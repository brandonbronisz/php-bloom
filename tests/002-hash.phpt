--TEST--
bloom_hash is deterministic and handles binary strings
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

$expectedHashes = PHP_INT_SIZE >= 8
	? [
		'empty' => 3414781078840391647,
		'hello' => 4282283387467632165,
		'seeded_hello' => 7374513309044294956,
		'binary' => 900508179546357362,
	]
	: [
		'empty' => 100775903,
		'hello' => 1771081253,
		'seeded_hello' => 1626981676,
		'binary' => 254918258,
	];

bloom_test_assert_same($expectedHashes['empty'], bloom_hash(''), 'empty string hash');
bloom_test_assert_same($expectedHashes['hello'], bloom_hash('hello'), 'hello hash');
bloom_test_assert_same($expectedHashes['seeded_hello'], bloom_hash('hello', 1), 'seeded hello hash');
bloom_test_assert_same($expectedHashes['binary'], bloom_hash("abc\0def"), 'binary string hash');

bloom_test_assert_same(bloom_hash('hello'), bloom_hash('hello'), 'hash is deterministic');
bloom_test_assert_true(bloom_hash('hello') !== bloom_hash('hello', 1), 'seed changes hash');
bloom_test_assert_true(bloom_hash('hello') >= 0, 'hash is non-negative');
bloom_test_assert_true(bloom_hash('hello') <= PHP_INT_MAX, 'hash fits zend_long');

bloom_test_assert_throws(TypeError::class, '', static fn () => bloom_hash([]));

echo "done\n";
?>
--EXPECT--
done
