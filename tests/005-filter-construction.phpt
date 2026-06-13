--TEST--
Bloom\Filter construction stores metadata and validates sizing input
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

$filter = new Bloom\Filter(100000, 0.01);

bloom_test_assert_same(100000, $filter->capacity(), 'capacity');
bloom_test_assert_same(958506, $filter->bits(), 'bits');
bloom_test_assert_same(7, $filter->hashes(), 'hashes');
bloom_test_assert_same(119814, $filter->bytes(), 'bytes');

$small = new Bloom\Filter(10, 0.1);

bloom_test_assert_same(10, $small->capacity(), 'small capacity');
bloom_test_assert_same(48, $small->bits(), 'small bits');
bloom_test_assert_same(4, $small->hashes(), 'small hashes');
bloom_test_assert_same(6, $small->bytes(), 'small bytes');

$small->add('before-reinit');
$small->__construct(1000, 0.01);

bloom_test_assert_same(1000, $small->capacity(), 'reinitialized capacity');
bloom_test_assert_same(9586, $small->bits(), 'reinitialized bits');
bloom_test_assert_same(7, $small->hashes(), 'reinitialized hashes');
bloom_test_assert_same(0, $small->setBits(), 'reinitialization clears bitset');
bloom_test_assert_same(false, $small->mightContain('before-reinit'), 'reinitialization removes previous membership');

bloom_test_assert_throws(ValueError::class, 'capacity must be greater than 0', static fn () => new Bloom\Filter(0));
bloom_test_assert_throws(ValueError::class, 'falsePositiveRate must be greater than 0 and less than 1', static fn () => new Bloom\Filter(10, 0.0));
bloom_test_assert_throws(ValueError::class, 'falsePositiveRate must be greater than 0 and less than 1', static fn () => new Bloom\Filter(10, 1.0));
bloom_test_assert_throws(ValueError::class, 'falsePositiveRate must be greater than 0 and less than 1', static fn () => new Bloom\Filter(10, NAN));
bloom_test_assert_throws(Error::class, 'Trying to clone an uncloneable object of class Bloom\Filter', static fn () => clone new Bloom\Filter(10));

echo "done\n";
?>
--EXPECT--
done
