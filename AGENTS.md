# Agent guidance — php-io-extensions/epoll

1. **Read [`.okf/index.md`](.okf/index.md) first** before changing the API, the C, or packaging. Open only the concepts the change touches.
2. **epoll(7), 1:1.** One PHP function = one syscall, named as `<sys/epoll.h>` names it. No helpers that aren't a syscall, no event loop, no wrapper class, no throwing on a failed call: failure is C's `-1` (or `false` from the wait family) with errno kept for `epoll_errno()`. See [`.okf/architecture/module.md`](.okf/architecture/module.md).
3. **Linux only.** eventfd, timerfd, signalfd are candidates for this extension or a sibling; kqueue and POSIX calls are not (ext-kqueue, ext-posi).
4. **Plain C on the Zend API.** No Zephir, no FFI. Sources: `epoll.c`, `php_epoll.h`.
5. **The stub is the declaration.** Edit `epoll.stub.php`, regenerate `epoll_arginfo.h` with gen_stub, commit both. Never hand-edit arginfo. See [`.okf/runbooks/build.md`](.okf/runbooks/build.md).
6. **Constants come from the header** via `@cvalue`, never a literal. `EPOLLET` goes through `PHP_EPOLLET` so it stays positive. See [`.okf/api/constants.md`](.okf/api/constants.md).
7. **Arguments are checked, never truncated.** Ints through `epoll_long_to_int()`; `$events` against `uint32`; out of range → `ValueError` naming the argument. `$maxevents` is the one exception: it reaches the kernel so the kernel's `EINVAL` is the answer. See [`.okf/api/functions.md`](.okf/api/functions.md).
8. **Never link ext/sockets.** `Socket` is resolved by class name at call time; the dependency stays `ZEND_MOD_OPTIONAL`.
9. **NTS and ZTS.** State lives only in module globals. Every change builds and passes Pest on Linux (the Pi via `fnk`, or CI); Mac cannot run the suite.
10. **Tests are Pest v4 and hardware-free**: socket pairs, `/proc`, child signals. Expected values come from the constants.
11. **Durable facts go in `.okf`.** Update the matching concept, bump its `generated.at`, append `.okf/log.md`. The bundle documents the package, never a session.
12. **Version**: `PHP_EPOLL_VERSION` moves only past a published tag; tags drive Packagist.
