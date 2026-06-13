--TEST--
bloom sizing helpers calculate expected values and reject invalid input
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

bloom_test_assert_same(9586, bloom_optimal_bits(1000, 0.01), '1000 item 1% bit size');
bloom_test_assert_same(7, bloom_optimal_hashes(9586, 1000), '1000 item 1% hash count');
bloom_test_assert_same(14378, bloom_optimal_bits(1000, 0.001), '1000 item 0.1% bit size');
bloom_test_assert_same(10, bloom_optimal_hashes(14378, 1000), '1000 item 0.1% hash count');
bloom_test_assert_same(2, bloom_optimal_bits(1, 0.5), 'small filter rounds bit size up');
bloom_test_assert_same(1, bloom_optimal_hashes(1, 100), 'hash count has a floor of one');

bloom_test_assert_throws(ValueError::class, 'capacity must be greater than 0', static fn () => bloom_optimal_bits(0, 0.01));
bloom_test_assert_throws(ValueError::class, 'falsePositiveRate must be greater than 0 and less than 1', static fn () => bloom_optimal_bits(10, 0.0));
bloom_test_assert_throws(ValueError::class, 'falsePositiveRate must be greater than 0 and less than 1', static fn () => bloom_optimal_bits(10, 1.0));
bloom_test_assert_throws(ValueError::class, 'falsePositiveRate must be greater than 0 and less than 1', static fn () => bloom_optimal_bits(10, NAN));
bloom_test_assert_throws(ValueError::class, 'bits must be greater than 0', static fn () => bloom_optimal_hashes(0, 10));
bloom_test_assert_throws(ValueError::class, 'capacity must be greater than 0', static fn () => bloom_optimal_hashes(10, 0));

echo "done\n";
?>
--EXPECT--
done
