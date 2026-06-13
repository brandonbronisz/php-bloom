--TEST--
Bloom\Filter export and import preserve data and reject malformed payloads
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

$filter = new Bloom\Filter(10, 0.1);
$filter->add('alpha');
$filter->add("abc\0def");

$data = $filter->export();

bloom_test_assert_same(42, strlen($data), 'exported length');
bloom_test_assert_same('BLM1', substr($data, 0, 4), 'export magic');
bloom_test_assert_same(10, bloom_test_read_u64_le($data, 4), 'export capacity');
bloom_test_assert_same(48, bloom_test_read_u64_le($data, 12), 'export bits');
bloom_test_assert_same(4, bloom_test_read_u64_le($data, 20), 'export hashes');
bloom_test_assert_same(6, bloom_test_read_u64_le($data, 28), 'export byte count');

$imported = Bloom\Filter::import($data);

bloom_test_assert_true($imported instanceof Bloom\Filter, 'import returns filter');
bloom_test_assert_same($filter->capacity(), $imported->capacity(), 'capacity survives import');
bloom_test_assert_same($filter->bits(), $imported->bits(), 'bits survive import');
bloom_test_assert_same($filter->hashes(), $imported->hashes(), 'hashes survive import');
bloom_test_assert_same($filter->bytes(), $imported->bytes(), 'byte count survives import');
bloom_test_assert_same($filter->setBits(), $imported->setBits(), 'set bits survive import');
bloom_test_assert_same(true, $imported->mightContain('alpha'), 'string membership survives import');
bloom_test_assert_same(true, $imported->mightContain("abc\0def"), 'binary membership survives import');
bloom_test_assert_same(false, $imported->mightContain('absent'), 'absent value remains absent');
bloom_test_assert_same($data, $imported->export(), 'round-tripped export is stable');

bloom_test_assert_throws(ValueError::class, 'invalid bloom filter data', static fn () => Bloom\Filter::import('nope'));

$badMagic = $data;
$badMagic[0] = 'X';
bloom_test_assert_throws(ValueError::class, 'invalid bloom filter data', static fn () => Bloom\Filter::import($badMagic));

$zeroCapacity = $data;
$zeroCapacity[4] = "\0";
bloom_test_assert_throws(ValueError::class, 'invalid bloom filter data', static fn () => Bloom\Filter::import($zeroCapacity));

$badByteCount = $data;
$badByteCount[28] = "\xff";
bloom_test_assert_throws(ValueError::class, 'invalid bloom filter data', static fn () => Bloom\Filter::import($badByteCount));

bloom_test_assert_throws(ValueError::class, 'invalid bloom filter data', static fn () => Bloom\Filter::import($data . 'x'));
bloom_test_assert_throws(ValueError::class, 'invalid bloom filter data', static fn () => Bloom\Filter::import(substr($data, 0, -1)));
bloom_test_assert_throws(TypeError::class, '', static fn () => Bloom\Filter::import([]));

echo "done\n";
?>
--EXPECT--
done
