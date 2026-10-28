# Log

## 2026-10-28

* **Version**: 0.11.0 in `PHP_EPOLL_VERSION` (`php_epoll.h`); `branch-alias` `dev-main` is `0.11.x-dev`. Promoted with the Venusian 0.11 ecosystem. No API or behaviour change; Linux-only, not built on macOS.

## 2026-10-11

* **0.10.6**: define `HAVE_SOCKETS` before including `ext/sockets/php_sockets.h` when the header exists. Debian-family PHP packages build ext/sockets shared and leave the macro undefined, which made the header empty and 0.10.5 fail to compile under PIE (`unknown type name 'php_socket'`, seen on an Ubuntu/Forge box). See [module](/architecture/module.md).
* **Creation**: Bundle created for ext-epoll 0.10.5 from the repository history (f7cd1e5 through 3583976): [module](/architecture/module.md), [functions](/api/functions.md), [constants](/api/constants.md), [build](/runbooks/build.md).
