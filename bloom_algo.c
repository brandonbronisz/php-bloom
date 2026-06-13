#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "bloom_algo.h"

uint64_t php_bloom_fnv1a_hash(const unsigned char *data, size_t len, uint64_t seed)
{
	uint64_t hash = 14695981039346656037ULL;
	const uint64_t prime = 1099511628211ULL;
	size_t i;

	hash ^= seed;
	hash *= prime;

	for (i = 0; i < len; i++) {
		hash ^= data[i];
		hash *= prime;
	}

	return hash;
}

void php_bloom_hash_pair(const unsigned char *data, size_t len, uint64_t *h1, uint64_t *h2)
{
	/* Kirsch-Mitzenmacher double hashing derives k positions from two hashes. */
	*h1 = php_bloom_fnv1a_hash(data, len, 0);
	*h2 = php_bloom_fnv1a_hash(data, len, 1);

	if (*h2 == 0) {
		*h2 = 1;
	}
}

zend_long php_bloom_position_from_pair(uint64_t h1, uint64_t h2, zend_long bits, zend_long index)
{
	return (zend_long) ((h1 + ((uint64_t) index * h2)) % (uint64_t) bits);
}
