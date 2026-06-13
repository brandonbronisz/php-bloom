--TEST--
Bloom\Filter stats report consistent counts, ratios, and padding-bit handling
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

$filter = new Bloom\Filter(1000, 0.01);

bloom_test_assert_same(0, $filter->setBits(), 'empty set bit count');
bloom_test_assert_same(0.0, $filter->fillRatio(), 'empty fill ratio');
bloom_test_assert_same(0.0, $filter->estimatedFalsePositiveRate(), 'empty estimated false positive rate');

$previousSetBits = $filter->setBits();

foreach (['hello', 'world', "abc\0def", ''] as $value) {
	$filter->add($value);
	bloom_test_assert_true($filter->setBits() > $previousSetBits, 'new value sets at least one bit');
	$previousSetBits = $filter->setBits();
}

$filter->add('hello');
bloom_test_assert_same($previousSetBits, $filter->setBits(), 'duplicate value leaves set bit count unchanged');

$stats = $filter->stats();

bloom_test_assert_same([
	'capacity',
	'bits',
	'hashes',
	'bytes',
	'set_bits',
	'fill_ratio',
	'estimated_false_positive_rate',
], array_keys($stats), 'stats keys');

bloom_test_assert_same($filter->capacity(), $stats['capacity'], 'stats capacity');
bloom_test_assert_same($filter->bits(), $stats['bits'], 'stats bits');
bloom_test_assert_same($filter->hashes(), $stats['hashes'], 'stats hashes');
bloom_test_assert_same($filter->bytes(), $stats['bytes'], 'stats bytes');
bloom_test_assert_same($filter->setBits(), $stats['set_bits'], 'stats set bits');
bloom_test_assert_same($filter->fillRatio(), $stats['fill_ratio'], 'stats fill ratio');
bloom_test_assert_same($filter->estimatedFalsePositiveRate(), $stats['estimated_false_positive_rate'], 'stats estimated false positive rate');

$expectedFillRatio = $filter->setBits() / $filter->bits();
$expectedFalsePositiveRate = $expectedFillRatio ** $filter->hashes();

bloom_test_assert_approx($expectedFillRatio, $filter->fillRatio(), 0.0, 'fill ratio formula');
bloom_test_assert_approx($expectedFalsePositiveRate, $filter->estimatedFalsePositiveRate(), 1.0e-30, 'estimated false positive rate formula');

$padding = (new Bloom\Filter(1000, 0.01))->export();
$padding[strlen($padding) - 1] = "\xfc";
$withPaddingBits = Bloom\Filter::import($padding);

bloom_test_assert_same(0, $withPaddingBits->setBits(), 'padding bits in final byte are ignored');
bloom_test_assert_same(0.0, $withPaddingBits->fillRatio(), 'ignored padding bits do not affect fill ratio');

echo "done\n";
?>
--EXPECT--
done
