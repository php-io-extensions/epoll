<?php

declare(strict_types=1);

/** @return array{0: resource, 1: resource} */
function streamPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_read_buffer($pair[0], 0);

    return $pair;
}

function epollFd(): int
{
    $epfd = epoll_create1(EPOLL_CLOEXEC);
    expect($epfd)->toBeGreaterThanOrEqual(0);

    return $epfd;
}

describe('constants', function (): void {
    it('mirrors <sys/epoll.h>', function (): void {
        expect(EPOLLIN)->toBe(0x001)
            ->and(EPOLLPRI)->toBe(0x002)
            ->and(EPOLLOUT)->toBe(0x004)
            ->and(EPOLLRDNORM)->toBe(0x040)
            ->and(EPOLLRDBAND)->toBe(0x080)
            ->and(EPOLLWRNORM)->toBe(0x100)
            ->and(EPOLLWRBAND)->toBe(0x200)
            ->and(EPOLLMSG)->toBe(0x400)
            ->and(EPOLLERR)->toBe(0x008)
            ->and(EPOLLHUP)->toBe(0x010)
            ->and(EPOLLRDHUP)->toBe(0x2000)
            ->and(EPOLLEXCLUSIVE)->toBe(1 << 28)
            ->and(EPOLLWAKEUP)->toBe(1 << 29)
            ->and(EPOLLONESHOT)->toBe(1 << 30)
            ->and(EPOLLET)->toBe(1 << 31)
            ->and(EPOLL_CTL_ADD)->toBe(1)
            ->and(EPOLL_CTL_DEL)->toBe(2)
            ->and(EPOLL_CTL_MOD)->toBe(3);
    });
});

describe('epoll_create / epoll_create1', function (): void {
    it('sets close-on-exec from EPOLL_CLOEXEC', function (): void {
        $epfd = epollFd();
        preg_match('/^flags:\s+([0-7]+)$/m', file_get_contents("/proc/self/fdinfo/{$epfd}"), $m);

        expect(octdec($m[1]) & EPOLL_CLOEXEC)->toBe(EPOLL_CLOEXEC);
    });

    it('leaves close-on-exec off with flags 0', function (): void {
        $epfd = epoll_create1(0);
        preg_match('/^flags:\s+([0-7]+)$/m', file_get_contents("/proc/self/fdinfo/{$epfd}"), $m);

        expect(octdec($m[1]) & EPOLL_CLOEXEC)->toBe(0);
    });

    it('creates with a positive size hint', function (): void {
        expect(epoll_create(1))->toBeGreaterThanOrEqual(0);
    });

    it('returns -1 and EINVAL for a zero size hint', function (): void {
        expect(epoll_create(0))->toBe(-1)
            ->and(epoll_errno())->toBe(SOCKET_EINVAL);
    });

    it('returns -1 and EINVAL for unknown flags', function (): void {
        expect(epoll_create1(1))->toBe(-1)
            ->and(epoll_errno())->toBe(SOCKET_EINVAL);
    });
});

