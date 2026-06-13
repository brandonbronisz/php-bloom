<?php

/** @generate-class-entries */

namespace {
    function bloom_version(): string {}

    function bloom_hash(string $value, int $seed = 0): int {}

    function bloom_optimal_bits(int $capacity, float $falsePositiveRate): int {}

    function bloom_optimal_hashes(int $bits, int $capacity): int {}

    function bloom_positions(string $value, int $bits, int $hashes): array {}
}

namespace Bloom {
    final class Filter
    {
        public function __construct(int $capacity, float $falsePositiveRate = 0.01) {}

        public function capacity(): int {}

        public function bits(): int {}

        public function hashes(): int {}

        public function bytes(): int {}

        public function add(string $value): void {}

        public function mightContain(string $value): bool {}

        public function export(): string {}

        public static function import(string $data): Filter {}

        public function setBits(): int {}

        public function fillRatio(): float {}

        public function estimatedFalsePositiveRate(): float {}

        public function stats(): array {}
    }
}