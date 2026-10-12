#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "php_network.h"
#include "ext/standard/info.h"
#include "php_epoll.h"

#include <errno.h>
#include <limits.h>
#include <signal.h>
#include <stdint.h>
#include <sys/epoll.h>
#include <sys/syscall.h>
#include <time.h>
#include <unistd.h>

/* The header is empty unless HAVE_SOCKETS is defined. php_config.h defines it only when
 * ext/sockets was built into PHP; distributions that build it shared (Debian, Ubuntu)
 * leave it undefined and still ship the header, so define it here. The declarations
 * are types and macros only; the Socket class is resolved by name at call time. */
#if __has_include("ext/sockets/php_sockets.h")
# ifndef HAVE_SOCKETS
#  define HAVE_SOCKETS 1
# endif
# include "ext/sockets/php_sockets.h"
# define EPOLL_HAVE_SOCKETS 1
#endif

/* EPOLLET is bit 31; pin it to its unsigned value (2147483648 on 64-bit zend_long). */
#define PHP_EPOLLET ((zend_long) (uint32_t) EPOLLET)

#include "epoll_arginfo.h"

ZEND_DECLARE_MODULE_GLOBALS(epoll)

/* Largest maxevents the kernel accepts (EP_MAX_EVENTS in fs/eventpoll.c). */
#define EPOLL_MAX_EVENTS ((zend_long) (INT_MAX / sizeof(struct epoll_event)))

static bool epoll_long_to_int(zend_long value, uint32_t arg_num, int *out)
{
	if (value < INT_MIN || value > INT_MAX) {
		zend_argument_value_error(arg_num, "must be between %d and %d", INT_MIN, INT_MAX);
		return false;
	}

	*out = (int) value;
	return true;
}

static bool epoll_zval_to_fd(zval *zfd, uint32_t arg_num, int *fd)
{
	switch (Z_TYPE_P(zfd)) {
		case IS_LONG:
			return epoll_long_to_int(Z_LVAL_P(zfd), arg_num, fd);

		case IS_RESOURCE: {
			php_stream *stream = (php_stream *) zend_fetch_resource2_ex(
				zfd, NULL, php_file_le_stream(), php_file_le_pstream()
			);
			php_socket_t stream_fd = -1;

			if (stream == NULL) {
				zend_argument_type_error(arg_num, "must be a valid stream resource");
				return false;
			}

			if (php_stream_cast(stream, PHP_STREAM_AS_FD_FOR_SELECT | PHP_STREAM_CAST_INTERNAL,
					(void **) &stream_fd, 0) != SUCCESS || stream_fd < 0) {
				zend_argument_value_error(arg_num, "must be a stream backed by a file descriptor");
				return false;
			}

			*fd = (int) stream_fd;
			return true;
		}

#ifdef EPOLL_HAVE_SOCKETS
		case IS_OBJECT: {
			/* Resolved by name so epoll.so never links against ext/sockets symbols. */
			zend_class_entry *socket_class = zend_hash_str_find_ptr(CG(class_table), "socket", sizeof("socket") - 1);

			if (socket_class != NULL && Z_OBJCE_P(zfd) == socket_class) {
				php_socket *socket = Z_SOCKET_P(zfd);

				if (socket->bsd_socket < 0) {
					zend_argument_value_error(arg_num, "has already been closed");
					return false;
				}

				*fd = (int) socket->bsd_socket;
				return true;
			}
			break;
		}
#endif
	}

	zend_argument_type_error(arg_num, "must be of type Socket|resource|int, %s given", zend_zval_value_name(zfd));
	return false;
}

static bool epoll_sigmask_to_sigset(HashTable *sigmask, uint32_t arg_num, sigset_t *set)
{
	zval *signo;

	sigemptyset(set);

	ZEND_HASH_FOREACH_VAL(sigmask, signo) {
		if (Z_TYPE_P(signo) != IS_LONG) {
			zend_argument_type_error(arg_num, "must contain only int signal numbers, %s given", zend_zval_value_name(signo));
			return false;
		}

		if (Z_LVAL_P(signo) < 1 || Z_LVAL_P(signo) >= NSIG || sigaddset(set, (int) Z_LVAL_P(signo)) != 0) {
			zend_argument_value_error(arg_num, "must contain only valid signal numbers, " ZEND_LONG_FMT " given", Z_LVAL_P(signo));
			return false;
		}
	} ZEND_HASH_FOREACH_END();

	return true;
}

/* Out-of-range maxevents still reaches the kernel (EINVAL) through a one-slot buffer. */
static struct epoll_event *epoll_events_buffer(zend_long maxevents, struct epoll_event *fallback, int *maxevents_out)
{
	if (maxevents <= 0 || maxevents > EPOLL_MAX_EVENTS) {
		*maxevents_out = maxevents <= 0 ? 0 : INT_MAX;
		return fallback;
	}

