#ifndef BLOOM_ALGO_H
#define BLOOM_ALGO_H

#include <stddef.h>
#include <stdint.h>

#include "php.h"

uint64_t php_bloom_fnv1a_hash(const unsigned char *data, size_t len, uint64_t seed);
void php_bloom_hash_pair(const unsigned char *data, size_t len, uint64_t *h1, uint64_t *h2);
zend_long php_bloom_position_from_pair(uint64_t h1, uint64_t h2, zend_long bits, zend_long index);

#endif
