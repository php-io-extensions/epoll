<div align="center" id="header">

<h1>ext-epoll</h1>

<h3>Linux <code>epoll(7)</code> bindings for PHP 8.4+</h3>


[![CI](https://img.shields.io/github/actions/workflow/status/php-io-extensions/epoll/ci.yml?style=flat-square&label=CI)](https://github.com/php-io-extensions/epoll/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/php-io-extensions/epoll?style=flat-square)](https://packagist.org/packages/php-io-extensions/epoll)
[![PHP](https://img.shields.io/packagist/dependency-v/php-io-extensions/epoll/php?style=flat-square&label=PHP)](https://packagist.org/packages/php-io-extensions/epoll)
[![License](https://img.shields.io/github/license/php-io-extensions/epoll?style=flat-square)](LICENSE)
[![Platform](https://img.shields.io/badge/platform-linux_only-blue?style=flat-square&logo=linux&logoColor=white)](https://man7.org/linux/man-pages/man7/epoll.7.html)
[![OKF](https://img.shields.io/badge/OKF-0.2-6f42c1?style=flat-square&label=knowledge%20bundle)](.okf/index.md)
[![PIE](https://img.shields.io/badge/PIE-installable-4f5b93?style=flat-square&logo=php&logoColor=white)](https://github.com/php/pie)
</div>

A program that talks to many connections at once needs to answer one question over and over: "<b>which of my file descriptors can I read or write right now without blocking?</b>" PHP's built-in answer is `stream_select()`. It works, and it's the right answer while you only watch a handful of streams. Every call you hand it the full list, the kernel copies the list in, scans every one, copies the result out. The more fds you watch, the more expensive that becomes.

With 10 connections nothing is taxed. With 10,000, each wakeup scans 10,000 fds to find the 3 that have data. There's also a hard cap, `FD_SETSIZE`, usually 1024, baked into the C library that PHP inherits. So out of the box, resource waiting doesn't scale.

<b>epoll</b> solves this. You register each fd once with the Linux kernel into an "epoll instance", which also happens to be an fd. Then you wait on it. This is the cool part: the kernel hands back only the fds with activity, each one labelled with the tag you registered it under. PHP never sorts through what's ready; it iterates through the ready ones, reads them, and goes again.

<b>The only problem?</b> PHP never exposes epoll to userland. PHP-FPM uses it internally, and PECL has `ev`, `event` and `uv`, which pick epoll on Linux and hide it behind a portable event loop. Every one of them gives you a loop. This extension exposes epoll directly giving you access to the system calls, one to one, no opinionated event loop: allowing you the freedom to build the loop you need, or watch resources those loop implementations never thought of.

## Basic usage

Most ways you will interface with epoll is in two parts -

Start an epoll instance with `epoll_create1()`, connect to resources and hand their fds to epoll with `epoll_ctl()`.

```php

// Create an epoll instance with the kernel and get back its fd.
$epoll_fd = epoll_create1(EPOLL_CLOEXEC);

$resources = [];
$resource_tags = ['resourceA', 'resourceB', 'resourceC'];

foreach ($resource_tags as $tag => $name) 
{  
    // Open another process on the device
    [$process, $pipes] = $this->startNewProcess();
    // Save it to your list of $resources
    $resources[$tag] = ['name' => $name, 'process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1]];
    
    epoll_ctl($epoll_fd, EPOLL_CTL_ADD, $pipes[1], EPOLLIN, $tag);
    $this->writeToResource($pipes[0])
}
```

Ping epoll for activity with `epoll_wait()`and do work. Use epoll to stop listening to resources with `epoll_ctl($epoll_fd, EPOLL_CTL_DEL, $resource)` before closing the connection to the resource process.
```php
while (!empty($servers)) 
{
    $activity = epoll_wait(edfd: $epoll_fd, maxevents:16, timeout: -1);
    
    foreach ($activity as ['events' => $events, 'data' => $tag]) 
    {
        $resource = $resources[$tag];
        
        // Read the data out of the resource
        $line = fgets($server['stdout']);
        $valid = ($line === false || $events & EPOLLHUP);
        
        if ($valid) {
            $message = json_decode($line, true);
            echo "{$resource['name']}: ", $message['method'] ?? "reply to #{$message['id']}", "\n";
        }
        else
        {
            // There was nothing to read, so use epoll to close the connection
            epoll_ctl($epfd, EPOLL_CTL_DEL, $server['stdout']);
            proc_close($resource['process']);
            unset($resources[$tag]);
            echo "{$resource['name']}: gone\n";
        }
    }
```

## Requirements

| | |
|---|---|
| OS | Linux. `epoll(7)` is a Linux syscall family; `configure` stops on any other kernel. |
| Kernel | Any that has epoll. `epoll_pwait2()` needs 5.11 or newer; the other six functions don't care. |
| PHP | 8.4 or newer, NTS or ZTS, from `php-src` or your distribution. |
| Libraries | None beyond libc. |
| Optional | ext/sockets, if you want to register `Socket` objects. Detected at build, resolved at runtime; the module loads without it. |

To build it you need a C compiler, the kernel headers, and the development files of the PHP you intend to load it into:

```bash
sudo apt install build-essential libc6-dev php8.4-dev
```

## Installation

### With PHP PIE (recommended) 

[PIE](https://github.com/php/pie) is the PHP Foundation's installer for extensions. It downloads the tagged source from Packagist, builds it against the PHP on your `PATH`, installs the module and enables it:

```bash
sudo pie install php-io-extensions/epoll
```

### Manually (install script)

The repository ships `install-debian-trixie.sh` for Debian Trixie and compatible systems (Ubuntu 24.04+, Raspberry Pi OS Bookworm and Trixie). It finds the `phpize` and `php-config` that match your `php`, builds, installs the module, writes `30-epoll.ini` into every `conf.d` of that PHP (CLI, FPM, Apache) and leaves the checkout clean:

```bash
git clone https://github.com/php-io-extensions/epoll.git
cd epoll
./install-debian-trixie.sh
```

### Manually (phpize)

```bash
git clone https://github.com/php-io-extensions/epoll.git
cd epoll
phpize
./configure --enable-epoll
make -j"$(nproc)"
sudo make install
```

Then enable it: add `extension=epoll.so` to your `php.ini`, or drop that line into a file in the scan directory that `php --ini` reports.

### Verify

```console
$ php --ri epoll

epoll

epoll support => enabled
Version => 0.11.x
epoll_pwait2 => libc
```

`epoll_pwait2` reads `libc` when your C library provides the wrapper, `syscall` when the extension calls the kernel directly, or `unavailable (ENOSYS)` on a kernel older than 5.11. The other six functions work on any of the three.

## API

| Method                         | Description |
|--------------------------------|-------------|
| `epoll_create(int $size): int` | Creates an epoll instance and returns its fd, or `-1`. `$size` must be positive; the kernel has ignored its value since Linux 2.6.8. |
| `epoll_create1(int $flags): int` | Same, with `$flags` of `0` or `EPOLL_CLOEXEC`. Prefer this one. |
| `epoll_ctl(int $epfd, int $op, Socket\|resource\|int $fd, ?int $events = null, ?int $data = null): int` | Adds, modifies or removes `$fd` in the instance (`EPOLL_CTL_ADD`, `EPOLL_CTL_MOD`, `EPOLL_CTL_DEL`). `$events` is a mask of `EPOLL*` constants; `null` sends no event struct, which is what `EPOLL_CTL_DEL` wants. `$data` is your 64-bit tag, handed back untouched by the wait functions; `null` tags the registration with its own fd number. Returns `0` or `-1`. |
| `epoll_wait(int $epfd, int $maxevents, int $timeout): array\|false` | Blocks up to `$timeout` milliseconds (`-1` forever, `0` poll) and returns up to `$maxevents` entries of `['events' => int, 'data' => int]`, empty on timeout, `false` on error. The fd is not in the result; your tag is. |
| `epoll_pwait(int $epfd, int $maxevents, int $timeout, ?array $sigmask): array\|false` | As `epoll_wait()`, with `$sigmask` (a list of signal numbers) installed as the blocked set only for the duration of the wait. `null` leaves the mask alone; `[]` unblocks everything, so a pending signal interrupts with `EINTR`. |
| `epoll_pwait2(int $epfd, int $maxevents, ?int $timeout_ns, ?array $sigmask): array\|false` | As `epoll_pwait()`, with the timeout in nanoseconds; `null` blocks forever. Needs Linux 5.11. `php --ri epoll` shows whether your build reaches it through libc, a raw syscall, or not at all (`ENOSYS`). |
| `epoll_errno(): int` | The `errno` saved by the last call that failed. Compare it against ext/sockets' `SOCKET_E*` constants; this extension defines none of its own. Bad PHP arguments never reach here: they throw `ValueError` or `TypeError` before the syscall. |

| Constant | Description |
|---|---|
| `EPOLLIN`, `EPOLLOUT`, `EPOLLPRI` | Readable, writable, urgent data. The events you ask for. |
| `EPOLLRDHUP` | Peer closed its write side. Ask for it to learn about a hang-up before the read returns empty. |
| `EPOLLERR`, `EPOLLHUP` | Error and hang-up. Always reported, never need asking for. |
| `EPOLLRDNORM`, `EPOLLRDBAND`, `EPOLLWRNORM`, `EPOLLWRBAND`, `EPOLLMSG` | The `poll(2)` aliases the header carries. Bound because the header has them. |
| `EPOLLET` | Edge-triggered: report once per new arrival instead of on every wait while data remains. |
| `EPOLLONESHOT` | Report once, then disarm until `EPOLL_CTL_MOD` re-arms the registration. |
| `EPOLLEXCLUSIVE`, `EPOLLWAKEUP` | Wake one of several waiters on the same fd; hold a wakeup lock while the event is pending. |
| `EPOLL_CTL_ADD`, `EPOLL_CTL_MOD`, `EPOLL_CTL_DEL` | The `$op` of `epoll_ctl()`. |
| `EPOLL_CLOEXEC` | The only flag `epoll_create1()` takes: close the instance across `exec`. |

Every value comes from `<sys/epoll.h>` at build time. `EPOLLET` is the header's `1 << 31`, kept positive (`2147483648`) so it passes the `uint32` range check on `$events`.

## Extensions you might use with this one

* [ext-posi](https://github.com/php-io-extensions/posi): POSIX calls as PHP functions. Gives you `posix_open()`, `posix_read()`, `posix_close()` and `ioctl()` for the raw fds epoll hands back, including the epoll instance itself, which core PHP cannot close.
* [ext/sockets](https://www.php.net/manual/en/book.sockets.php): ships with PHP. Its `Socket` objects register directly in `epoll_ctl()`, and its `SOCKET_E*` constants name the errno values.

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Agentic Documentation

This repository carries an [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) bundle at [`.okf/`](.okf/): Markdown with YAML frontmatter, readable by you and loadable by a coding agent. It records what the README doesn't: how the module is laid out, why each argument check exists, how the stub becomes `epoll_arginfo.h`, which `epoll_pwait2` path a build takes, and a log of every change that altered behaviour.

Start at [`.okf/index.md`](.okf/index.md). [`AGENTS.md`](AGENTS.md) is the short rule sheet an agent reads before touching the C, the stub, or packaging; each rule points into the bundle for the reason behind it.

Point your agent at the repo and it has the same context the maintainer has. Point yourself at it when the README's answer is "it does X" and you want "because".

## Resources

* [`epoll(7)`](https://man7.org/linux/man-pages/man7/epoll.7.html), [`epoll_ctl(2)`](https://man7.org/linux/man-pages/man2/epoll_ctl.2.html), [`epoll_wait(2)`](https://man7.org/linux/man-pages/man2/epoll_wait.2.html): the kernel interface this extension binds. The man pages are the manual; this README only adds what PHP changes.
* [PIE](https://github.com/php/pie): the PHP Foundation's extension installer.
* [`gen_stub.php`](https://github.com/php/php-src/blob/master/build/gen_stub.php): the php-src stub format. [`epoll.stub.php`](epoll.stub.php) is the API declaration; `epoll_arginfo.h` is generated from it.
* [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md): the spec the [`.okf/`](.okf/) bundle follows.

## Running the test suite

The suite is Pest v4 and needs no hardware: socket pairs, `/proc/self/fdinfo`, and a child signal. It runs on Linux only, since the module does. Build the module in the clone, then point `php` at it:

```bash
git clone https://github.com/php-io-extensions/epoll.git
cd epoll
phpize && ./configure --enable-epoll && make -j"$(nproc)"
composer install
php -d extension="$PWD/modules/epoll.so" vendor/bin/pest
```

```console
Tests:    39 passed (186 assertions)
Duration: 0.15s
```

Loading the freshly built `.so` by path means the suite tests the clone, not whatever `epoll.so` is already installed. If both end up loaded you'll see `Module "epoll" is already loaded`; add `-n` to skip your `php.ini`.
## Contributing

Open an issue first for anything beyond a typo. Pull requests change `epoll.stub.php` and `epoll.c` together, regenerate `epoll_arginfo.h` with gen_stub, and add a Pest test for the behaviour. CI must be green on every cell of the matrix. Read [`AGENTS.md`](AGENTS.md) for the rules the code follows.

## Security

epoll only reports readiness; it never reads, writes or closes a descriptor. See [SECURITY.md](SECURITY.md) for the model and how to report a vulnerability.

## License

MIT. See [LICENSE](LICENSE).
