#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <math.h>
#include <stddef.h>
#include <stdint.h>

#include "php.h"
#include "bloom_algo.h"
#include "bloom_filter.h"

/* Export format: "BLM1" + capacity/bits/hashes/byte_count as little-endian uint64 values. */
#define BLOOM_EXPORT_HEADER_SIZE 36

static zend_class_entry *bloom_filter_ce;
static zend_object_handlers bloom_filter_handlers;

typedef struct bloom_filter_object {
	zend_long capacity;
	zend_long bits;
	zend_long hashes;
	size_t byte_count;
	unsigned char *bitset;

	zend_object std;
} bloom_filter_object;

static inline bloom_filter_object *bloom_filter_from_object(zend_object *obj)
{
	/* std is embedded at the end of bloom_filter_object, so subtract its offset. */
	return (bloom_filter_object *) ((char *) obj - offsetof(bloom_filter_object, std));
}

static zend_object *bloom_filter_create_object(zend_class_entry *ce)
{
	bloom_filter_object *intern = ecalloc(1, sizeof(bloom_filter_object) + zend_object_properties_size(ce));

	zend_object_std_init(&intern->std, ce);
	object_properties_init(&intern->std, ce);

	intern->std.handlers = &bloom_filter_handlers;

	return &intern->std;
}

static void bloom_filter_free_object(zend_object *obj)
{
	bloom_filter_object *intern = bloom_filter_from_object(obj);

	if (intern->bitset != NULL) {
		efree(intern->bitset);
		intern->bitset = NULL;
	}

	zend_object_std_dtor(&intern->std);
}

#define Z_BLOOM_FILTER_P(zv) bloom_filter_from_object(Z_OBJ_P(zv))

