#!/usr/bin/env php
<?php

declare(strict_types=1);

const MYSQL_BENCH_DEFAULT_DATASET = 100000;
const MYSQL_BENCH_DEFAULT_CHECKS = 100000;
const MYSQL_BENCH_DEFAULT_FPR = 0.01;
const MYSQL_BENCH_DEFAULT_MIXES = '99/1,95/5,90/10,50/50';
const MYSQL_BENCH_DEFAULT_WARMUP = 1000;
const MYSQL_BENCH_DEFAULT_HOST = '127.0.0.1';
const MYSQL_BENCH_DEFAULT_PORT = 3306;
const MYSQL_BENCH_DEFAULT_DATABASE = 'php_bloom_bench';
const MYSQL_BENCH_DEFAULT_USER = 'bloom';
const MYSQL_BENCH_DEFAULT_PASSWORD = 'bloom';
const MYSQL_BENCH_DEFAULT_WAIT_TIMEOUT = 60;

exit(main($argv));

/**
 * @param array<int, string> $argv
 */
function main(array $argv): int
{
    try {
        $config = parseBenchConfig($argv);

        if ($config['help']) {
            fwrite(STDOUT, benchUsage());
            return 0;
        }

        assertPdoMysqlAvailable();
        assertBloomFilterAvailable();

        $pdo = connectWithRetry($config);
        $seededRows = fetchSuppressionCount($pdo);
        $lookupStmt = $pdo->prepare('SELECT 1 FROM suppressions WHERE email_hash = ? LIMIT 1');
        ensureDatasetSeeded($lookupStmt, $seededRows, $config['dataset']);

        $filterBuild = buildAndImportFilter($config['dataset'], $config['false_positive_rate']);
        $filter = $filterBuild['filter'];
        $filterInfo = $filterBuild['info'];

        $warmup = warmUpMysql($lookupStmt, $config['warmup'], $config['dataset']);
        $results = [];

        foreach ($config['mixes'] as $mix) {
            $results[] = runMix($mix, $config, $lookupStmt, $filter);
        }

        $payload = [
            'schema' => 'php-bloom-mysql-benchmark-v1',
            'generated_at' => date(DATE_ATOM),
            'config' => [
                'dataset' => $config['dataset'],
                'checks' => $config['checks'],
                'false_positive_rate' => $config['false_positive_rate'],
                'mixes' => $config['mixes'],
                'warmup' => $config['warmup'],
                'mysql' => [
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'database' => $config['database'],
                    'user' => $config['user'],
                ],
            ],
            'environment' => [
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit'),
                'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
                'bloom_extension_loaded' => extension_loaded('bloom'),
                'bloom_filter_class_loaded' => class_exists('Bloom\\Filter', false),
                'bloom_version' => function_exists('bloom_version') ? bloom_version() : null,
                'mysql_server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            ],
            'database' => [
                'seeded_rows' => $seededRows,
            ],
            'filter' => $filterInfo,
            'warmup' => $warmup,
            'results' => $results,
        ];

        if ($config['json']) {
            fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return 0;
        }

        renderConsole($payload);

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }
}

/**
 * @param array<int, string> $argv
 * @return array{
 *     host: string,
 *     port: int,
 *     database: string,
 *     user: string,
 *     password: string,
 *     dataset: int,
 *     checks: int,
 *     false_positive_rate: float,
 *     mixes: array<int, array{key: string, label: string, absent_percent: float, present_percent: float}>,
 *     warmup: int,
 *     wait_timeout: int,
 *     json: bool,
 *     help: bool
 * }
 */
function parseBenchConfig(array $argv): array
{
    $raw = [
        'host' => envString('BLOOM_MYSQL_HOST', MYSQL_BENCH_DEFAULT_HOST),
        'port' => envString('BLOOM_MYSQL_PORT', (string) MYSQL_BENCH_DEFAULT_PORT),
        'database' => envString('BLOOM_MYSQL_DATABASE', MYSQL_BENCH_DEFAULT_DATABASE),
        'user' => envString('BLOOM_MYSQL_USER', MYSQL_BENCH_DEFAULT_USER),
        'password' => envString('BLOOM_MYSQL_PASSWORD', MYSQL_BENCH_DEFAULT_PASSWORD),
        'dataset' => envString('BLOOM_MYSQL_DATASET', (string) MYSQL_BENCH_DEFAULT_DATASET),
        'checks' => envString('BLOOM_MYSQL_CHECKS', (string) MYSQL_BENCH_DEFAULT_CHECKS),
        'false_positive_rate' => envString('BLOOM_MYSQL_FPR', (string) MYSQL_BENCH_DEFAULT_FPR),
        'mixes' => envString('BLOOM_MYSQL_MIXES', MYSQL_BENCH_DEFAULT_MIXES),
        'warmup' => envString('BLOOM_MYSQL_WARMUP', (string) MYSQL_BENCH_DEFAULT_WARMUP),
        'wait_timeout' => envString('BLOOM_MYSQL_WAIT_TIMEOUT', (string) MYSQL_BENCH_DEFAULT_WAIT_TIMEOUT),
        'json' => envString('BLOOM_MYSQL_JSON', '0'),
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

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            throw new InvalidArgumentException("Unknown option: {$arg}" . PHP_EOL . benchUsage());
        }

        [$name, $value] = explode('=', substr($arg, 2), 2);
        $name = strtolower(str_replace('_', '-', $name));

        switch ($name) {
            case 'host':
                $raw['host'] = $value;
                break;
            case 'port':
                $raw['port'] = $value;
                break;
            case 'database':
            case 'db':
                $raw['database'] = $value;
                break;
            case 'user':
                $raw['user'] = $value;
                break;
            case 'password':
                $raw['password'] = $value;
                break;
            case 'dataset':
                $raw['dataset'] = $value;
                break;
            case 'checks':
                $raw['checks'] = $value;
                break;
            case 'false-positive-rate':
            case 'fpr':
                $raw['false_positive_rate'] = $value;
                break;
            case 'mixes':
                $raw['mixes'] = $value;
                break;
            case 'warmup':
                $raw['warmup'] = $value;
                break;
            case 'wait-timeout':
                $raw['wait_timeout'] = $value;
                break;
            case 'json':
                $raw['json'] = $value;
                break;
            default:
                throw new InvalidArgumentException("Unknown option: --{$name}" . PHP_EOL . benchUsage());
        }
    }

    return [
        'host' => parseNonEmptyString('host', $raw['host']),
        'port' => parsePort($raw['port']),
        'database' => parseNonEmptyString('database', $raw['database']),
        'user' => parseNonEmptyString('user', $raw['user']),
        'password' => $raw['password'],
        'dataset' => parsePositiveInt('dataset', $raw['dataset']),
        'checks' => parsePositiveInt('checks', $raw['checks']),
        'false_positive_rate' => parseFalsePositiveRate($raw['false_positive_rate']),
        'mixes' => parseMixes($raw['mixes']),
        'warmup' => parsePositiveInt('warmup', $raw['warmup']),
        'wait_timeout' => parsePositiveInt('wait-timeout', $raw['wait_timeout']),
        'json' => parseBool($raw['json']),
        'help' => (bool) $raw['help'],
    ];
}

