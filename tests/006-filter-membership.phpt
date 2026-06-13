--TEST--
Bloom\Filter add and mightContain handle normal, duplicate, empty, and binary values
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

$filter = new Bloom\Filter(1000, 0.01);

bloom_test_assert_same(false, $filter->mightContain('hello'), 'unseen string is absent');
bloom_test_assert_same(false, $filter->mightContain('world'), 'different unseen string is absent');

bloom_test_assert_same(null, $filter->add('hello'), 'add returns null');
bloom_test_assert_same(true, $filter->mightContain('hello'), 'added string might be present');
bloom_test_assert_same(false, $filter->mightContain('world'), 'unadded string remains absent');

$setBitsAfterHello = $filter->setBits();
$filter->add('hello');
bloom_test_assert_same($setBitsAfterHello, $filter->setBits(), 'duplicate add does not set new bits');

$filter->add("abc\0def");
bloom_test_assert_same(true, $filter->mightContain("abc\0def"), 'binary string might be present');
bloom_test_assert_same(false, $filter->mightContain('abc'), 'binary prefix is different');

$filter->add('');
bloom_test_assert_same(true, $filter->mightContain(''), 'empty string might be present');
bloom_test_assert_same(false, $filter->mightContain('not-added'), 'known absent string stays absent');

bloom_test_assert_throws(TypeError::class, '', static fn () => $filter->add([]));
bloom_test_assert_throws(TypeError::class, '', static fn () => $filter->mightContain([]));

echo "done\n";
?>
--EXPECT--
done