void php_bloom_filter_register_handlers(zend_class_entry *ce)
{
	bloom_filter_ce = ce;
	bloom_filter_ce->create_object = bloom_filter_create_object;

	/* Start from Zend's standard handlers and override only object lifecycle hooks. */
	memcpy(&bloom_filter_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
	bloom_filter_handlers.offset = offsetof(bloom_filter_object, std);
	bloom_filter_handlers.free_obj = bloom_filter_free_object;
	bloom_filter_handlers.clone_obj = NULL;
}

static unsigned int bloom_popcount_u8(unsigned char value)
{
	unsigned int count = 0;

	while (value != 0) {
		count += value & 1u;
		value >>= 1u;
	}

	return count;
}

static zend_long bloom_bitset_count_set_bits(const unsigned char *bitset, size_t byte_count, zend_long bits)
{
	zend_long count = 0;
	unsigned int valid_bits_in_last_byte;
	unsigned char mask;
	size_t i;

	if (byte_count == 0) {
		return 0;
	}

	for (i = 0; i + 1 < byte_count; i++) {
		count += bloom_popcount_u8(bitset[i]);
	}

	valid_bits_in_last_byte = (unsigned int) (bits % 8);

	/* The final byte may contain padding bits outside the configured filter size. */
	if (valid_bits_in_last_byte == 0) {
		count += bloom_popcount_u8(bitset[byte_count - 1]);
	} else {
		mask = (unsigned char) ((1u << valid_bits_in_last_byte) - 1u);
		count += bloom_popcount_u8((unsigned char) (bitset[byte_count - 1] & mask));
	}

	return count;
}

static void bloom_write_u64_le(unsigned char *out, uint64_t value)
{
	int i;

	for (i = 0; i < 8; i++) {
		out[i] = (unsigned char) ((value >> (i * 8)) & 0xff);
	}
}

static uint64_t bloom_read_u64_le(const unsigned char *in)
{
	uint64_t value = 0;
	int i;

	for (i = 0; i < 8; i++) {
		value |= ((uint64_t) in[i]) << (i * 8);
	}

	return value;
}

static bool bloom_validate_export_metadata(uint64_t capacity, uint64_t bits, uint64_t hashes, uint64_t byte_count)
{
	if (capacity == 0 || bits == 0 || hashes == 0 || byte_count == 0) {
		return false;
	}

	if (capacity > (uint64_t) ZEND_LONG_MAX || bits > (uint64_t) ZEND_LONG_MAX || hashes > (uint64_t) ZEND_LONG_MAX) {
		return false;
	}

	if (byte_count > (uint64_t) SIZE_MAX) {
		return false;
	}

	if (byte_count != ((bits + 7) / 8)) {
		return false;
	}

	return true;
}

static void bloom_bitset_set(unsigned char *bitset, zend_long bit)
{
	size_t byte_index = (size_t) bit / 8;
	unsigned char mask = (unsigned char) (1u << ((unsigned int) bit % 8));

	bitset[byte_index] |= mask;
}

static bool bloom_bitset_get(const unsigned char *bitset, zend_long bit)
{
	size_t byte_index = (size_t) bit / 8;
	unsigned char mask = (unsigned char) (1u << ((unsigned int) bit % 8));

	return (bitset[byte_index] & mask) != 0;
}

PHP_METHOD(Bloom_Filter, __construct)
{
	bloom_filter_object *intern;
	zend_long capacity;
	double false_positive_rate = 0.01;
	double bits_double;
	zend_long bits;
	zend_long hashes;
	size_t byte_count;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_LONG(capacity)
		Z_PARAM_OPTIONAL
		Z_PARAM_DOUBLE(false_positive_rate)
	ZEND_PARSE_PARAMETERS_END();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	if (capacity <= 0) {
		zend_value_error("capacity must be greater than 0");
		RETURN_THROWS();
	}

	if (!isfinite(false_positive_rate) || false_positive_rate <= 0.0 || false_positive_rate >= 1.0) {
		zend_value_error("falsePositiveRate must be greater than 0 and less than 1");
		RETURN_THROWS();
	}

	bits_double = -((double) capacity * log(false_positive_rate)) / (log(2.0) * log(2.0));

	if (!isfinite(bits_double) || bits_double > (double) ZEND_LONG_MAX) {
		zend_value_error("calculated bit size is too large");
		RETURN_THROWS();
	}

	bits = (zend_long) ceil(bits_double);
	hashes = (zend_long) ceil(((double) bits / (double) capacity) * log(2.0));

	if (hashes < 1) {
		hashes = 1;
	}

	byte_count = ((size_t) bits + 7) / 8;

	if (intern->bitset != NULL) {
		efree(intern->bitset);
	}

	intern->capacity = capacity;
	intern->bits = bits;
	intern->hashes = hashes;
	intern->byte_count = byte_count;
	intern->bitset = ecalloc(byte_count, sizeof(unsigned char));
}

PHP_METHOD(Bloom_Filter, capacity)
{
	const bloom_filter_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	RETURN_LONG(intern->capacity);
}

PHP_METHOD(Bloom_Filter, bits)
{
	const bloom_filter_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	RETURN_LONG(intern->bits);
}

PHP_METHOD(Bloom_Filter, hashes)
{
	const bloom_filter_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	RETURN_LONG(intern->hashes);
}

PHP_METHOD(Bloom_Filter, bytes)
{
	const bloom_filter_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	RETURN_LONG((zend_long) intern->byte_count);
}

PHP_METHOD(Bloom_Filter, add)
{
	bloom_filter_object *intern;
	zend_string *value;
	uint64_t h1;
	uint64_t h2;
	zend_long position;
	zend_long i;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(value)
	ZEND_PARSE_PARAMETERS_END();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	php_bloom_hash_pair(
		(const unsigned char *) ZSTR_VAL(value),
		ZSTR_LEN(value),
		&h1,
		&h2
	);

	for (i = 0; i < intern->hashes; i++) {
		position = php_bloom_position_from_pair(h1, h2, intern->bits, i);
		bloom_bitset_set(intern->bitset, position);
	}
}

PHP_METHOD(Bloom_Filter, mightContain)
{
	const bloom_filter_object *intern;
	zend_string *value;
	uint64_t h1;
	uint64_t h2;
	zend_long position;
	zend_long i;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(value)
	ZEND_PARSE_PARAMETERS_END();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	php_bloom_hash_pair(
		(const unsigned char *) ZSTR_VAL(value),
		ZSTR_LEN(value),
		&h1,
		&h2
	);

	for (i = 0; i < intern->hashes; i++) {
		position = php_bloom_position_from_pair(h1, h2, intern->bits, i);

		if (!bloom_bitset_get(intern->bitset, position)) {
			RETURN_FALSE;
		}
	}

	RETURN_TRUE;
}

PHP_METHOD(Bloom_Filter, export)
{
	const bloom_filter_object *intern;
	zend_string *out;
	unsigned char *bytes;
	size_t total_size;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);
	total_size = BLOOM_EXPORT_HEADER_SIZE + intern->byte_count;

	out = zend_string_alloc(total_size, 0);
	bytes = (unsigned char *) ZSTR_VAL(out);

	memcpy(bytes, "BLM1", 4);
	bloom_write_u64_le(bytes + 4, (uint64_t) intern->capacity);
	bloom_write_u64_le(bytes + 12, (uint64_t) intern->bits);
	bloom_write_u64_le(bytes + 20, (uint64_t) intern->hashes);
	bloom_write_u64_le(bytes + 28, (uint64_t) intern->byte_count);

	memcpy(bytes + BLOOM_EXPORT_HEADER_SIZE, intern->bitset, intern->byte_count);

	ZSTR_VAL(out)[total_size] = '\0';

	RETURN_STR(out);
}

