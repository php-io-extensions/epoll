# Security Policy

## Supported versions

ext-epoll is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on the
[php-io-extensions/epoll](https://github.com/php-io-extensions/epoll) repository's **Security** tab.
If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, your platform and PHP build (NTS or ZTS), and steps
to reproduce. Reports are read and weighed for the release line in development; before 1.0 there
is no response-time commitment.

## Security model

ext-epoll binds `epoll(7)` one to one. Each function performs the syscall with the privileges of the
PHP process, the same as C code linked into that process. The syscalls only observe readiness and
never read, write or close a descriptor, so the extension cannot leak data from an fd it is handed;
it can only tell the caller that the fd is ready.

- **Descriptors.** `epoll_ctl()` accepts any integer as `$fd` and passes it to the kernel. Registering
  a descriptor the script did not open is allowed by the kernel wherever the process holds it; the
  extension adds no check beyond the C `int` range. Streams and `Socket` objects are resolved to
  their descriptor through the engine, and a closed one is rejected with `TypeError` or `ValueError`
  before the syscall.
- **Memory.** The only allocation is the result buffer of the wait family, sized from `$maxevents`
  through `safe_emalloc()` and freed before the call returns. A `$maxevents` outside `1..INT_MAX /
  sizeof(struct epoll_event)` is never used to size a buffer; it reaches the kernel with a one-slot
  stack buffer and the kernel answers `EINVAL`.
- **Signals.** `epoll_pwait()` and `epoll_pwait2()` install the given signal set only for the
  duration of the wait; the kernel restores the caller's mask. Signal numbers outside `1..NSIG-1`
  throw `ValueError`.
- **Arguments.** Every `int` argument is checked against the C type it lands in, and `$events`
  against `uint32`. Out-of-range values throw `ValueError`; nothing is truncated into a different
  value.

A report is in scope when ext-epoll itself reads or writes memory it should not: a buffer size that
can be made to disagree with `$maxevents`, a crash reachable from well-formed PHP arguments, a
descriptor resolved from a stream or `Socket` after it was freed. Effects that follow from a valid
syscall the script asked for, such as watching a descriptor it was handed, are the documented
behaviour.
