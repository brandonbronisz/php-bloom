--TEST--
bloom_positions returns deterministic in-range positions
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

$positions = bloom_positions('hello', 128, 4);

bloom_test_assert_same([37, 81, 125, 41], $positions, 'known positions');
bloom_test_assert_same($positions, bloom_positions('hello', 128, 4), 'positions are deterministic');
bloom_test_assert_same([7, 3, 7], bloom_positions('', 8, 3), 'empty string positions');

foreach ($positions as $position) {
	bloom_test_assert_true(is_int($position), 'position is int');
	bloom_test_assert_true($position >= 0, 'position is non-negative');
	bloom_test_assert_true($position < 128, 'position is inside filter size');
}

bloom_test_assert_throws(ValueError::class, 'bits must be greater than 0', static fn () => bloom_positions('hello', 0, 4));
bloom_test_assert_throws(ValueError::class, 'hashes must be greater than 0', static fn () => bloom_positions('hello', 128, 0));
bloom_test_assert_throws(TypeError::class, '', static fn () => bloom_positions([], 128, 4));

echo "done\n";
?>
--EXPECT--
done