PHP_METHOD(Bloom_Filter, import)
{
	zend_string *data;
	const unsigned char *bytes;
	uint64_t capacity;
	uint64_t bits;
	uint64_t hashes;
	uint64_t byte_count;
	size_t expected_size;
	bloom_filter_object *intern;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(data)
	ZEND_PARSE_PARAMETERS_END();

	if (ZSTR_LEN(data) < BLOOM_EXPORT_HEADER_SIZE) {
		zend_value_error("invalid bloom filter data");
		RETURN_THROWS();
	}

	bytes = (const unsigned char *) ZSTR_VAL(data);

	if (memcmp(bytes, "BLM1", 4) != 0) {
		zend_value_error("invalid bloom filter data");
		RETURN_THROWS();
	}

	capacity = bloom_read_u64_le(bytes + 4);
	bits = bloom_read_u64_le(bytes + 12);
	hashes = bloom_read_u64_le(bytes + 20);
	byte_count = bloom_read_u64_le(bytes + 28);

	if (!bloom_validate_export_metadata(capacity, bits, hashes, byte_count)) {
		zend_value_error("invalid bloom filter data");
		RETURN_THROWS();
	}

	expected_size = BLOOM_EXPORT_HEADER_SIZE + (size_t) byte_count;

	if (ZSTR_LEN(data) != expected_size) {
		zend_value_error("invalid bloom filter data");
		RETURN_THROWS();
	}

	object_init_ex(return_value, bloom_filter_ce);
	intern = Z_BLOOM_FILTER_P(return_value);

	intern->capacity = (zend_long) capacity;
	intern->bits = (zend_long) bits;
	intern->hashes = (zend_long) hashes;
	intern->byte_count = (size_t) byte_count;
	intern->bitset = emalloc(intern->byte_count);

	memcpy(intern->bitset, bytes + BLOOM_EXPORT_HEADER_SIZE, intern->byte_count);
}

PHP_METHOD(Bloom_Filter, setBits)
{
	const bloom_filter_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);

	RETURN_LONG(bloom_bitset_count_set_bits(intern->bitset, intern->byte_count, intern->bits));
}

PHP_METHOD(Bloom_Filter, fillRatio)
{
	const bloom_filter_object *intern;
	zend_long set_bits;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);
	set_bits = bloom_bitset_count_set_bits(intern->bitset, intern->byte_count, intern->bits);

	RETURN_DOUBLE((double) set_bits / (double) intern->bits);
}

PHP_METHOD(Bloom_Filter, estimatedFalsePositiveRate)
{
	const bloom_filter_object *intern;
	zend_long set_bits;
	double fill_ratio;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);
	set_bits = bloom_bitset_count_set_bits(intern->bitset, intern->byte_count, intern->bits);
	fill_ratio = (double) set_bits / (double) intern->bits;

	RETURN_DOUBLE(pow(fill_ratio, (double) intern->hashes));
}

PHP_METHOD(Bloom_Filter, stats)
{
	const bloom_filter_object *intern;
	zend_long set_bits;
	double fill_ratio;
	double estimated_fpr;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_BLOOM_FILTER_P(ZEND_THIS);
	set_bits = bloom_bitset_count_set_bits(intern->bitset, intern->byte_count, intern->bits);
	fill_ratio = (double) set_bits / (double) intern->bits;
	estimated_fpr = pow(fill_ratio, (double) intern->hashes);

	array_init(return_value);

	add_assoc_long(return_value, "capacity", intern->capacity);
	add_assoc_long(return_value, "bits", intern->bits);
	add_assoc_long(return_value, "hashes", intern->hashes);
	add_assoc_long(return_value, "bytes", (zend_long) intern->byte_count);
	add_assoc_long(return_value, "set_bits", set_bits);
	add_assoc_double(return_value, "fill_ratio", fill_ratio);
	add_assoc_double(return_value, "estimated_false_positive_rate", estimated_fpr);
}
