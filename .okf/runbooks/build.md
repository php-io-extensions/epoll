---
type: Runbook
title: Build, install, test
description: gen_stub after a stub edit, scratch builds, the Debian installer, Pest, CI matrix, PIE.
resource: install-debian-trixie.sh
tags: [epoll, build, linux, pest, pie, ci]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-11T19:56:56Z }
sources:
  - id: debian
    resource: install-debian-trixie.sh
    title: install-debian-trixie.sh
  - id: ci
    resource: .github/workflows/ci.yml
    title: ci.yml
  - id: composer
    resource: composer.json
    title: composer.json
  - id: pie-run
    resource: "scope: pie install on the Pi, 2026-10-11"
    title: PIE install log, Raspberry Pi, PHP 8.4.20 ZTS
---

# Needs

Linux. php-dev (phpize, php-config) for the target PHP, a C compiler, `sys/epoll.h` (libc6-dev). No library. ext/sockets optional at build and at runtime.

# Stub → arginfo

Edit `epoll.stub.php`, never `epoll_arginfo.h`. Regenerate with the target PHP's gen_stub:

```bash
php "$(php-config --prefix)/lib/php/build/gen_stub.php" epoll.stub.php
```

Commit stub and arginfo together; the arginfo header carries the stub hash. The Debian installer runs gen_stub itself after phpize, so a stale arginfo in a clone is regenerated before configure.[^debian]

# Scratch build

phpize builds in the repo root; `.gitignore` covers every artifact. Clean build:

```bash
phpize && ./configure --enable-epoll && make -j"$(nproc)"
php -n -d extension="$PWD/modules/epoll.so" --ri epoll
```

`--enable-epoll` is required by hand: `config.m4` defaults the flag to `no`.

# Test

Pest v4, hardware-free: `stream_socket_pair`, `socket_create_pair`, `/proc/self/fdinfo` for the CLOEXEC check, `pcntl`/`posix` for the sigmask test. Expected values are the constants, never numbers, except the constants test itself.

```bash
composer install
php -d extension="$PWD/modules/epoll.so" -d memory_limit=128M vendor/bin/pest
```

Mac has no epoll: the suite only runs on Linux (the Pi via `fnk`, or CI).

# CI

`ubuntu-24.04` × PHP `8.4`, `8.5` × `nts`, `zts` with `shivammathur/setup-php`. Steps: phpize/configure/make, load check with `php -n -d extension=…`, `--ri epoll`, then `composer install` and Pest.[^ci]

# Installer

`install-debian-trixie.sh`: Debian Trixie, Ubuntu 24.04+, Raspberry Pi OS Bookworm/Trixie. Resolves `phpize`/`php-config` matching the `php` on PATH (versioned names first), verifies `php-config --vernum` equals `PHP_VERSION_ID`, builds in place, copies `epoll.so` into `extension_dir`, writes `30-epoll.ini` into the CLI scan dir and, on Debian packaging, the sibling `fpm/` and `apache2/` conf.d, verifies `function_exists('epoll_create1')`, reloads FPM if active, removes every build artifact. Fails early with the apt package to install for each missing tool.[^debian]

# PIE

`composer.json` `type: php-ext`, `extension-name: epoll`, `build-path: "."`, priority 30, NTS + ZTS, `os-families: [linux]`, configure option `--enable-epoll`. No `version` key: git tags drive Packagist. `.gitattributes` export-ignores tests, `phpunit.xml`, `.okf`, `AGENTS.md`, `CLAUDE.md`.[^composer]

Proven on the Pi (PHP 8.4.20 ZTS, PIE 1.4.2): `sudo pie install php-io-extensions/epoll` resolved `v0.10.0`, ran phpize and configure with only `--with-php-config`, built, installed into `extension_dir`, reported enabled and loaded. PIE did not pass `--enable-epoll` and the module still built.[^pie-run]

# Version

`PHP_EPOLL_VERSION` in `php_epoll.h` is the only version field in the repo; bump it only past a published tag, and tag to publish.

[^debian]: install-debian-trixie.sh
[^ci]: .github/workflows/ci.yml
[^composer]: composer.json
[^pie-run]: PIE install log, Raspberry Pi, 2026-10-11