describe('epoll_ctl + epoll_wait', function (): void {
    it('reports a readable stream with its data word', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();

        expect(epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN, 42))->toBe(0)
            ->and(epoll_wait($epfd, 8, 0))->toBe([]);

        fwrite($b, 'x');

        expect(epoll_wait($epfd, 8, 1000))->toBe([['events' => EPOLLIN, 'data' => 42]]);
    });

    it('reports a writable stream', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLOUT, 7);

        expect(epoll_wait($epfd, 8, 0))->toBe([['events' => EPOLLOUT, 'data' => 7]]);
    });

    it('keeps the full 64-bit data word', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLOUT, PHP_INT_MIN);

        expect(epoll_wait($epfd, 1, 0)[0]['data'])->toBe(PHP_INT_MIN);
    });

    it('defaults the data word to the fd number', function (): void {
        $outer = epollFd();
        $inner = epollFd();
        [$a, $b] = streamPair();

        epoll_ctl($inner, EPOLL_CTL_ADD, $a, EPOLLIN);
        expect(epoll_ctl($outer, EPOLL_CTL_ADD, $inner, EPOLLIN))->toBe(0);

        fwrite($b, 'x');

        expect(epoll_wait($outer, 8, 1000))->toBe([['events' => EPOLLIN, 'data' => $inner]]);
    });

    it('repeats level-triggered readiness until drained', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN, 1);
        fwrite($b, 'x');

        expect(epoll_wait($epfd, 8, 0))->toHaveCount(1)
            ->and(epoll_wait($epfd, 8, 0))->toHaveCount(1);

        fread($a, 1);

        expect(epoll_wait($epfd, 8, 0))->toBe([]);
    });

    it('reports edge-triggered readiness once per edge', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN | EPOLLET, 1);
        fwrite($b, 'x');

        expect(epoll_wait($epfd, 8, 0))->toHaveCount(1)
            ->and(epoll_wait($epfd, 8, 0))->toBe([]);

        fwrite($b, 'y');

        expect(epoll_wait($epfd, 8, 0))->toHaveCount(1);
    });

    it('disarms after EPOLLONESHOT until EPOLL_CTL_MOD re-arms', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN | EPOLLONESHOT, 1);
        fwrite($b, 'x');

        expect(epoll_wait($epfd, 8, 0))->toHaveCount(1)
            ->and(epoll_wait($epfd, 8, 0))->toBe([])
            ->and(epoll_ctl($epfd, EPOLL_CTL_MOD, $a, EPOLLIN | EPOLLONESHOT, 2))->toBe(0)
            ->and(epoll_wait($epfd, 8, 0))->toBe([['events' => EPOLLIN, 'data' => 2]]);
    });

    it('removes an fd with a null event pointer', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN, 1);

        expect(epoll_ctl($epfd, EPOLL_CTL_DEL, $a))->toBe(0);

        fwrite($b, 'x');

        expect(epoll_wait($epfd, 8, 0))->toBe([]);
    });

    it('caps results at maxevents', function (): void {
        $epfd = epollFd();
        foreach (range(1, 3) as $data) {
            $keep[$data] = streamPair();
            epoll_ctl($epfd, EPOLL_CTL_ADD, $keep[$data][0], EPOLLOUT, $data);
        }

        expect(epoll_wait($epfd, 2, 0))->toHaveCount(2)
            ->and(epoll_wait($epfd, 3, 0))->toHaveCount(3);
    });

    it('accepts a Socket object', function (): void {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $epfd = epollFd();

        expect(epoll_ctl($epfd, EPOLL_CTL_ADD, $pair[0], EPOLLIN, 9))->toBe(0);

        socket_write($pair[1], 'x');

        expect(epoll_wait($epfd, 8, 1000))->toBe([['events' => EPOLLIN, 'data' => 9]]);
    });

    it('surfaces kernel errors through epoll_errno', function (): void {
        [$a, $b] = streamPair();
        [$other, $otherPeer] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN);

        expect(epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN))->toBe(-1)
            ->and(epoll_errno())->toBe(SOCKET_EEXIST)
            ->and(epoll_ctl($epfd, EPOLL_CTL_DEL, $other))->toBe(-1)
            ->and(epoll_errno())->toBe(SOCKET_ENOENT)
            ->and(epoll_ctl($epfd, EPOLL_CTL_ADD, $epfd, EPOLLIN))->toBe(-1)
            ->and(epoll_errno())->toBe(SOCKET_EINVAL)
            ->and(epoll_ctl($epfd, EPOLL_CTL_MOD, $a, null))->toBe(-1)
            ->and(epoll_errno())->toBe(SOCKET_EFAULT)
            ->and(epoll_wait(-1, 8, 0))->toBeFalse()
            ->and(epoll_errno())->toBe(SOCKET_EBADF);
    });

    it('passes out-of-range maxevents to the kernel as EINVAL', function (int $maxevents): void {
        $epfd = epollFd();

        expect(epoll_wait($epfd, $maxevents, 0))->toBeFalse()
            ->and(epoll_errno())->toBe(SOCKET_EINVAL)
            ->and(epoll_pwait($epfd, $maxevents, 0, null))->toBeFalse()
            ->and(epoll_pwait2($epfd, $maxevents, 0, null))->toBeFalse();
    })->with([0, -1, PHP_INT_MAX, PHP_INT_MIN]);
});

