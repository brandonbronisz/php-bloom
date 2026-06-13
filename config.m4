PHP_ARG_ENABLE([bloom],
	[whether to enable bloom support],
	[AS_HELP_STRING([--enable-bloom], [Enable bloom support])],
	[no])

if test "$PHP_BLOOM" != "no"; then
	PHP_ADD_LIBRARY(m, 1, BLOOM_SHARED_LIBADD)
	PHP_SUBST(BLOOM_SHARED_LIBADD)

	PHP_NEW_EXTENSION([bloom], [bloom.c bloom_algo.c bloom_filter.c], [$ext_shared])
fi
