---
okf_version: "0.2"
---

# ext-epoll

* [Module](architecture/module.md) - Scope, file layout, module globals, optional sockets dependency, errno and argument conventions.

# API

* [Functions](api/functions.md) - epoll_create/create1, epoll_ctl fd forms and the data word, epoll_wait/pwait/pwait2 result shape, timeouts, sigmask, maxevents.
* [Constants](api/constants.md) - The <sys/epoll.h> set, EPOLLET pinned unsigned, EPOLL_CLOEXEC.

# Runbooks

* [Build, install, test](runbooks/build.md) - gen_stub after a stub edit, scratch builds, the Debian installer, Pest, CI matrix, PIE.
