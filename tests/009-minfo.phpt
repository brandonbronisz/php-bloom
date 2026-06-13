--TEST--
bloom phpinfo output reports support and version
--SKIPIF--
<?php if (!extension_loaded('bloom')) die('skip bloom extension not loaded'); ?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();

bloom_test_assert_true(is_string($info), 'phpinfo output is captured');
bloom_test_assert_true(str_contains($info, 'bloom support'), 'phpinfo includes support row');
bloom_test_assert_true(str_contains($info, 'enabled'), 'phpinfo reports enabled support');
bloom_test_assert_true(str_contains($info, 'version'), 'phpinfo includes version row');
bloom_test_assert_true(str_contains($info, bloom_version()), 'phpinfo reports extension version');

echo "done\n";
?>
--EXPECT--
done
