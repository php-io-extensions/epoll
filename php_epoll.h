#ifndef PHP_EPOLL_H
#define PHP_EPOLL_H

extern zend_module_entry epoll_module_entry;
#define phpext_epoll_ptr &epoll_module_entry

#define PHP_EPOLL_VERSION "0.10.0"

ZEND_BEGIN_MODULE_GLOBALS(epoll)
	int last_errno;
ZEND_END_MODULE_GLOBALS(epoll)

ZEND_EXTERN_MODULE_GLOBALS(epoll)
#define EPOLL_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(epoll, v)

#if defined(ZTS) && defined(COMPILE_DL_EPOLL)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif
