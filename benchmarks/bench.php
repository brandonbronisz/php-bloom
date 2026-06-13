#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/PurePhpBloomFilter.php';

const BENCHMARK_DEFAULT_CAPACITY = 100000;
const BENCHMARK_DEFAULT_FPR = 0.01;
const BENCHMARK_DEFAULT_INSERTS = 100000;
const BENCHMARK_DEFAULT_LOOKUPS = 100000;

exit(main($argv));

/**
 * @param array<int, string> $argv
 */
function main(array $argv): int
{
    try {
        $config = parseConfig($argv);

        if ($config['help']) {
            fwrite(STDOUT, usage());
            return 0;
        }

        $implementations = resolveImplementations($config['implementation']);

        $insertValues = generateValues('insert', $config['inserts']);
        $presentValues = generatePresentValues($insertValues, $config['lookups']);
        $absentValues = generateValues('absent', $config['lookups']);

        $payload = [
            'schema' => 'php-bloom-benchmark-v1',
            'generated_at' => date(DATE_ATOM),
            'config' => [
                'capacity' => $config['capacity'],
                'false_positive_rate' => $config['false_positive_rate'],
                'inserts' => $config['inserts'],
                'lookups' => $config['lookups'],
                'implementation' => $config['implementation'],
            ],
            'environment' => [
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit'),
                'bloom_extension_loaded' => extension_loaded('bloom'),
                'bloom_filter_class_loaded' => class_exists('Bloom\\Filter', false),
                'bloom_version' => function_exists('bloom_version') ? bloom_version() : null,
            ],
            'results' => [],
            'comparisons' => [],
        ];

        foreach ($implementations as $implementation) {
            $payload['results'][] = runImplementation(
                $implementation,
                $config,
                $insertValues,
                $presentValues,
                $absentValues
            );
        }

        $payload['comparisons'] = buildComparisons($payload['results']);

        if ($config['json']) {
            fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return 0;
        }

        renderConsole($payload);

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        return 1;
    }
}

/**
 * @param array<int, string> $argv
 * @return array{
 *     capacity: int,
 *     false_positive_rate: float,
 *     inserts: int,
 *     lookups: int,
 *     implementation: string,
 *     json: bool,
 *     help: bool
 * }
 */
function parseConfig(array $argv): array
{
    $raw = [
        'capacity' => getenv('BLOOM_BENCH_CAPACITY') !== false ? (string) getenv('BLOOM_BENCH_CAPACITY') : (string) BENCHMARK_DEFAULT_CAPACITY,
        'false_positive_rate' => getenv('BLOOM_BENCH_FPR') !== false ? (string) getenv('BLOOM_BENCH_FPR') : (string) BENCHMARK_DEFAULT_FPR,
        'inserts' => getenv('BLOOM_BENCH_INSERTS') !== false ? (string) getenv('BLOOM_BENCH_INSERTS') : (string) BENCHMARK_DEFAULT_INSERTS,
        'lookups' => getenv('BLOOM_BENCH_LOOKUPS') !== false ? (string) getenv('BLOOM_BENCH_LOOKUPS') : (string) BENCHMARK_DEFAULT_LOOKUPS,
        'implementation' => getenv('BLOOM_BENCH_IMPL') !== false ? strtolower((string) getenv('BLOOM_BENCH_IMPL')) : 'both',
        'json' => getenv('BLOOM_BENCH_JSON') !== false ? (string) getenv('BLOOM_BENCH_JSON') : '0',
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $raw['help'] = true;
            continue;
        }

        if ($arg === '--json') {
            $raw['json'] = '1';
            continue;
        }

        if ($arg === '--no-json') {
            $raw['json'] = '0';
            continue;
        }

        if ($arg === '--pure-only') {
            $raw['implementation'] = 'pure';
            continue;
        }

        if ($arg === '--extension-only') {
            $raw['implementation'] = 'extension';
            continue;
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            throw new InvalidArgumentException("Unknown option: {$arg}" . PHP_EOL . usage());
        }

        [$name, $value] = explode('=', substr($arg, 2), 2);
        $name = strtolower(str_replace('_', '-', $name));

        switch ($name) {
            case 'capacity':
                $raw['capacity'] = $value;
                break;
            case 'false-positive-rate':
            case 'fpr':
                $raw['false_positive_rate'] = $value;
                break;
            case 'inserts':
                $raw['inserts'] = $value;
                break;
            case 'lookups':
                $raw['lookups'] = $value;
                break;
            case 'impl':
            case 'implementation':
                $raw['implementation'] = strtolower($value);
                break;
            case 'json':
                $raw['json'] = $value;
                break;
            default:
                throw new InvalidArgumentException("Unknown option: --{$name}" . PHP_EOL . usage());
        }
    }

    $implementation = strtolower((string) $raw['implementation']);

    if (!in_array($implementation, ['both', 'extension', 'pure'], true)) {
        throw new InvalidArgumentException('implementation must be one of: both, extension, pure');
    }

    return [
        'capacity' => parsePositiveInt('capacity', (string) $raw['capacity']),
        'false_positive_rate' => parseFalsePositiveRate((string) $raw['false_positive_rate']),
        'inserts' => parsePositiveInt('inserts', (string) $raw['inserts']),
        'lookups' => parsePositiveInt('lookups', (string) $raw['lookups']),
        'implementation' => $implementation,
        'json' => parseBool((string) $raw['json']),
        'help' => (bool) $raw['help'],
    ];
}

