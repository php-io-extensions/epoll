PHP_ARG_ENABLE([epoll],
  [whether to enable epoll support],
  [AS_HELP_STRING([--enable-epoll], [Enable Linux epoll(7) bindings])],
  [no])

if test "$PHP_EPOLL" != "no"; then
  AC_CHECK_HEADER([sys/epoll.h], [],
    [AC_MSG_ERROR([sys/epoll.h not found: epoll(7) is Linux-only])])

  dnl glibc >= 2.35 ships the wrapper; older libcs fall back to syscall(2).
  AC_CHECK_FUNCS([epoll_pwait2])

  PHP_NEW_EXTENSION(epoll, epoll.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
  PHP_ADD_EXTENSION_DEP(epoll, sockets, true)
fi
