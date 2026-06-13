#ifndef PHP_BLOOM_H
#define PHP_BLOOM_H

typedef struct _zend_module_entry zend_module_entry;

extern zend_module_entry bloom_module_entry;
#define phpext_bloom_ptr &bloom_module_entry

#define PHP_BLOOM_VERSION "0.1.0"

#endif