function parsePositiveInt(string $name, string $value): int
{
    if (!preg_match('/^[1-9][0-9]*$/', $value)) {
        throw new InvalidArgumentException("{$name} must be a positive integer");
    }

    $parsed = (int) $value;

    if ((string) $parsed !== $value) {
        throw new InvalidArgumentException("{$name} is too large for this PHP build");
    }

    return $parsed;
}

function parseFalsePositiveRate(string $value): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('false positive rate must be numeric');
    }

    $parsed = (float) $value;

    if (!is_finite($parsed) || $parsed <= 0.0 || $parsed >= 1.0) {
        throw new InvalidArgumentException('false positive rate must be greater than 0 and less than 1');
    }

    return $parsed;
}

function parseBool(string $value): bool
{
    $normalized = strtolower(trim($value));

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
        return false;
    }

    throw new InvalidArgumentException('boolean values must be one of: 1, 0, true, false, yes, no, on, off');
}

/**
 * @return array<int, array{key: string, label: string, class: class-string}>
 */
function resolveImplementations(string $requested): array
{
    $needsExtension = $requested === 'both' || $requested === 'extension';

    if ($needsExtension && !class_exists('Bloom\\Filter', false)) {
        throw new RuntimeException(
            'Bloom\\Filter is not loaded, so extension benchmarks cannot run.' . PHP_EOL
            . 'Build and load the extension first:' . PHP_EOL
            . '  scripts/build.sh' . PHP_EOL
            . '  php -d extension=modules/bloom.so benchmarks/bench.php' . PHP_EOL
            . 'To run only the pure PHP baseline:' . PHP_EOL
            . '  php benchmarks/bench.php --impl=pure'
        );
    }

    $implementations = [];

    if ($requested === 'both' || $requested === 'extension') {
        $implementations[] = [
            'key' => 'extension',
            'label' => 'C extension',
            'class' => 'Bloom\\Filter',
        ];
    }

    if ($requested === 'both' || $requested === 'pure') {
        $implementations[] = [
            'key' => 'pure_php',
            'label' => 'Pure PHP',
            'class' => PurePhpBloomFilter::class,
        ];
    }

    return $implementations;
}

/**
 * @return array<int, string>
 */
function generateValues(string $prefix, int $count): array
{
    $values = [];

    for ($i = 0; $i < $count; $i++) {
        $values[] = $prefix . ':' . str_pad((string) $i, 12, '0', STR_PAD_LEFT);
    }

    return $values;
}

/**
 * @param array<int, string> $insertValues
 * @return array<int, string>
 */
function generatePresentValues(array $insertValues, int $lookups): array
{
    $insertCount = count($insertValues);

    if ($lookups === $insertCount) {
        return $insertValues;
    }

    $values = [];

    for ($i = 0; $i < $lookups; $i++) {
        $values[] = $insertValues[$i % $insertCount];
    }

    return $values;
}

