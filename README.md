# php-io-extensions/epoll

Linux epoll(7) as global PHP functions. Hand-written C, one PHP function per libc call, constants from `<sys/epoll.h>`. Linux only.

## Install

```sh
pie install php-io-extensions/epoll
```

or from a clone (Debian Trixie, Ubuntu 24.04+, Raspberry Pi OS):

```sh
./install-debian-trixie.sh
```

The installer builds against the `php` on `PATH` and its matching `php-config` and `phpize`. It installs `epoll.so`, enables it only in that PHP's conf.d (plus its FPM and Apache SAPIs on Debian-packaged PHP) and removes the build artifacts afterwards. The build log stays in `build.log`. Prerequisites: `apt install php8.4-dev build-essential`.

`epoll_arginfo.h` is generated from `epoll.stub.php`. The installer regenerates it when the stub changes (gen_stub downloads PHP-Parser for that, so it needs network access; an unchanged stub is a no-op). For a manual build, run `php build/gen_stub.php epoll.stub.php` after `phpize`.

## Functions

| PHP | C |
|-----|---|
| `epoll_create(int $size): int` | `epoll_create(size)` |
| `epoll_create1(int $flags): int` | `epoll_create1(flags)` |
| `epoll_ctl(int $epfd, int $op, Socket\|resource\|int $fd, ?int $events = null, ?int $data = null): int` | `epoll_ctl(epfd, op, fd, &event)` |
| `epoll_wait(int $epfd, int $maxevents, int $timeout): array\|false` | `epoll_wait(epfd, events, maxevents, timeout)` |
| `epoll_pwait(int $epfd, int $maxevents, int $timeout, ?array $sigmask): array\|false` | `epoll_pwait(..., sigmask)` |
| `epoll_pwait2(int $epfd, int $maxevents, ?int $timeout_ns, ?array $sigmask): array\|false` | `epoll_pwait2(..., timeout, sigmask)` |
| `epoll_errno(): int` | `errno` after the last failed call |

Return values match C. The int-returning calls return the fd or `0` on success and `-1` on failure. The wait calls return `false` on failure. On success they return one `['events' => int, 'data' => int]` per ready fd, capped at `$maxevents`. After a failure, `epoll_errno()` holds the errno for that call, for example `EINTR` when a signal interrupts a wait. Like C `errno`, a successful call does not reset it.

`struct epoll_event` is flattened into `$events` and `$data`:

- `$events` is the `uint32_t` event mask. `null` passes a NULL event pointer, which is how C callers write `EPOLL_CTL_DEL`.
- `$data` fills `epoll_data.u64` and comes back unchanged. When omitted it defaults to the fd number, the same as setting `data.fd`.

`$fd` accepts:

- an int fd;
- a stream resource. The ext watches its underlying descriptor. Bytes a stream has already read into its PHP-side buffer are no longer in the kernel, so epoll can't see them. Call `stream_set_read_buffer($stream, 0)` on streams you watch.
- a `Socket` object from ext/sockets.

`$sigmask` is a list of signal numbers that stay blocked for the duration of the wait. `null` passes a NULL mask. `$timeout_ns` is nanoseconds, and `null` blocks with no timeout. `epoll_pwait2` uses the libc wrapper when present (glibc ≥ 2.35) and `syscall(SYS_epoll_pwait2)` otherwise. On kernels before 5.11 it fails with `ENOSYS`. `php --ri epoll` shows which path was built.

Close an epoll fd with `Posi\System::close` (php-io-extensions/posi).

## Constants

`EPOLLIN`, `EPOLLPRI`, `EPOLLOUT`, `EPOLLRDNORM`, `EPOLLRDBAND`, `EPOLLWRNORM`, `EPOLLWRBAND`, `EPOLLMSG`, `EPOLLERR`, `EPOLLHUP`, `EPOLLRDHUP`, `EPOLLEXCLUSIVE`, `EPOLLWAKEUP`, `EPOLLONESHOT`, `EPOLLET`, `EPOLL_CTL_ADD`, `EPOLL_CTL_DEL`, `EPOLL_CTL_MOD`, `EPOLL_CLOEXEC`

All values come from the headers of the build target. `EPOLL_CLOEXEC` equals `O_CLOEXEC`, which differs between architectures.

## Example

```php
[$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
stream_set_read_buffer($a, 0);

$epfd = epoll_create1(EPOLL_CLOEXEC);
epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN | EPOLLET, 42);

fwrite($b, 'ping');

foreach (epoll_wait($epfd, 16, 1000) as ['events' => $events, 'data' => $data]) {
    // $events === EPOLLIN, $data === 42
}
```

## Tests

Pest v4. Run on Linux against the built module:

```sh
composer install
php -d extension=$PWD/modules/epoll.so vendor/bin/pest
```
