# php-bloom

[![Build and Test](https://github.com/brandonbronisz/php-bloom/actions/workflows/build-and-test.yml/badge.svg)](https://github.com/brandonbronisz/php-bloom/actions/workflows/build-and-test.yml)

`php-bloom` is a native PHP 8 extension that provides a compact Bloom filter
implementation for fast probabilistic membership checks.

Bloom filters answer one question efficiently: "have I probably seen this
value before?" A negative result means the value is definitely absent. A positive
result means the value might be present and should be verified against an
authoritative store when false positives matter.

## Requirements

- PHP `>=8.0 <9.0`
- PIE, for package-based installation
- Unix-like builds: `phpize`, `php-config`, a C compiler, and `make`
- Windows builds: PHP SDK tooling through `php/php-windows-builder` or an
  equivalent PHP Windows build environment
- Docker, only for the MySQL benchmark

## Install With PIE

```sh
pie install brandonbronisz/php-bloom
```

PIE builds and enables the extension for the target PHP installation. On
Unix-like systems, make sure the PHP development tools and compiler toolchain
are installed first. On Windows, PIE installs from the pre-built DLL archives
published on GitHub releases.

Verify the extension is loaded:

```sh
php -r 'var_dump(extension_loaded("bloom"), bloom_version());'
```

## IDE Stubs

Install the optional stubs package in projects that use php-bloom to get IDE
completion, parameter hints, return types, and inline docs without requiring the
extension to be loaded in the IDE environment:

```sh
composer require --dev brandonbronisz/php-bloom-stubs
```

## Build

```sh
scripts/build.sh
```

Load the built module directly:

```sh
php -d extension="$(pwd)/modules/bloom.so" -r 'var_dump(bloom_version());'
```

Run the PHPT suite:

```sh
scripts/test.sh
```

Build and test the extension on Windows:

```powershell
Install-Module -Name BuildPhpExtension -Repository PSGallery -Force -Scope CurrentUser
Invoke-PhpBuildExtension -PhpVersion 8.4 -Arch x64 -Ts nts -Args "--enable-bloom"
```

## Quick Start

```php
<?php

$filter = new Bloom\Filter(100000, 0.01);

$filter->add('user@example.com');

if ($filter->mightContain('user@example.com')) {
    // The value might be present.
}

if (!$filter->mightContain('other@example.com')) {
    // The value is definitely absent.
}
```

For correctness-sensitive paths, treat positive results as a pre-check and then
verify them against the real source of truth:

```php
<?php

if (!$filter->mightContain($emailHash)) {
    return false;
}

return $database->containsSuppression($emailHash);
```

## API

### Functions

```php
bloom_version(): string
bloom_hash(string $value, int $seed = 0): int
bloom_optimal_bits(int $capacity, float $falsePositiveRate): int
bloom_optimal_hashes(int $bits, int $capacity): int
bloom_positions(string $value, int $bits, int $hashes): array
```

### `Bloom\Filter`

```php
namespace Bloom;

final class Filter
{
    public function __construct(int $capacity, float $falsePositiveRate = 0.01);

    public function capacity(): int;
    public function bits(): int;
    public function hashes(): int;
    public function bytes(): int;

    public function add(string $value): void;
    public function mightContain(string $value): bool;

    public function export(): string;
    public static function import(string $data): Filter;

    public function setBits(): int;
    public function fillRatio(): float;
    public function estimatedFalsePositiveRate(): float;
    public function stats(): array;
}
```

## Export And Import

Filters can be serialized to a compact binary format and loaded later:

```php
<?php

$filter = new Bloom\Filter(100000, 0.01);
$filter->add('value');

file_put_contents('filter.blm', $filter->export());

$loaded = Bloom\Filter::import(file_get_contents('filter.blm'));
```

The export format is binary-safe and starts with the `BLM1` magic header.

## Use Cases

Good fits:

- Suppression lists where most candidates are absent and positives are verified
  in MySQL, Postgres, Redis, or another authoritative store.
- Deny lists and block lists where negative checks should be very cheap.
- Cache penetration protection, where missing keys should avoid expensive
  downstream lookups.
- Pre-filtering large streams before doing heavier processing.
- Fast duplicate detection where occasional false positives are acceptable.
- Shipping a compact, read-mostly membership structure with `export()` and
  `import()`.

## Benchmarks

Benchmark results are workload and machine dependent. The reports below were
captured locally on June 13, 2026 with PHP 8.2.30 and extension version 0.1.0.

### Extension vs Pure PHP

Command:

```sh
php -d extension="$(pwd)/modules/bloom.so" benchmarks/bench.php
```

Configuration:

- capacity: `100000`
- false positive rate: `0.01`
- inserts: `100000`
- present lookups: `100000`
- absent lookups: `100000`

Report:

| Operation | C extension ns/op | Pure PHP ns/op | Result |
| --- | ---: | ---: | ---: |
| construct | 21,041.0 | 35,041.0 | 1.67x faster |
| add | 54.1 | 7,212.8 | 133.24x faster |
| lookup present | 62.4 | 7,184.1 | 115.18x faster |
| lookup absent | 70.2 | 5,865.6 | 83.53x faster |
| export | 7,417.0 | 18,167.0 | 2.45x faster |
| import | 4,167.0 | 519,709.0 | 124.72x faster |
| stats | 649,792.0 | 3,706,667.0 | 5.70x faster |

For this run, the filter used `958,506` bits, `7` hash rounds, and `119,814`
bytes of bitset storage.

### MySQL Suppression Lookup

The MySQL benchmark compares direct indexed lookups against a Bloom pre-check
plus MySQL verification. The Bloom-only path is reported as a raw lookup ceiling,
but it is not correctness-safe because false positives are possible.

Setup:

```sh
MYSQL_PORT=3307 docker compose -f benchmarks/docker-compose.yml up -d
php benchmarks/mysql/seed.php --port=3307
```

Command used for this report:

```sh
php -d extension="$(pwd)/modules/bloom.so" \
  benchmarks/mysql/bench.php --port=3307 --checks=10000 --warmup=100
```

Configuration:

- MySQL: `8.4.9`
- dataset: `100000` deterministic SHA-256 hashes
- checks per mix: `10000`
- false positive rate: `0.01`
- warmup queries: `100`
- filter size: `117.01 KiB`
- estimated false positive rate after build: `0.00721217`

Report:

| Mix | Direct MySQL | Bloom + MySQL verification | MySQL queries avoided | Bloom false positives | Speedup |
| --- | ---: | ---: | ---: | ---: | ---: |
| 99% absent / 1% present | 4.395 s | 153.586 ms | 9,636 | 264 | 28.62x |
| 95% absent / 5% present | 4.740 s | 409.401 ms | 9,245 | 255 | 11.58x |
| 90% absent / 10% present | 5.095 s | 709.813 ms | 8,754 | 246 | 7.18x |
| 50% absent / 50% present | 4.927 s | 3.042 s | 4,856 | 144 | 1.62x |

The benchmark shows the expected shape: the Bloom pre-check helps most when the
traffic is negative-heavy. As the present rate rises, more positive Bloom
results must be verified by MySQL, so the benefit narrows.

More benchmark details are in `benchmarks/README.md` and
`benchmarks/mysql/README.md`.

## Development

Regenerate arginfo after changing `bloom.stub.php`:

```sh
scripts/gen-arginfo.sh
```

Clean generated build output:

```sh
scripts/clean.sh
```

Useful layout:

- `bloom.c`: module entry and procedural functions
- `bloom_algo.c`: shared hashing and position helpers
- `bloom_filter.c`: `Bloom\Filter` object implementation
- `bloom.stub.php`: public API declarations for arginfo generation
- `tests/`: PHPT test suite
- `benchmarks/`: extension and MySQL benchmark suites

## License

MIT. See `LICENSE`.