/**
 * @param array{key: string, label: string, class: class-string} $implementation
 * @param array{capacity: int, false_positive_rate: float, inserts: int, lookups: int} $config
 * @param array<int, string> $insertValues
 * @param array<int, string> $presentValues
 * @param array<int, string> $absentValues
 * @return array<string, mixed>
 */
function runImplementation(array $implementation, array $config, array $insertValues, array $presentValues, array $absentValues): array
{
    $class = $implementation['class'];
    $filter = null;
    $exported = '';
    $imported = null;
    $stats = null;

    $benchmarks = [];

    $construct = measureOperation('construct', 1, static function () use (&$filter, $class, $config): void {
        $filter = new $class($config['capacity'], $config['false_positive_rate']);
    });
    $construct['result'] = [
        'bits' => $filter->bits(),
        'hashes' => $filter->hashes(),
        'bytes' => $filter->bytes(),
    ];
    $benchmarks[] = $construct;

    $add = measureOperation('add', $config['inserts'], static function () use ($filter, $insertValues): void {
        foreach ($insertValues as $value) {
            $filter->add($value);
        }
    });
    $add['result'] = [
        'inserted' => $config['inserts'],
    ];
    $benchmarks[] = $add;

    $presentHits = 0;
    $present = measureOperation('lookup_present', $config['lookups'], static function () use ($filter, $presentValues, &$presentHits): void {
        foreach ($presentValues as $value) {
            if ($filter->mightContain($value)) {
                $presentHits++;
            }
        }
    });
    $present['result'] = [
        'hits' => $presentHits,
        'lookups' => $config['lookups'],
    ];
    $benchmarks[] = $present;

    $absentHits = 0;
    $absent = measureOperation('lookup_absent', $config['lookups'], static function () use ($filter, $absentValues, &$absentHits): void {
        foreach ($absentValues as $value) {
            if ($filter->mightContain($value)) {
                $absentHits++;
            }
        }
    });
    $absent['result'] = [
        'positive_results' => $absentHits,
        'lookups' => $config['lookups'],
    ];
    $benchmarks[] = $absent;

    $export = measureOperation('export', 1, static function () use ($filter, &$exported): void {
        $exported = $filter->export();
    });
    $export['result'] = [
        'exported_size_bytes' => strlen($exported),
    ];
    $benchmarks[] = $export;

    $import = measureOperation('import', 1, static function () use ($class, &$exported, &$imported): void {
        $imported = $class::import($exported);
    });
    $import['result'] = [
        'imported_bytes' => $imported->bytes(),
    ];
    $benchmarks[] = $import;

    if (method_exists($filter, 'stats')) {
        $statsBenchmark = measureOperation('stats', 1, static function () use ($filter, &$stats): void {
            $stats = $filter->stats();
        });
        $statsBenchmark['result'] = [
            'set_bits' => $stats['set_bits'] ?? null,
            'fill_ratio' => $stats['fill_ratio'] ?? null,
            'estimated_false_positive_rate' => $stats['estimated_false_positive_rate'] ?? null,
        ];
        $benchmarks[] = $statsBenchmark;
    }

    return [
        'key' => $implementation['key'],
        'label' => $implementation['label'],
        'class' => $implementation['class'],
        'filter' => [
            'capacity' => $filter->capacity(),
            'bits' => $filter->bits(),
            'hashes' => $filter->hashes(),
            'bytes' => $filter->bytes(),
            'exported_size_bytes' => strlen($exported),
            'stats' => $stats,
        ],
        'benchmarks' => $benchmarks,
    ];
}

/**
 * @return array<string, mixed>
 */
function measureOperation(string $operation, int $operations, callable $callback): array
{
    gc_collect_cycles();

    $memoryBefore = memory_get_usage();
    $realMemoryBefore = memory_get_usage(true);
    $start = hrtime(true);

    $callback();

    $elapsedNs = hrtime(true) - $start;
    $memoryAfter = memory_get_usage();
    $realMemoryAfter = memory_get_usage(true);
    $seconds = $elapsedNs / 1_000_000_000;

    return [
        'operation' => $operation,
        'operations' => $operations,
        'elapsed_ns' => $elapsedNs,
        'elapsed_seconds' => $seconds,
        'ops_per_second' => $seconds > 0.0 ? $operations / $seconds : null,
        'ns_per_operation' => $operations > 0 ? $elapsedNs / $operations : null,
        'memory_delta_bytes' => $memoryAfter - $memoryBefore,
        'real_memory_delta_bytes' => $realMemoryAfter - $realMemoryBefore,
    ];
}

