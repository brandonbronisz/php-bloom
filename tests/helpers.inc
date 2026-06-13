<?php

declare(strict_types=1);

function bloom_test_assert_true(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException("Assertion failed: {$message}");
	}
}

function bloom_test_assert_same(mixed $expected, mixed $actual, string $message): void
{
	if ($expected !== $actual) {
		throw new RuntimeException(sprintf(
			"Assertion failed: %s\nExpected: %s\nActual: %s",
			$message,
			var_export($expected, true),
			var_export($actual, true)
		));
	}
}

function bloom_test_assert_approx(float $expected, float $actual, float $epsilon, string $message): void
{
	if (abs($expected - $actual) > $epsilon) {
		throw new RuntimeException(sprintf(
			"Assertion failed: %s\nExpected: %.17g\nActual: %.17g",
			$message,
			$expected,
			$actual
		));
	}
}

function bloom_test_assert_throws(string $expectedClass, string $expectedMessage, callable $callback): void
{
	try {
		$callback();
	} catch (Throwable $e) {
		if (!$e instanceof $expectedClass) {
			throw new RuntimeException(sprintf(
				"Expected %s, got %s: %s",
				$expectedClass,
				$e::class,
				$e->getMessage()
			));
		}

		if ($expectedMessage !== '' && $e->getMessage() !== $expectedMessage) {
			throw new RuntimeException(sprintf(
				"Expected exception message %s, got %s",
				var_export($expectedMessage, true),
				var_export($e->getMessage(), true)
			));
		}

		return;
	}

	throw new RuntimeException("Expected {$expectedClass} to be thrown");
}

function bloom_test_read_u64_le(string $bytes, int $offset): int
{
	$value = 0;

	for ($i = 0; $i < 8; $i++) {
		$value += ord($bytes[$offset + $i]) << ($i * 8);
	}

	return $value;
}