function envString(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false ? $default : (string) $value;
}

function assertPdoMysqlAvailable(): void
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException(
            'pdo_mysql is not available. Install or enable the PDO MySQL extension before running the MySQL benchmark.'
        );
    }
}

function assertBloomFilterAvailable(): void
{
    if (!class_exists('Bloom\\Filter', false)) {
        throw new RuntimeException(
            'Bloom\\Filter is not available, so Bloom benchmark scenarios cannot run.' . PHP_EOL
            . 'Build and load the extension first:' . PHP_EOL
            . '  scripts/build.sh' . PHP_EOL
            . '  php -d extension=modules/bloom.so benchmarks/mysql/bench.php'
        );
    }
}

/**
 * @param array{host: string, port: int, database: string, user: string, password: string, wait_timeout: int} $config
 */
function connectWithRetry(array $config): PDO
{
    $deadline = microtime(true) + $config['wait_timeout'];
    $lastError = null;

    do {
        try {
            return connectPdo($config);
        } catch (PDOException $e) {
            $lastError = $e;
            usleep(250000);
        }
    } while (microtime(true) < $deadline);

    $message = sprintf(
        'could not connect to MySQL at %s:%d/%s within %d seconds',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['wait_timeout']
    );

    if ($lastError !== null) {
        $message .= '. Last error: ' . $lastError->getMessage();
    }

    throw new RuntimeException($message);
}

