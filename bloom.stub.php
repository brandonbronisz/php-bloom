<?php

/** @generate-class-entries */

namespace {
    /**
     * Returns the loaded php-bloom extension version.
     */
    function bloom_version(): string {}

    /**
     * Hashes a binary-safe string with the extension's FNV-1a implementation.
     */
    function bloom_hash(string $value, int $seed = 0): int {}

    /**
     * Calculates the optimal number of bits for a Bloom filter.
     *
     * @throws \ValueError if $capacity is not greater than 0, if
     *     $falsePositiveRate is not greater than 0 and less than 1, or if the
     *     calculated bit size is too large.
     */
    function bloom_optimal_bits(int $capacity, float $falsePositiveRate): int {}

    /**
     * Calculates the optimal number of hash rounds for a Bloom filter.
     *
     * @throws \ValueError if $bits or $capacity are not greater than 0, or if
     *     the calculated hash count is too large.
     */
    function bloom_optimal_hashes(int $bits, int $capacity): int {}

    /**
     * Returns the bit positions a value maps to for a filter shape.
     *
     * @return list<int>
     *
     * @throws \ValueError if $bits or $hashes are not greater than 0.
     */
    function bloom_positions(string $value, int $bits, int $hashes): array {}
}

namespace Bloom {
    /**
     * Binary-safe Bloom filter for fast probabilistic membership checks.
     *
     * A negative result from mightContain() means the value is definitely
     * absent. A positive result means the value might be present and should be
     * verified against an authoritative store when false positives matter.
     */
    final class Filter
    {
        /**
         * Creates an empty Bloom filter sized for the expected capacity and
         * target false-positive rate.
         *
         * @throws \ValueError if $capacity is not greater than 0, if
         *     $falsePositiveRate is not greater than 0 and less than 1, or if
         *     the calculated bit size is too large.
         */
        public function __construct(int $capacity, float $falsePositiveRate = 0.01) {}

        /**
         * Returns the expected number of values the filter was sized for.
         */
        public function capacity(): int {}

        /**
         * Returns the number of addressable bits in the filter.
         */
        public function bits(): int {}

        /**
         * Returns the number of hash rounds used per value.
         */
        public function hashes(): int {}

        /**
         * Returns the number of bytes used by the filter bitset.
         */
        public function bytes(): int {}

        /**
         * Adds a binary-safe value to the filter.
         */
        public function add(string $value): void {}

        /**
         * Checks whether a binary-safe value might be present in the filter.
         *
         * A false return value means the value is definitely absent. A true
         * return value means the value might be present.
         */
        public function mightContain(string $value): bool {}

        /**
         * Exports the filter to a compact binary string.
         *
         * The export format starts with the "BLM1" magic header.
         */
        public function export(): string {}

        /**
         * Imports a filter previously produced by export().
         *
         * @throws \ValueError if $data is not a valid php-bloom export.
         */
        public static function import(string $data): Filter {}

        /**
         * Returns the number of set bits in the filter.
         */
        public function setBits(): int {}

        /**
         * Returns the ratio of set bits to total bits.
         */
        public function fillRatio(): float {}

        /**
         * Returns the estimated false-positive rate for the current fill ratio.
         */
        public function estimatedFalsePositiveRate(): float {}

        /**
         * Returns filter sizing and fill statistics.
         *
         * @return array{
         *     capacity: int,
         *     bits: int,
         *     hashes: int,
         *     bytes: int,
         *     set_bits: int,
         *     fill_ratio: float,
         *     estimated_false_positive_rate: float
         * }
         */
        public function stats(): array {}
    }
}
