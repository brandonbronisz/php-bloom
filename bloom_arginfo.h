/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: dffb012af511e83e6cae57a76cda97b33868cbbe */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_bloom_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_bloom_hash, 0, 1, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, seed, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_bloom_optimal_bits, 0, 2, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, capacity, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, falsePositiveRate, IS_DOUBLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_bloom_optimal_hashes, 0, 2, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, bits, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, capacity, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_bloom_positions, 0, 3, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, bits, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, hashes, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Bloom_Filter___construct, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, capacity, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, falsePositiveRate, IS_DOUBLE, 0, "0.01")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bloom_Filter_capacity, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Bloom_Filter_bits arginfo_class_Bloom_Filter_capacity

#define arginfo_class_Bloom_Filter_hashes arginfo_class_Bloom_Filter_capacity

#define arginfo_class_Bloom_Filter_bytes arginfo_class_Bloom_Filter_capacity

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bloom_Filter_add, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bloom_Filter_mightContain, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Bloom_Filter_export arginfo_bloom_version

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Bloom_Filter_import, 0, 1, Bloom\\Filter, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Bloom_Filter_setBits arginfo_class_Bloom_Filter_capacity

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bloom_Filter_fillRatio, 0, 0, IS_DOUBLE, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Bloom_Filter_estimatedFalsePositiveRate arginfo_class_Bloom_Filter_fillRatio

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bloom_Filter_stats, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()


ZEND_FUNCTION(bloom_version);
ZEND_FUNCTION(bloom_hash);
ZEND_FUNCTION(bloom_optimal_bits);
ZEND_FUNCTION(bloom_optimal_hashes);
ZEND_FUNCTION(bloom_positions);
ZEND_METHOD(Bloom_Filter, __construct);
ZEND_METHOD(Bloom_Filter, capacity);
ZEND_METHOD(Bloom_Filter, bits);
ZEND_METHOD(Bloom_Filter, hashes);
ZEND_METHOD(Bloom_Filter, bytes);
ZEND_METHOD(Bloom_Filter, add);
ZEND_METHOD(Bloom_Filter, mightContain);
ZEND_METHOD(Bloom_Filter, export);
ZEND_METHOD(Bloom_Filter, import);
ZEND_METHOD(Bloom_Filter, setBits);
ZEND_METHOD(Bloom_Filter, fillRatio);
ZEND_METHOD(Bloom_Filter, estimatedFalsePositiveRate);
ZEND_METHOD(Bloom_Filter, stats);


static const zend_function_entry ext_functions[] = {
	ZEND_FE(bloom_version, arginfo_bloom_version)
	ZEND_FE(bloom_hash, arginfo_bloom_hash)
	ZEND_FE(bloom_optimal_bits, arginfo_bloom_optimal_bits)
	ZEND_FE(bloom_optimal_hashes, arginfo_bloom_optimal_hashes)
	ZEND_FE(bloom_positions, arginfo_bloom_positions)
	ZEND_FE_END
};


static const zend_function_entry class_Bloom_Filter_methods[] = {
	ZEND_ME(Bloom_Filter, __construct, arginfo_class_Bloom_Filter___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, capacity, arginfo_class_Bloom_Filter_capacity, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, bits, arginfo_class_Bloom_Filter_bits, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, hashes, arginfo_class_Bloom_Filter_hashes, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, bytes, arginfo_class_Bloom_Filter_bytes, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, add, arginfo_class_Bloom_Filter_add, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, mightContain, arginfo_class_Bloom_Filter_mightContain, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, export, arginfo_class_Bloom_Filter_export, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, import, arginfo_class_Bloom_Filter_import, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Bloom_Filter, setBits, arginfo_class_Bloom_Filter_setBits, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, fillRatio, arginfo_class_Bloom_Filter_fillRatio, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, estimatedFalsePositiveRate, arginfo_class_Bloom_Filter_estimatedFalsePositiveRate, ZEND_ACC_PUBLIC)
	ZEND_ME(Bloom_Filter, stats, arginfo_class_Bloom_Filter_stats, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Bloom_Filter(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Bloom", "Filter", class_Bloom_Filter_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	return class_entry;
}
