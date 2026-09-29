<?php

/** @generate-class-entries */

/**
 * @var int
 * @cvalue EPOLLIN
 */
const EPOLLIN = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLPRI
 */
const EPOLLPRI = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLOUT
 */
const EPOLLOUT = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLRDNORM
 */
const EPOLLRDNORM = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLRDBAND
 */
const EPOLLRDBAND = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLWRNORM
 */
const EPOLLWRNORM = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLWRBAND
 */
const EPOLLWRBAND = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLMSG
 */
const EPOLLMSG = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLERR
 */
const EPOLLERR = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLHUP
 */
const EPOLLHUP = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLRDHUP
 */
const EPOLLRDHUP = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLEXCLUSIVE
 */
const EPOLLEXCLUSIVE = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLWAKEUP
 */
const EPOLLWAKEUP = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLLONESHOT
 */
const EPOLLONESHOT = UNKNOWN;
/**
 * @var int
 * @cvalue PHP_EPOLLET
 */
const EPOLLET = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLL_CTL_ADD
 */
const EPOLL_CTL_ADD = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLL_CTL_DEL
 */
const EPOLL_CTL_DEL = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLL_CTL_MOD
 */
const EPOLL_CTL_MOD = UNKNOWN;
/**
 * @var int
 * @cvalue EPOLL_CLOEXEC
 */
const EPOLL_CLOEXEC = UNKNOWN;

function epoll_create(int $size): int {}

function epoll_create1(int $flags): int {}

/** @param resource|Socket|int $fd */
function epoll_ctl(int $epfd, int $op, mixed $fd, ?int $events = null, ?int $data = null): int {}

/** @return list<array{events: int, data: int}>|false */
function epoll_wait(int $epfd, int $maxevents, int $timeout): array|false {}

/** @return list<array{events: int, data: int}>|false */
function epoll_pwait(int $epfd, int $maxevents, int $timeout, ?array $sigmask): array|false {}

/** @return list<array{events: int, data: int}>|false */
function epoll_pwait2(int $epfd, int $maxevents, ?int $timeout_ns, ?array $sigmask): array|false {}

function epoll_errno(): int {}