	*maxevents_out = (int) maxevents;
	return safe_emalloc((size_t) maxevents, sizeof(struct epoll_event), 0);
}

static void epoll_return_events(zval *return_value, int count, int saved_errno,
	struct epoll_event *events, struct epoll_event *fallback)
{
	if (count < 0) {
		EPOLL_G(last_errno) = saved_errno;
		RETVAL_FALSE;
	} else {
		array_init_size(return_value, (uint32_t) count);

		for (int i = 0; i < count; i++) {
			zval event;

			array_init_size(&event, 2);
			add_assoc_long(&event, "events", (zend_long) events[i].events);
			add_assoc_long(&event, "data", (zend_long) events[i].data.u64);
			add_next_index_zval(return_value, &event);
		}
	}

	if (events != fallback) {
		efree(events);
	}
}

static int epoll_pwait2_call(int epfd, struct epoll_event *events, int maxevents,
	const struct timespec *timeout, const sigset_t *sigmask)
{
#ifdef HAVE_EPOLL_PWAIT2
	return epoll_pwait2(epfd, events, maxevents, timeout, sigmask);
#elif defined(SYS_epoll_pwait2)
	/* Raw syscall wants a kernel timespec (64-bit fields) and the kernel sigset size. */
	struct {
		int64_t tv_sec;
		long long tv_nsec;
	} kernel_timeout;

	if (timeout != NULL) {
		kernel_timeout.tv_sec = (int64_t) timeout->tv_sec;
		kernel_timeout.tv_nsec = (long long) timeout->tv_nsec;
	}

	return (int) syscall(SYS_epoll_pwait2, epfd, events, maxevents,
		timeout != NULL ? &kernel_timeout : NULL, sigmask, (size_t) (_NSIG / 8));
#else
	errno = ENOSYS;
	return -1;
#endif
}

PHP_FUNCTION(epoll_create)
{
	zend_long size;
	int size_i, fd;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(size)
	ZEND_PARSE_PARAMETERS_END();

	if (!epoll_long_to_int(size, 1, &size_i)) {
		RETURN_THROWS();
	}

	fd = epoll_create(size_i);
	if (fd < 0) {
		EPOLL_G(last_errno) = errno;
	}

	RETURN_LONG(fd);
}

PHP_FUNCTION(epoll_create1)
{
	zend_long flags;
	int flags_i, fd;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(flags)
	ZEND_PARSE_PARAMETERS_END();

	if (!epoll_long_to_int(flags, 1, &flags_i)) {
		RETURN_THROWS();
	}

	fd = epoll_create1(flags_i);
	if (fd < 0) {
		EPOLL_G(last_errno) = errno;
	}

	RETURN_LONG(fd);
}

PHP_FUNCTION(epoll_ctl)
{
	zend_long epfd, op, events = 0, data = 0;
	bool events_is_null = true, data_is_null = true;
	zval *zfd;
	int epfd_i, op_i, fd, result;
	struct epoll_event event;

	ZEND_PARSE_PARAMETERS_START(3, 5)
		Z_PARAM_LONG(epfd)
		Z_PARAM_LONG(op)
		Z_PARAM_ZVAL(zfd)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG_OR_NULL(events, events_is_null)
		Z_PARAM_LONG_OR_NULL(data, data_is_null)
	ZEND_PARSE_PARAMETERS_END();

	if (!epoll_long_to_int(epfd, 1, &epfd_i)
		|| !epoll_long_to_int(op, 2, &op_i)
		|| !epoll_zval_to_fd(zfd, 3, &fd)) {
		RETURN_THROWS();
	}

#if SIZEOF_ZEND_LONG > 4
	if (!events_is_null && (events < 0 || events > (zend_long) UINT32_MAX)) {
		zend_argument_value_error(4, "must be between 0 and %u", UINT32_MAX);
		RETURN_THROWS();
	}
#else
	/* 32-bit zend_long: every value is a valid uint32 bit pattern; EPOLLET arrives negative. */
#endif

	memset(&event, 0, sizeof(event));
	event.events = (uint32_t) events;
	event.data.u64 = data_is_null ? (uint64_t) fd : (uint64_t) data;

	/* A null $events passes a NULL event pointer, exactly as C callers do for EPOLL_CTL_DEL. */
	result = epoll_ctl(epfd_i, op_i, fd, events_is_null ? NULL : &event);
	if (result < 0) {
		EPOLL_G(last_errno) = errno;
	}

	RETURN_LONG(result);
}

PHP_FUNCTION(epoll_wait)
{
	zend_long epfd, maxevents, timeout;
	int epfd_i, timeout_i, maxevents_i, count, saved_errno = 0;
	struct epoll_event fallback, *events;

	ZEND_PARSE_PARAMETERS_START(3, 3)
		Z_PARAM_LONG(epfd)
		Z_PARAM_LONG(maxevents)
		Z_PARAM_LONG(timeout)
	ZEND_PARSE_PARAMETERS_END();

	if (!epoll_long_to_int(epfd, 1, &epfd_i) || !epoll_long_to_int(timeout, 3, &timeout_i)) {
		RETURN_THROWS();
	}

	events = epoll_events_buffer(maxevents, &fallback, &maxevents_i);
	count = epoll_wait(epfd_i, events, maxevents_i, timeout_i);
	if (count < 0) {
		saved_errno = errno;
	}

	epoll_return_events(return_value, count, saved_errno, events, &fallback);
}

