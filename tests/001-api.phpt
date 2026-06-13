--TEST--
bloom exposes the expected API surface
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

bloom_test_assert_same('0.1.0', bloom_version(), 'extension version');

foreach ([
	'bloom_version',
	'bloom_hash',
	'bloom_optimal_bits',
	'bloom_optimal_hashes',
	'bloom_positions',
] as $function) {
	bloom_test_assert_true(function_exists($function), "{$function} exists");
}

$class = new ReflectionClass(Bloom\Filter::class);

bloom_test_assert_true($class->isInternal(), 'Bloom\Filter is internal');
bloom_test_assert_true($class->isFinal(), 'Bloom\Filter is final');

bloom_test_assert_same([
	'__construct',
	'capacity',
	'bits',
	'hashes',
	'bytes',
	'add',
	'mightContain',
	'export',
	'import',
	'setBits',
	'fillRatio',
	'estimatedFalsePositiveRate',
	'stats',
], array_map(static fn (ReflectionMethod $method): string => $method->getName(), $class->getMethods()), 'Bloom\Filter methods');

echo "done\n";
?>
--EXPECT--
done