/**
 * @param array{host: string, port: int, database: string, user: string, password: string} $config
 */
function connectPdo(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function fetchSuppressionCount(PDO $pdo): int
{
    try {
        $count = $pdo->query('SELECT COUNT(*) FROM suppressions')->fetchColumn();
    } catch (PDOException $e) {
        throw new RuntimeException(
            'could not read the suppressions table. Run: php benchmarks/mysql/seed.php. ' . $e->getMessage(),
            0,
            $e
        );
    }

    return (int) $count;
}

function ensureDatasetSeeded(PDOStatement $lookupStmt, int $seededRows, int $dataset): void
{
    if ($seededRows < $dataset) {
        throw new RuntimeException(
            sprintf(
                'suppressions contains %s rows, but the benchmark dataset is %s. Run: php benchmarks/mysql/seed.php --dataset=%d',
                formatInteger($seededRows),
                formatInteger($dataset),
                $dataset
            )
        );
    }

    $sampleIndexes = array_values(array_unique([0, intdiv($dataset, 2), $dataset - 1]));

    foreach ($sampleIndexes as $index) {
        if (!lookupSuppression($lookupStmt, presentHash($index))) {
            throw new RuntimeException(
                sprintf(
                    'suppressions does not contain expected deterministic hash present:%d. Reseed with: php benchmarks/mysql/seed.php --dataset=%d',
                    $index,
                    $dataset
                )
            );
        }
    }
}

/**
 * @return array{filter: object, info: array<string, mixed>}
 */
function buildAndImportFilter(int $dataset, float $falsePositiveRate): array
{
    gc_collect_cycles();

    $buildMemoryBefore = memory_get_usage();
    $buildRealMemoryBefore = memory_get_usage(true);
    $buildStart = hrtime(true);
    $filter = new Bloom\Filter($dataset, $falsePositiveRate);

    for ($i = 0; $i < $dataset; $i++) {
        $filter->add(presentHash($i));
    }

    $buildElapsedNs = hrtime(true) - $buildStart;
    $buildMemoryDelta = memory_get_usage() - $buildMemoryBefore;
    $buildRealMemoryDelta = memory_get_usage(true) - $buildRealMemoryBefore;

    $exportStart = hrtime(true);
    $exported = $filter->export();
    $exportElapsedNs = hrtime(true) - $exportStart;

    gc_collect_cycles();

    $importMemoryBefore = memory_get_usage();
    $importRealMemoryBefore = memory_get_usage(true);
    $importStart = hrtime(true);
    $imported = Bloom\Filter::import($exported);
    $importElapsedNs = hrtime(true) - $importStart;
    $importMemoryDelta = memory_get_usage() - $importMemoryBefore;
    $importRealMemoryDelta = memory_get_usage(true) - $importRealMemoryBefore;
    $stats = $imported->stats();

    return [
        'filter' => $imported,
        'info' => [
            'capacity' => $imported->capacity(),
            'bits' => $imported->bits(),
            'hashes' => $imported->hashes(),
            'bytes' => $imported->bytes(),
            'set_bits' => $imported->setBits(),
            'fill_ratio' => $imported->fillRatio(),
            'estimated_false_positive_rate' => $imported->estimatedFalsePositiveRate(),
            'build_elapsed_ns' => $buildElapsedNs,
            'build_elapsed_seconds' => $buildElapsedNs / 1_000_000_000,
            'build_memory_delta_bytes' => $buildMemoryDelta,
            'build_real_memory_delta_bytes' => $buildRealMemoryDelta,
            'export_elapsed_ns' => $exportElapsedNs,
            'export_elapsed_seconds' => $exportElapsedNs / 1_000_000_000,
            'exported_size_bytes' => strlen($exported),
            'import_elapsed_ns' => $importElapsedNs,
            'import_elapsed_seconds' => $importElapsedNs / 1_000_000_000,
            'import_memory_delta_bytes' => $importMemoryDelta,
            'import_real_memory_delta_bytes' => $importRealMemoryDelta,
            'stats' => $stats,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function warmUpMysql(PDOStatement $lookupStmt, int $iterations, int $dataset): array
{
    $mysqlTimeNs = 0;
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $hash = $i % 2 === 0 ? presentHash($i % $dataset) : absentHash($i);
        $queryStart = hrtime(true);
        lookupSuppression($lookupStmt, $hash);
        $mysqlTimeNs += hrtime(true) - $queryStart;
    }

    $elapsedNs = hrtime(true) - $start;

    return [
        'queries' => $iterations,
        'elapsed_ns' => $elapsedNs,
        'elapsed_seconds' => $elapsedNs / 1_000_000_000,
        'mysql_time_ns' => $mysqlTimeNs,
        'mysql_time_seconds' => $mysqlTimeNs / 1_000_000_000,
    ];
}

/**
 * @param array{key: string, label: string, absent_percent: float, present_percent: float} $mix
 * @param array{dataset: int, checks: int} $config
 * @return array<string, mixed>
 */
function runMix(array $mix, array $config, PDOStatement $lookupStmt, object $filter): array
{
    $presentChecks = (int) round($config['checks'] * ($mix['present_percent'] / 100.0));
    $presentChecks = max(0, min($config['checks'], $presentChecks));
    $absentChecks = $config['checks'] - $presentChecks;

    return [
        'key' => $mix['key'],
        'label' => $mix['label'],
        'absent_percent' => $mix['absent_percent'],
        'present_percent' => $mix['present_percent'],
        'checks' => $config['checks'],
        'present_checks' => $presentChecks,
        'absent_checks' => $absentChecks,
        'scenarios' => [
            runDirectMysqlScenario($lookupStmt, $config['dataset'], $config['checks'], $presentChecks, $absentChecks),
            runBloomMysqlScenario($lookupStmt, $filter, $config['dataset'], $config['checks'], $presentChecks, $absentChecks),
            runBloomOnlyScenario($filter, $config['dataset'], $config['checks'], $presentChecks, $absentChecks),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function runDirectMysqlScenario(PDOStatement $lookupStmt, int $dataset, int $checks, int $presentChecks, int $absentChecks): array
{
    $metrics = baseMetrics();
    $presentIndex = 0;
    $absentIndex = 0;

    [$start, $memoryBefore, $realMemoryBefore] = startMeasuredScenario();

    for ($i = 0; $i < $checks; $i++) {
        $expectedPresent = slotIsPresent($i, $presentChecks, $checks);

        if ($expectedPresent) {
            $hash = presentHash($presentIndex % $dataset);
            $presentIndex++;
        } else {
            $hash = absentHash($absentIndex);
            $absentIndex++;
        }

        $queryStart = hrtime(true);
        $found = lookupSuppression($lookupStmt, $hash);
        $metrics['mysql_time_ns'] += hrtime(true) - $queryStart;
        $metrics['mysql_queries']++;

        recordFinalResult($metrics, $expectedPresent, $found);
    }

    return finishMeasuredScenario(
        'direct_mysql',
        'Direct MySQL',
        $checks,
        $presentChecks,
        $absentChecks,
        $start,
        $memoryBefore,
        $realMemoryBefore,
        $metrics
    );
}

/**
 * @return array<string, mixed>
 */
function runBloomMysqlScenario(PDOStatement $lookupStmt, object $filter, int $dataset, int $checks, int $presentChecks, int $absentChecks): array
{
    $metrics = baseMetrics();
    $metrics['bloom_positive_results'] = 0;
    $metrics['bloom_negative_results'] = 0;
    $metrics['bloom_false_positives'] = 0;
    $presentIndex = 0;
    $absentIndex = 0;

    [$start, $memoryBefore, $realMemoryBefore] = startMeasuredScenario();

    for ($i = 0; $i < $checks; $i++) {
        $expectedPresent = slotIsPresent($i, $presentChecks, $checks);

        if ($expectedPresent) {
            $hash = presentHash($presentIndex % $dataset);
            $presentIndex++;
        } else {
            $hash = absentHash($absentIndex);
            $absentIndex++;
        }

        if (!$filter->mightContain($hash)) {
            $metrics['bloom_negative_results']++;

            if ($expectedPresent) {
                $metrics['false_negatives']++;
            } else {
                $metrics['true_negatives']++;
            }

            continue;
        }

        $metrics['bloom_positive_results']++;

        if (!$expectedPresent) {
            $metrics['bloom_false_positives']++;
            $metrics['false_positives']++;
        }

        $queryStart = hrtime(true);
        $found = lookupSuppression($lookupStmt, $hash);
        $metrics['mysql_time_ns'] += hrtime(true) - $queryStart;
        $metrics['mysql_queries']++;

        if ($found) {
            if ($expectedPresent) {
                $metrics['true_positives']++;
            } else {
                $metrics['incorrect_false_positives']++;
            }
        } elseif ($expectedPresent) {
            $metrics['false_negatives']++;
        } else {
            $metrics['true_negatives']++;
        }
    }

    return finishMeasuredScenario(
        'bloom_mysql',
        'Bloom + MySQL verification',
        $checks,
        $presentChecks,
        $absentChecks,
        $start,
        $memoryBefore,
        $realMemoryBefore,
        $metrics
    );
}

/**
 * @return array<string, mixed>
 */
function runBloomOnlyScenario(object $filter, int $dataset, int $checks, int $presentChecks, int $absentChecks): array
{
    $metrics = baseMetrics();
    $metrics['bloom_positive_results'] = 0;
    $metrics['bloom_negative_results'] = 0;
    $metrics['bloom_false_positives'] = 0;
    $presentIndex = 0;
    $absentIndex = 0;

    [$start, $memoryBefore, $realMemoryBefore] = startMeasuredScenario();

    for ($i = 0; $i < $checks; $i++) {
        $expectedPresent = slotIsPresent($i, $presentChecks, $checks);

        if ($expectedPresent) {
            $hash = presentHash($presentIndex % $dataset);
            $presentIndex++;
        } else {
            $hash = absentHash($absentIndex);
            $absentIndex++;
        }

        if ($filter->mightContain($hash)) {
            $metrics['bloom_positive_results']++;

            if ($expectedPresent) {
                $metrics['true_positives']++;
            } else {
                $metrics['bloom_false_positives']++;
                $metrics['false_positives']++;
                $metrics['incorrect_false_positives']++;
            }
        } else {
            $metrics['bloom_negative_results']++;

            if ($expectedPresent) {
                $metrics['false_negatives']++;
            } else {
                $metrics['true_negatives']++;
            }
        }
    }

    return finishMeasuredScenario(
        'bloom_only',
        'Bloom only',
        $checks,
        $presentChecks,
        $absentChecks,
        $start,
        $memoryBefore,
        $realMemoryBefore,
        $metrics
    );
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function startMeasuredScenario(): array
{
    gc_collect_cycles();

    return [hrtime(true), memory_get_usage(), memory_get_usage(true)];
}

/**
 * @param array<string, mixed> $metrics
 * @return array<string, mixed>
 */
function finishMeasuredScenario(
    string $key,
    string $label,
    int $checks,
    int $presentChecks,
    int $absentChecks,
    int $start,
    int $memoryBefore,
    int $realMemoryBefore,
    array $metrics
): array {
    $elapsedNs = hrtime(true) - $start;
    $seconds = $elapsedNs / 1_000_000_000;
    $metrics['queries_avoided'] = $checks - (int) $metrics['mysql_queries'];

    return [
        'key' => $key,
        'label' => $label,
        'checks' => $checks,
        'present_checks' => $presentChecks,
        'absent_checks' => $absentChecks,
        'elapsed_ns' => $elapsedNs,
        'elapsed_seconds' => $seconds,
        'checks_per_second' => $seconds > 0.0 ? $checks / $seconds : null,
        'memory_delta_bytes' => memory_get_usage() - $memoryBefore,
        'real_memory_delta_bytes' => memory_get_usage(true) - $realMemoryBefore,
        'mysql_queries' => $metrics['mysql_queries'],
        'mysql_time_ns' => $metrics['mysql_time_ns'],
        'mysql_time_seconds' => $metrics['mysql_time_ns'] / 1_000_000_000,
        'mysql_ns_per_query' => $metrics['mysql_queries'] > 0 ? $metrics['mysql_time_ns'] / $metrics['mysql_queries'] : null,
        'queries_avoided' => $metrics['queries_avoided'],
        'false_positives' => $metrics['false_positives'],
        'incorrect_false_positives' => $metrics['incorrect_false_positives'],
        'false_negatives' => $metrics['false_negatives'],
        'true_positives' => $metrics['true_positives'],
        'true_negatives' => $metrics['true_negatives'],
        'bloom_positive_results' => $metrics['bloom_positive_results'],
        'bloom_negative_results' => $metrics['bloom_negative_results'],
        'bloom_false_positives' => $metrics['bloom_false_positives'],
    ];
}

/**
 * @return array<string, mixed>
 */
function baseMetrics(): array
{
    return [
        'mysql_queries' => 0,
        'mysql_time_ns' => 0,
        'queries_avoided' => 0,
        'false_positives' => 0,
        'incorrect_false_positives' => 0,
        'false_negatives' => 0,
        'true_positives' => 0,
        'true_negatives' => 0,
        'bloom_positive_results' => null,
        'bloom_negative_results' => null,
        'bloom_false_positives' => null,
    ];
}

function recordFinalResult(array &$metrics, bool $expectedPresent, bool $returnedPresent): void
{
    if ($returnedPresent) {
        if ($expectedPresent) {
            $metrics['true_positives']++;
        } else {
            $metrics['false_positives']++;
            $metrics['incorrect_false_positives']++;
        }

        return;
    }

    if ($expectedPresent) {
        $metrics['false_negatives']++;
    } else {
        $metrics['true_negatives']++;
    }
}

function lookupSuppression(PDOStatement $lookupStmt, string $hash): bool
{
    $lookupStmt->execute([$hash]);
    $found = $lookupStmt->fetchColumn() !== false;
    $lookupStmt->closeCursor();

    return $found;
}

function slotIsPresent(int $offset, int $presentChecks, int $totalChecks): bool
{
    if ($presentChecks <= 0) {
        return false;
    }

    if ($presentChecks >= $totalChecks) {
        return true;
    }

    return intdiv(($offset + 1) * $presentChecks, $totalChecks) > intdiv($offset * $presentChecks, $totalChecks);
}

function presentHash(int $index): string
{
    return hash('sha256', 'present:' . $index, true);
}

function absentHash(int $index): string
{
    return hash('sha256', 'absent:' . $index, true);
}

/**
 * @return array<int, array{key: string, label: string, absent_percent: float, present_percent: float}>
 */
function parseMixes(string $value): array
{
    $mixes = [];

    foreach (explode(',', $value) as $rawPart) {
        $part = trim($rawPart);

        if ($part === '') {
            continue;
        }

        if (str_contains($part, '/')) {
            [$absentRaw, $presentRaw] = explode('/', $part, 2);
        } elseif (str_contains($part, ':')) {
            [$absentRaw, $presentRaw] = explode(':', $part, 2);
        } else {
            $presentRaw = $part;
            $absentRaw = (string) (100.0 - parsePercent('present percent', $presentRaw));
        }

        $absent = parsePercent('absent percent', trim($absentRaw));
        $present = parsePercent('present percent', trim($presentRaw));

        if (abs(($absent + $present) - 100.0) > 0.000001) {
            throw new InvalidArgumentException("mix {$part} must add up to 100 percent");
        }

        $mixes[] = [
            'key' => percentKey($absent) . '_absent_' . percentKey($present) . '_present',
            'label' => formatPercent($absent) . '% absent / ' . formatPercent($present) . '% present',
            'absent_percent' => $absent,
            'present_percent' => $present,
        ];
    }

    if ($mixes === []) {
        throw new InvalidArgumentException('mixes must contain at least one mix');
    }

    return $mixes;
}

function parsePercent(string $name, string $value): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("{$name} must be numeric");
    }

    $parsed = (float) $value;

    if (!is_finite($parsed) || $parsed < 0.0 || $parsed > 100.0) {
        throw new InvalidArgumentException("{$name} must be between 0 and 100");
    }

    return $parsed;
}

function parseNonEmptyString(string $name, string $value): string
{
    if ($value === '') {
        throw new InvalidArgumentException("{$name} must not be empty");
    }

    return $value;
}

function parsePort(string $value): int
{
    $port = parsePositiveInt('port', $value);

    if ($port > 65535) {
        throw new InvalidArgumentException('port must be between 1 and 65535');
    }

    return $port;
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
 * @param array<string, mixed> $payload
 */
function renderConsole(array $payload): void
{
    fwrite(STDOUT, 'php-bloom MySQL comparison benchmark' . PHP_EOL . PHP_EOL);

    printTable(['Setting', 'Value'], [
        ['PHP', $payload['environment']['php_version'] . ' (' . $payload['environment']['php_sapi'] . ')'],
        ['Bloom extension', $payload['environment']['bloom_extension_loaded'] ? 'loaded' : 'not loaded'],
        ['Bloom version', (string) ($payload['environment']['bloom_version'] ?? 'n/a')],
        ['MySQL server', (string) $payload['environment']['mysql_server_version']],
        ['MySQL target', $payload['config']['mysql']['host'] . ':' . $payload['config']['mysql']['port'] . '/' . $payload['config']['mysql']['database']],
        ['Seeded rows', formatInteger((int) $payload['database']['seeded_rows'])],
        ['Dataset', formatInteger((int) $payload['config']['dataset'])],
        ['Checks per mix', formatInteger((int) $payload['config']['checks'])],
        ['False positive rate', (string) $payload['config']['false_positive_rate']],
        ['Warmup queries', formatInteger((int) $payload['warmup']['queries'])],
        ['Warmup elapsed', formatDuration((int) $payload['warmup']['elapsed_ns'])],
    ]);

    fwrite(STDOUT, PHP_EOL . 'Bloom filter' . PHP_EOL);

    printTable(['Metric', 'Value'], [
        ['Capacity', formatInteger((int) $payload['filter']['capacity'])],
        ['Bits', formatInteger((int) $payload['filter']['bits'])],
        ['Hashes', formatInteger((int) $payload['filter']['hashes'])],
        ['Filter memory', formatBytes((int) $payload['filter']['bytes'])],
        ['Set bits', formatInteger((int) $payload['filter']['set_bits'])],
        ['Fill ratio', formatFloat((float) $payload['filter']['fill_ratio'])],
        ['Estimated FPR', formatFloat((float) $payload['filter']['estimated_false_positive_rate'])],
        ['Build time', formatDuration((int) $payload['filter']['build_elapsed_ns'])],
        ['Import time', formatDuration((int) $payload['filter']['import_elapsed_ns'])],
        ['Export time', formatDuration((int) $payload['filter']['export_elapsed_ns'])],
        ['Exported size', formatBytes((int) $payload['filter']['exported_size_bytes'])],
        ['Build memory delta', formatBytes((int) $payload['filter']['build_memory_delta_bytes'])],
        ['Import memory delta', formatBytes((int) $payload['filter']['import_memory_delta_bytes'])],
    ]);

    foreach ($payload['results'] as $mixResult) {
        fwrite(STDOUT, PHP_EOL . $mixResult['label'] . PHP_EOL);

        $rows = [];

        foreach ($mixResult['scenarios'] as $scenario) {
            $rows[] = [
                $scenario['label'],
                formatInteger((int) $scenario['checks']),
                formatDuration((int) $scenario['elapsed_ns']),
                formatRate($scenario['checks_per_second']),
                formatInteger((int) $scenario['mysql_queries']),
                formatDuration((int) $scenario['mysql_time_ns']),
                formatInteger((int) $scenario['queries_avoided']),
                formatInteger((int) $scenario['false_positives']),
                formatInteger((int) $scenario['incorrect_false_positives']),
                formatInteger((int) $scenario['true_positives']),
                formatInteger((int) $scenario['true_negatives']),
            ];
        }

        printTable(
            ['Path', 'Checks', 'Elapsed', 'Checks/sec', 'MySQL q', 'MySQL time', 'Avoided', 'False pos', 'Incorrect pos', 'True pos', 'True neg'],
            $rows
        );
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

function formatFloat(float $value): string
{
    return rtrim(rtrim(sprintf('%.8f', $value), '0'), '.');
}

function formatPercent(float $value): string
{
    if (abs($value - round($value)) < 0.000001) {
        return number_format($value, 0, '.', '');
    }

    return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
}

function percentKey(float $value): string
{
    return str_replace('.', '_', formatPercent($value));
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

function benchUsage(): string
{
    return <<<'TXT'
Usage:
  php -d extension=modules/bloom.so benchmarks/mysql/bench.php [options]

Options:
  --dataset=N                 Seeded suppression hashes to model. Default: 100000
  --checks=N                  Lookups per scenario and mix. Default: 100000
  --fpr=RATE                  Bloom false positive rate. Default: 0.01
  --false-positive-rate=RATE  Alias for --fpr
  --mixes=LIST                Comma-separated absent/present percentages. Default: 99/1,95/5,90/10,50/50
  --warmup=N                  MySQL lookups to run before measuring. Default: 1000
  --host=HOST                 MySQL host. Default: 127.0.0.1
  --port=PORT                 MySQL port. Default: 3306
  --database=NAME             MySQL database. Default: php_bloom_bench
  --user=USER                 MySQL user. Default: bloom
  --password=PASSWORD         MySQL password. Default: bloom
  --wait-timeout=N            Seconds to wait for MySQL readiness. Default: 60
  --json                      Emit JSON instead of console tables
  --help                      Show this help

Environment variables:
  BLOOM_MYSQL_DATASET
  BLOOM_MYSQL_CHECKS
  BLOOM_MYSQL_FPR
  BLOOM_MYSQL_MIXES
  BLOOM_MYSQL_WARMUP
  BLOOM_MYSQL_HOST
  BLOOM_MYSQL_PORT
  BLOOM_MYSQL_DATABASE
  BLOOM_MYSQL_USER
  BLOOM_MYSQL_PASSWORD
  BLOOM_MYSQL_WAIT_TIMEOUT
  BLOOM_MYSQL_JSON

Mix examples:
  --mixes=99/1,95/5,90/10,50/50
  --mixes=1,5,10,50

TXT;
}
