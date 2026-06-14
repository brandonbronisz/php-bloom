PHP_ARG_ENABLE([bloom],
	[whether to enable bloom support],
	[AS_HELP_STRING([--enable-bloom], [Enable bloom support])],
	[yes])

if test "$PHP_BLOOM" != "no"; then
	AC_DEFINE([HAVE_BLOOM], [1], [Define to 1 if the PHP extension 'bloom' is available.])

	PHP_ADD_LIBRARY(m, 1, BLOOM_SHARED_LIBADD)
	PHP_SUBST(BLOOM_SHARED_LIBADD)

	PHP_NEW_EXTENSION([bloom], [bloom.c bloom_algo.c bloom_filter.c], [$ext_shared],, [-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1])
fi
