#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <math.h>
#include <stddef.h>
#include <stdint.h>

#include "php.h"
#include "ext/standard/info.h"
#include "php_bloom.h"
#include "bloom_algo.h"
#include "bloom_filter.h"
#include "bloom_arginfo.h"

PHP_FUNCTION(bloom_version)
{
	RETURN_STRING(PHP_BLOOM_VERSION);
}

PHP_FUNCTION(bloom_hash)
{
	zend_string *value;
	zend_long seed = 0;
	uint64_t hash;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_STR(value)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(seed)
	ZEND_PARSE_PARAMETERS_END();

	hash = php_bloom_fnv1a_hash(
		(const unsigned char *) ZSTR_VAL(value),
		ZSTR_LEN(value),
		(uint64_t) seed
	);

	RETURN_LONG((zend_long) (hash & ZEND_LONG_MAX));
}

PHP_FUNCTION(bloom_optimal_bits)
{
	zend_long capacity;
	double false_positive_rate;
	double bits;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_LONG(capacity)
		Z_PARAM_DOUBLE(false_positive_rate)
	ZEND_PARSE_PARAMETERS_END();

	if (capacity <= 0) {
		zend_value_error("capacity must be greater than 0");
		RETURN_THROWS();
	}

	if (!isfinite(false_positive_rate) || false_positive_rate <= 0.0 || false_positive_rate >= 1.0) {
		zend_value_error("falsePositiveRate must be greater than 0 and less than 1");
		RETURN_THROWS();
	}

	bits = -((double) capacity * log(false_positive_rate)) / (log(2.0) * log(2.0));

	if (!isfinite(bits) || bits > (double) ZEND_LONG_MAX) {
		zend_value_error("calculated bit size is too large");
		RETURN_THROWS();
	}

	RETURN_LONG((zend_long) ceil(bits));
}

PHP_FUNCTION(bloom_optimal_hashes)
{
	zend_long bits;
	zend_long capacity;
	double hashes;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_LONG(bits)
		Z_PARAM_LONG(capacity)
	ZEND_PARSE_PARAMETERS_END();

	if (bits <= 0) {
		zend_value_error("bits must be greater than 0");
		RETURN_THROWS();
	}

	if (capacity <= 0) {
		zend_value_error("capacity must be greater than 0");
		RETURN_THROWS();
	}

	hashes = ((double) bits / (double) capacity) * log(2.0);

	if (!isfinite(hashes) || hashes > (double) ZEND_LONG_MAX) {
		zend_value_error("calculated hash count is too large");
		RETURN_THROWS();
	}

	if (hashes < 1.0) {
		RETURN_LONG(1);
	}

	RETURN_LONG((zend_long) ceil(hashes));
}

PHP_FUNCTION(bloom_positions)
{
	zend_string *value;
	zend_long bits;
	zend_long hashes;
	uint64_t h1;
	uint64_t h2;
	uint64_t position;
	zend_long i;

	ZEND_PARSE_PARAMETERS_START(3, 3)
		Z_PARAM_STR(value)
		Z_PARAM_LONG(bits)
		Z_PARAM_LONG(hashes)
	ZEND_PARSE_PARAMETERS_END();

	if (bits <= 0) {
		zend_value_error("bits must be greater than 0");
		RETURN_THROWS();
	}

	if (hashes <= 0) {
		zend_value_error("hashes must be greater than 0");
		RETURN_THROWS();
	}

	h1 = php_bloom_fnv1a_hash(
		(const unsigned char *) ZSTR_VAL(value),
		ZSTR_LEN(value),
		0
	);

	h2 = php_bloom_fnv1a_hash(
		(const unsigned char *) ZSTR_VAL(value),
		ZSTR_LEN(value),
		1
	);

	if (h2 == 0) {
		h2 = 1;
	}

	array_init(return_value);

	for (i = 0; i < hashes; i++) {
		position = (h1 + ((uint64_t) i * h2)) % (uint64_t) bits;
		add_next_index_long(return_value, (zend_long) position);
	}
}

PHP_MINIT_FUNCTION(bloom)
{
	php_bloom_filter_register_handlers(register_class_Bloom_Filter());

	return SUCCESS;
}

PHP_MINFO_FUNCTION(bloom)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "bloom support", "enabled");
	php_info_print_table_row(2, "version", PHP_BLOOM_VERSION);
	php_info_print_table_end();
}

zend_module_entry bloom_module_entry = {
	STANDARD_MODULE_HEADER,
	"bloom",
	ext_functions,
	PHP_MINIT(bloom),
	NULL,
	NULL,
	NULL,
	PHP_MINFO(bloom),
	PHP_BLOOM_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_BLOOM
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(bloom)
#endif