/**
 * @param array<int, array<string, mixed>> $results
 * @return array<int, array<string, mixed>>
 */
function buildComparisons(array $results): array
{
    $byKey = [];

    foreach ($results as $result) {
        $byKey[$result['key']] = $result;
    }

    if (!isset($byKey['extension'], $byKey['pure_php'])) {
        return [];
    }

    $pureByOperation = [];

    foreach ($byKey['pure_php']['benchmarks'] as $benchmark) {
        $pureByOperation[$benchmark['operation']] = $benchmark;
    }

    $comparisons = [];

    foreach ($byKey['extension']['benchmarks'] as $extensionBenchmark) {
        $operation = $extensionBenchmark['operation'];

        if (!isset($pureByOperation[$operation])) {
            continue;
        }

        $extensionNs = $extensionBenchmark['ns_per_operation'];
        $pureNs = $pureByOperation[$operation]['ns_per_operation'];
        $speedup = $extensionNs > 0.0 ? $pureNs / $extensionNs : null;

        $comparisons[] = [
            'operation' => $operation,
            'extension_ns_per_operation' => $extensionNs,
            'pure_php_ns_per_operation' => $pureNs,
            'extension_speedup_vs_pure_php' => $speedup,
            'winner' => $speedup !== null && $speedup >= 1.0 ? 'extension' : 'pure_php',
        ];
    }

    return $comparisons;
}

/**
 * @param array<string, mixed> $payload
 */
function renderConsole(array $payload): void
{
    fwrite(STDOUT, 'php-bloom benchmark' . PHP_EOL . PHP_EOL);

    printTable(['Setting', 'Value'], [
        ['PHP', $payload['environment']['php_version'] . ' (' . $payload['environment']['php_sapi'] . ')'],
        ['Bloom extension', $payload['environment']['bloom_extension_loaded'] ? 'loaded' : 'not loaded'],
        ['Bloom version', (string) ($payload['environment']['bloom_version'] ?? 'n/a')],
        ['Capacity', formatInteger($payload['config']['capacity'])],
        ['False positive rate', (string) $payload['config']['false_positive_rate']],
        ['Inserts', formatInteger($payload['config']['inserts'])],
        ['Lookups', formatInteger($payload['config']['lookups'])],
    ]);

    fwrite(STDOUT, PHP_EOL);

    foreach ($payload['results'] as $result) {
        fwrite(STDOUT, $result['label'] . PHP_EOL);

        $rows = [];

        foreach ($result['benchmarks'] as $benchmark) {
            $rows[] = [
                $benchmark['operation'],
                formatInteger($benchmark['operations']),
                formatDuration((int) $benchmark['elapsed_ns']),
                formatRate($benchmark['ops_per_second']),
                formatDecimal($benchmark['ns_per_operation']),
                formatBytes((int) $benchmark['memory_delta_bytes']),
                formatResult($benchmark['result'] ?? []),
            ];
        }

        printTable(['Operation', 'Ops', 'Time', 'Ops/sec', 'ns/op', 'Mem delta', 'Result'], $rows);
        fwrite(STDOUT, PHP_EOL);
    }

    if ($payload['comparisons'] !== []) {
        fwrite(STDOUT, 'Comparison' . PHP_EOL);

        $rows = [];

        foreach ($payload['comparisons'] as $comparison) {
            $rows[] = [
                $comparison['operation'],
                formatDecimal($comparison['extension_ns_per_operation']),
                formatDecimal($comparison['pure_php_ns_per_operation']),
                formatSpeedup($comparison['extension_speedup_vs_pure_php']),
            ];
        }

        printTable(['Operation', 'C extension ns/op', 'Pure PHP ns/op', 'C extension vs PHP'], $rows);
    }
}

/**
 * @param array<int, string> $headers
 * @param array<int, array<int, string>> $rows
 */