PHP_FUNCTION(epoll_pwait)
{
	zend_long epfd, maxevents, timeout;
	HashTable *sigmask;
	int epfd_i, timeout_i, maxevents_i, count, saved_errno = 0;
	sigset_t set;
	struct epoll_event fallback, *events;

	ZEND_PARSE_PARAMETERS_START(4, 4)
		Z_PARAM_LONG(epfd)
		Z_PARAM_LONG(maxevents)
		Z_PARAM_LONG(timeout)
		Z_PARAM_ARRAY_HT_OR_NULL(sigmask)
	ZEND_PARSE_PARAMETERS_END();

	if (!epoll_long_to_int(epfd, 1, &epfd_i)
		|| !epoll_long_to_int(timeout, 3, &timeout_i)
		|| (sigmask != NULL && !epoll_sigmask_to_sigset(sigmask, 4, &set))) {
		RETURN_THROWS();
	}

	events = epoll_events_buffer(maxevents, &fallback, &maxevents_i);
	count = epoll_pwait(epfd_i, events, maxevents_i, timeout_i, sigmask != NULL ? &set : NULL);
	if (count < 0) {
		saved_errno = errno;
	}

	epoll_return_events(return_value, count, saved_errno, events, &fallback);
}

PHP_FUNCTION(epoll_pwait2)
{
	zend_long epfd, maxevents, timeout_ns = 0;
	bool timeout_is_null = true;
	HashTable *sigmask;
	int epfd_i, maxevents_i, count, saved_errno = 0;
	sigset_t set;
	struct timespec timeout;
	struct epoll_event fallback, *events;

	ZEND_PARSE_PARAMETERS_START(4, 4)
		Z_PARAM_LONG(epfd)
		Z_PARAM_LONG(maxevents)
		Z_PARAM_LONG_OR_NULL(timeout_ns, timeout_is_null)
		Z_PARAM_ARRAY_HT_OR_NULL(sigmask)
	ZEND_PARSE_PARAMETERS_END();

	if (!epoll_long_to_int(epfd, 1, &epfd_i)
		|| (sigmask != NULL && !epoll_sigmask_to_sigset(sigmask, 4, &set))) {
		RETURN_THROWS();
	}

	/* Negative nanoseconds give a negative timespec, which the kernel rejects with EINVAL. */
	if (!timeout_is_null) {
		timeout.tv_sec = (time_t) (timeout_ns / 1000000000);
		timeout.tv_nsec = (long) (timeout_ns % 1000000000);
	}

	events = epoll_events_buffer(maxevents, &fallback, &maxevents_i);
	count = epoll_pwait2_call(epfd_i, events, maxevents_i,
		timeout_is_null ? NULL : &timeout, sigmask != NULL ? &set : NULL);
	if (count < 0) {
		saved_errno = errno;
	}

	epoll_return_events(return_value, count, saved_errno, events, &fallback);
}

PHP_FUNCTION(epoll_errno)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_LONG(EPOLL_G(last_errno));
}

static PHP_GINIT_FUNCTION(epoll)
{
#if defined(COMPILE_DL_EPOLL) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	epoll_globals->last_errno = 0;
}

static PHP_MINIT_FUNCTION(epoll)
{
	register_epoll_symbols(module_number);
	return SUCCESS;
}

static PHP_MINFO_FUNCTION(epoll)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "epoll support", "enabled");
	php_info_print_table_row(2, "Version", PHP_EPOLL_VERSION);
#ifdef HAVE_EPOLL_PWAIT2
	php_info_print_table_row(2, "epoll_pwait2", "libc");
#elif defined(SYS_epoll_pwait2)
	php_info_print_table_row(2, "epoll_pwait2", "syscall");
#else
	php_info_print_table_row(2, "epoll_pwait2", "unavailable (ENOSYS)");
#endif
	php_info_print_table_end();
}

static const zend_module_dep epoll_deps[] = {
	ZEND_MOD_OPTIONAL("sockets")
	ZEND_MOD_END
};

zend_module_entry epoll_module_entry = {
	STANDARD_MODULE_HEADER_EX,
	NULL,
	epoll_deps,
	"epoll",
	ext_functions,
	PHP_MINIT(epoll),
	NULL,
	NULL,
	NULL,
	PHP_MINFO(epoll),
	PHP_EPOLL_VERSION,
	PHP_MODULE_GLOBALS(epoll),
	PHP_GINIT(epoll),
	NULL,
	NULL,
	STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_EPOLL
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(epoll)
#endif
