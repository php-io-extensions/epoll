---
type: API
title: Functions
description: epoll_create/create1, epoll_ctl fd forms and the data word, epoll_wait/pwait/pwait2 result shape, timeouts, sigmask, maxevents.
resource: epoll.stub.php
tags: [epoll, api, functions]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-11T19:56:56Z }
sources:
  - id: stub
    resource: epoll.stub.php
    title: epoll.stub.php
  - id: epoll-c
    resource: epoll.c
    title: epoll.c
  - id: tests
    resource: tests/EpollTest.php
    title: tests/EpollTest.php
---

# Create

```php
function epoll_create(int $size): int {}
function epoll_create1(int $flags): int {}
```

Both return the epoll fd or `-1`. `$size` must be positive (kernel `EINVAL` otherwise; the value is ignored since Linux 2.6.8). `$flags` is `0` or `EPOLL_CLOEXEC`; anything else is `EINVAL`. The returned fd is a raw `int`. Core PHP has no call to close it; use `posix_close()` from ext-posi or let process exit reclaim it. It may itself be registered in another epoll instance.[^epoll-c][^tests]

# Register

```php
/** @param resource|Socket|int $fd */
function epoll_ctl(int $epfd, int $op, mixed $fd, ?int $events = null, ?int $data = null): int {}
```

`$op` is `EPOLL_CTL_ADD`, `EPOLL_CTL_MOD` or `EPOLL_CTL_DEL`. `$fd` forms: a raw `int`, any stream resource backed by a descriptor, or an ext/sockets `Socket`. See [module](/architecture/module.md) for how each is resolved.

The data word: `$data` is stored in `epoll_data.u64` and returned verbatim by the wait family. All 64 bits survive (`PHP_INT_MIN` round-trips). When `$data` is `null` it defaults to the resolved fd number, so the simple case needs no tag.[^epoll-c][^tests]

`$events` `null` passes a `NULL` `struct epoll_event*` to the kernel, exactly as C callers do for `EPOLL_CTL_DEL`. Passing `null` with `ADD` or `MOD` reaches the kernel too and answers `EFAULT`. Returns `0` or `-1`.[^epoll-c]

Kernel errors seen in the suite: `EEXIST` (ADD twice), `ENOENT` (DEL/MOD an unregistered fd), `EINVAL` (registering the epfd in itself), `EBADF`.[^tests]

# Wait

```php
/** @return list<array{events: int, data: int}>|false */
function epoll_wait(int $epfd, int $maxevents, int $timeout): array|false {}
function epoll_pwait(int $epfd, int $maxevents, int $timeout, ?array $sigmask): array|false {}
function epoll_pwait2(int $epfd, int $maxevents, ?int $timeout_ns, ?array $sigmask): array|false {}
```

Result: a list, one `['events' => int, 'data' => int]` per ready registration, at most `$maxevents` long, empty on timeout, `false` on error (`errno` via `epoll_errno()`). The fd is not in the result; the data word is.[^epoll-c]

`$maxevents`: 1 to `INT_MAX / sizeof(struct epoll_event)` is allocated with `safe_emalloc` and freed after the call. Anything else is sent to the kernel with a one-slot stack buffer and `0` or `INT_MAX` as the count, so the kernel's own `EINVAL` is what the caller sees; no PHP-side exception.[^epoll-c][^tests]

Timeouts: `epoll_wait`/`epoll_pwait` take milliseconds, `-1` blocks, `0` polls. `epoll_pwait2` takes nanoseconds as an `int`, `null` blocks; a negative value becomes a negative `timespec`, which the kernel rejects with `EINVAL`.[^epoll-c][^tests]

`$sigmask`: `null` means no mask change (behaves as `epoll_wait`). An array of signal numbers becomes a `sigset_t` installed for the duration of the wait only; the previous mask is restored by the kernel. Each entry must be an `int` in `1..NSIG-1`. An empty array unblocks everything for the wait, which lets a pending blocked signal interrupt it with `EINTR`.[^epoll-c][^tests]

`epoll_pwait2` availability is decided at configure: libc wrapper (`HAVE_EPOLL_PWAIT2`), else raw `syscall(SYS_epoll_pwait2, …)` with a kernel-layout timespec and `_NSIG / 8` as the sigset size, else `ENOSYS` at call time. `php --ri epoll` reports which. The syscall exists since Linux 5.11.[^epoll-c]

# Errors

```php
function epoll_errno(): int {}
```

Last `errno` saved by a failed call in this process (thread under ZTS). Compare against ext/sockets' `SOCKET_E*`; this extension defines no errno constants of its own, and neither does ext-posi.[^epoll-c]

# Triggering

Level-triggered by default: a registration is reported on every wait while the condition holds. `EPOLLET` reports once per edge; `EPOLLONESHOT` disarms after one report until `EPOLL_CTL_MOD` re-arms it. Both proven in the suite with a `stream_socket_pair`.[^tests]

[^stub]: epoll.stub.php
[^epoll-c]: epoll.c
[^tests]: tests/EpollTest.php