describe('argument validation', function (): void {
    it('rejects a closed stream', function (): void {
        [$a, $b] = streamPair();
        fclose($a);

        epoll_ctl(epollFd(), EPOLL_CTL_ADD, $a, EPOLLIN);
    })->throws(TypeError::class, 'must be a valid stream resource');

    it('rejects a closed Socket', function (): void {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        socket_close($pair[0]);

        epoll_ctl(epollFd(), EPOLL_CTL_ADD, $pair[0], EPOLLIN);
    })->throws(ValueError::class, 'has already been closed');

    it('rejects non-fd values', function (mixed $fd): void {
        epoll_ctl(epollFd(), EPOLL_CTL_ADD, $fd, EPOLLIN);
    })->throws(TypeError::class, 'must be of type Socket|resource|int')
        ->with([[[]], ['3'], [new stdClass()], [null]]);

    it('rejects ints outside C int range', function (): void {
        epoll_ctl(PHP_INT_MAX, EPOLL_CTL_ADD, 0, EPOLLIN);
    })->throws(ValueError::class, 'must be between');

    it('rejects events outside uint32', function (int $events): void {
        [$a, $b] = streamPair();

        epoll_ctl(epollFd(), EPOLL_CTL_ADD, $a, $events);
    })->throws(ValueError::class, 'must be between 0 and 4294967295')->with([-1, 1 << 32]);

    it('rejects invalid signal numbers', function (array $sigmask): void {
        epoll_pwait(epollFd(), 1, 0, $sigmask);
    })->throws(ValueError::class, 'must contain only valid signal numbers')->with([[[0]], [[65]], [[-3]]]);

    it('rejects non-int signal numbers', function (): void {
        epoll_pwait2(epollFd(), 1, 0, ['10']);
    })->throws(TypeError::class, 'must contain only int signal numbers');
});

describe('epoll_pwait / epoll_pwait2', function (): void {
    it('behaves as epoll_wait with a null sigmask', function (): void {
        [$a, $b] = streamPair();
        $epfd = epollFd();
        epoll_ctl($epfd, EPOLL_CTL_ADD, $a, EPOLLIN, 5);
        fwrite($b, 'x');

        expect(epoll_pwait($epfd, 8, 1000, null))->toBe([['events' => EPOLLIN, 'data' => 5]])
            ->and(epoll_pwait2($epfd, 8, null, null))->toBe([['events' => EPOLLIN, 'data' => 5]]);
    });

    it('honours a nanosecond timeout', function (): void {
        $epfd = epollFd();
        $start = hrtime(true);

        expect(epoll_pwait2($epfd, 8, 20_000_000, null))->toBe([])
            ->and(hrtime(true) - $start)->toBeGreaterThanOrEqual(20_000_000);
    });

    it('returns EINVAL for a negative nanosecond timeout', function (): void {
        expect(epoll_pwait2(epollFd(), 8, -1, null))->toBeFalse()
            ->and(epoll_errno())->toBe(SOCKET_EINVAL);
    });

    it('applies the sigmask only for the duration of the wait', function (string $call): void {
        $epfd = epollFd();
        $wait = fn (int $ms, array $sigmask) => $call === 'epoll_pwait'
            ? epoll_pwait($epfd, 1, $ms, $sigmask)
            : epoll_pwait2($epfd, 1, $ms * 1_000_000, $sigmask);

        $delivered = 0;
        pcntl_async_signals(false);
        pcntl_signal(SIGUSR1, function () use (&$delivered): void {
            $delivered++;
        });
        pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $previous);

        try {
            posix_kill(getmypid(), SIGUSR1);

            // Mask keeps SIGUSR1 blocked: the wait times out, the signal stays pending.
            expect($wait(20, [SIGUSR1]))->toBe([]);

            // Empty mask unblocks it for the wait: delivery interrupts with EINTR.
            expect($wait(1000, []))->toBeFalse()
                ->and(epoll_errno())->toBe(SOCKET_EINTR);

            pcntl_signal_dispatch();
            expect($delivered)->toBe(1);

            // The caller's mask is restored after the wait.
            pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $current);
            expect($current)->toContain(SIGUSR1);
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $previous);
            pcntl_signal(SIGUSR1, SIG_DFL);
        }
    })->with(['epoll_pwait', 'epoll_pwait2']);
});
