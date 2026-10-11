---
type: API
title: Constants
description: The <sys/epoll.h> set, EPOLLET pinned unsigned, EPOLL_CLOEXEC.
resource: epoll.stub.php
tags: [epoll, constants]
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

# Rule

Every constant is `@cvalue` from `<sys/epoll.h>` at build time, never a literal. No `#ifdef` guards: every name here exists on every Linux the extension builds on, so the stub declares them unconditionally.[^stub]

# Set

| Group | Names |
|---|---|
| Events | `EPOLLIN EPOLLPRI EPOLLOUT EPOLLRDNORM EPOLLRDBAND EPOLLWRNORM EPOLLWRBAND EPOLLMSG EPOLLERR EPOLLHUP EPOLLRDHUP` |
| Flags | `EPOLLEXCLUSIVE EPOLLWAKEUP EPOLLONESHOT EPOLLET` |
| Ops | `EPOLL_CTL_ADD EPOLL_CTL_DEL EPOLL_CTL_MOD` |
| Create | `EPOLL_CLOEXEC` |

The test suite pins each value to the header's number so a drift in the stub shows up as a failure.[^tests]

# EPOLLET

The header defines `EPOLLET` as `1u << 31`. As a signed C `int` that is negative; as PHP's `zend_long` on 64-bit it must be `2147483648`. The stub's `@cvalue` points at `PHP_EPOLLET`, defined in `epoll.c` as `(zend_long) (uint32_t) EPOLLET`, so the constant is positive and the `$events` range check (`0..UINT32_MAX`) accepts it. On a 32-bit `zend_long` every value is a valid `uint32` bit pattern and the check is skipped.[^epoll-c]

# Errno

No `E*` constants. Compare `epoll_errno()` against ext/sockets' `SOCKET_E*`; ext-posi defines none either.

[^stub]: epoll.stub.php
[^epoll-c]: epoll.c
[^tests]: tests/EpollTest.php