function printTable(array $headers, array $rows): void
{
    $widths = array_map('strlen', $headers);

    foreach ($rows as $row) {
        foreach ($row as $index => $cell) {
            $widths[$index] = max($widths[$index] ?? 0, strlen($cell));
        }
    }

    $line = [];

    foreach ($headers as $index => $header) {
        $line[] = str_pad($header, $widths[$index]);
    }

    fwrite(STDOUT, implode('  ', $line) . PHP_EOL);

    $separator = [];

    foreach ($widths as $width) {
        $separator[] = str_repeat('-', $width);
    }

    fwrite(STDOUT, implode('  ', $separator) . PHP_EOL);

    foreach ($rows as $row) {
        $line = [];

        foreach ($row as $index => $cell) {
            $line[] = str_pad($cell, $widths[$index]);
        }

        fwrite(STDOUT, implode('  ', $line) . PHP_EOL);
    }
}

/**
 * @param array<string, mixed> $result
 */
function formatResult(array $result): string
{
    if ($result === []) {
        return '';
    }

    $parts = [];

    foreach ($result as $key => $value) {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.8f', $value), '0'), '.');
        } elseif (is_int($value)) {
            $value = formatInteger($value);
        } elseif ($value === null) {
            $value = 'n/a';
        } else {
            $value = (string) $value;
        }

        $parts[] = $key . '=' . $value;
    }

    return implode(', ', $parts);
}

function formatInteger(int $value): string
{
    return number_format($value, 0, '.', ',');
}

function formatDuration(int $nanoseconds): string
{
    if ($nanoseconds >= 1_000_000_000) {
        return number_format($nanoseconds / 1_000_000_000, 3) . ' s';
    }

    if ($nanoseconds >= 1_000_000) {
        return number_format($nanoseconds / 1_000_000, 3) . ' ms';
    }

    if ($nanoseconds >= 1_000) {
        return number_format($nanoseconds / 1_000, 3) . ' us';
    }

    return $nanoseconds . ' ns';
}

function formatRate(?float $rate): string
{
    if ($rate === null) {
        return 'n/a';
    }

    return number_format($rate, 2);
}

function formatDecimal(?float $value): string
{
    if ($value === null) {
        return 'n/a';
    }

    return number_format($value, 1);
}

function formatBytes(int $bytes): string
{
    $sign = $bytes < 0 ? '-' : '';
    $absolute = abs($bytes);
    $units = ['B', 'KiB', 'MiB', 'GiB'];
    $unitIndex = 0;
    $value = (float) $absolute;

    while ($value >= 1024.0 && $unitIndex < count($units) - 1) {
        $value /= 1024.0;
        $unitIndex++;
    }

    if ($unitIndex === 0) {
        return $sign . number_format($value, 0) . ' ' . $units[$unitIndex];
    }

    return $sign . number_format($value, 2) . ' ' . $units[$unitIndex];
}

function formatSpeedup(?float $speedup): string
{
    if ($speedup === null || $speedup <= 0.0) {
        return 'n/a';
    }

    if ($speedup >= 1.0) {
        return number_format($speedup, 2) . 'x faster';
    }

    return number_format(1.0 / $speedup, 2) . 'x slower';
}

function usage(): string
{
    return <<<'TXT'
Usage:
  php -d extension=modules/bloom.so benchmarks/bench.php [options]
  php benchmarks/bench.php --impl=pure [options]

Options:
  --capacity=N                 Filter capacity. Default: 100000
  --fpr=RATE                   False positive rate. Default: 0.01
  --false-positive-rate=RATE   Alias for --fpr
  --inserts=N                  Values to add. Default: 100000
  --lookups=N                  Present and absent lookup count. Default: 100000
  --impl=both|extension|pure   Implementation set. Default: both
  --json                       Emit JSON instead of console tables
  --pure-only                  Alias for --impl=pure
  --extension-only             Alias for --impl=extension
  --help                       Show this help

Environment variables:
  BLOOM_BENCH_CAPACITY
  BLOOM_BENCH_FPR
  BLOOM_BENCH_INSERTS
  BLOOM_BENCH_LOOKUPS
  BLOOM_BENCH_IMPL
  BLOOM_BENCH_JSON

TXT;
}
