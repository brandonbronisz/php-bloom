#!/usr/bin/env php
<?php

declare(strict_types=1);

const MYSQL_SEED_DEFAULT_DATASET = 100000;
const MYSQL_SEED_DEFAULT_BATCH_SIZE = 1000;
const MYSQL_SEED_DEFAULT_HOST = '127.0.0.1';
const MYSQL_SEED_DEFAULT_PORT = 3306;
const MYSQL_SEED_DEFAULT_DATABASE = 'php_bloom_bench';
const MYSQL_SEED_DEFAULT_USER = 'bloom';
const MYSQL_SEED_DEFAULT_PASSWORD = 'bloom';
const MYSQL_SEED_DEFAULT_WAIT_TIMEOUT = 60;

exit(main($argv));

/**
 * @param array<int, string> $argv
 */
function main(array $argv): int
{
    $startedAt = hrtime(true);

    try {
        $config = parseSeedConfig($argv);

        if ($config['help']) {
            fwrite(STDOUT, seedUsage());
            return 0;
        }

        assertPdoMysqlAvailable();

        $pdo = connectWithRetry($config);
        applySchema($pdo);

        if ($config['truncate']) {
            $pdo->exec('TRUNCATE TABLE suppressions');
        }

        $inserted = seedSuppressions($pdo, $config['dataset'], $config['batch_size']);
        $rowCount = fetchSuppressionCount($pdo);
        $elapsedNs = hrtime(true) - $startedAt;

        renderSeedSummary($config, $inserted, $rowCount, $elapsedNs);

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
 *     batch_size: int,
 *     wait_timeout: int,
 *     truncate: bool,
 *     help: bool
 * }
 */
function parseSeedConfig(array $argv): array
{
    $raw = [
        'host' => envString('BLOOM_MYSQL_HOST', MYSQL_SEED_DEFAULT_HOST),
        'port' => envString('BLOOM_MYSQL_PORT', (string) MYSQL_SEED_DEFAULT_PORT),
        'database' => envString('BLOOM_MYSQL_DATABASE', MYSQL_SEED_DEFAULT_DATABASE),
        'user' => envString('BLOOM_MYSQL_USER', MYSQL_SEED_DEFAULT_USER),
        'password' => envString('BLOOM_MYSQL_PASSWORD', MYSQL_SEED_DEFAULT_PASSWORD),
        'dataset' => envString('BLOOM_MYSQL_DATASET', (string) MYSQL_SEED_DEFAULT_DATASET),
        'batch_size' => envString('BLOOM_MYSQL_BATCH_SIZE', (string) MYSQL_SEED_DEFAULT_BATCH_SIZE),
        'wait_timeout' => envString('BLOOM_MYSQL_WAIT_TIMEOUT', (string) MYSQL_SEED_DEFAULT_WAIT_TIMEOUT),
        'truncate' => envString('BLOOM_MYSQL_TRUNCATE', '1'),
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $raw['help'] = true;
            continue;
        }

        if ($arg === '--truncate') {
            $raw['truncate'] = '1';
            continue;
        }

        if ($arg === '--no-truncate') {
            $raw['truncate'] = '0';
            continue;
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            throw new InvalidArgumentException("Unknown option: {$arg}" . PHP_EOL . seedUsage());
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
            case 'batch-size':
                $raw['batch_size'] = $value;
                break;
            case 'wait-timeout':
                $raw['wait_timeout'] = $value;
                break;
            case 'truncate':
                $raw['truncate'] = $value;
                break;
            default:
                throw new InvalidArgumentException("Unknown option: --{$name}" . PHP_EOL . seedUsage());
        }
    }

    return [
        'host' => parseNonEmptyString('host', $raw['host']),
        'port' => parsePort($raw['port']),
        'database' => parseNonEmptyString('database', $raw['database']),
        'user' => parseNonEmptyString('user', $raw['user']),
        'password' => $raw['password'],
        'dataset' => parsePositiveInt('dataset', $raw['dataset']),
        'batch_size' => parsePositiveInt('batch-size', $raw['batch_size']),
        'wait_timeout' => parsePositiveInt('wait-timeout', $raw['wait_timeout']),
        'truncate' => parseBool($raw['truncate']),
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
            'pdo_mysql is not available. Install or enable the PDO MySQL extension before running the MySQL seed script.'
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

function applySchema(PDO $pdo): void
{
    $schemaPath = __DIR__ . '/schema.sql';
    $schema = file_get_contents($schemaPath);

    if ($schema === false || trim($schema) === '') {
        throw new RuntimeException("could not read schema file: {$schemaPath}");
    }

    $pdo->exec($schema);
}

function seedSuppressions(PDO $pdo, int $dataset, int $batchSize): int
{
    $inserted = 0;
    $pdo->beginTransaction();

    try {
        for ($offset = 0; $offset < $dataset; $offset += $batchSize) {
            $count = min($batchSize, $dataset - $offset);
            $placeholders = implode(',', array_fill(0, $count, '(?)'));
            $stmt = $pdo->prepare("INSERT IGNORE INTO suppressions (email_hash) VALUES {$placeholders}");
            $values = [];

            for ($i = 0; $i < $count; $i++) {
                $values[] = presentHash($offset + $i);
            }

            $stmt->execute($values);
            $inserted += $stmt->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $inserted;
}

function fetchSuppressionCount(PDO $pdo): int
{
    $count = $pdo->query('SELECT COUNT(*) FROM suppressions')->fetchColumn();

    return (int) $count;
}

function presentHash(int $index): string
{
    return hash('sha256', 'present:' . $index, true);
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
 * @param array{host: string, port: int, database: string, user: string, dataset: int, batch_size: int, truncate: bool} $config
 */
function renderSeedSummary(array $config, int $inserted, int $rowCount, int $elapsedNs): void
{
    fwrite(STDOUT, 'php-bloom MySQL seed' . PHP_EOL . PHP_EOL);

    printTable(['Setting', 'Value'], [
        ['Host', $config['host'] . ':' . $config['port']],
        ['Database', $config['database']],
        ['User', $config['user']],
        ['Dataset requested', formatInteger($config['dataset'])],
        ['Batch size', formatInteger($config['batch_size'])],
        ['Table truncated', $config['truncate'] ? 'yes' : 'no'],
        ['Rows inserted', formatInteger($inserted)],
        ['Rows currently in suppressions', formatInteger($rowCount)],
        ['Elapsed', formatDuration($elapsedNs)],
    ]);
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

function seedUsage(): string
{
    return <<<'TXT'
Usage:
  php benchmarks/mysql/seed.php [options]

Options:
  --dataset=N          Number of deterministic suppression hashes to seed. Default: 100000
  --batch-size=N       Insert batch size. Default: 1000
  --host=HOST          MySQL host. Default: 127.0.0.1
  --port=PORT          MySQL port. Default: 3306
  --database=NAME      MySQL database. Default: php_bloom_bench
  --user=USER          MySQL user. Default: bloom
  --password=PASSWORD  MySQL password. Default: bloom
  --wait-timeout=N     Seconds to wait for MySQL readiness. Default: 60
  --truncate           Truncate suppressions before seeding. Default
  --no-truncate        Keep existing rows and INSERT IGNORE deterministic hashes
  --help               Show this help

Environment variables:
  BLOOM_MYSQL_DATASET
  BLOOM_MYSQL_BATCH_SIZE
  BLOOM_MYSQL_HOST
  BLOOM_MYSQL_PORT
  BLOOM_MYSQL_DATABASE
  BLOOM_MYSQL_USER
  BLOOM_MYSQL_PASSWORD
  BLOOM_MYSQL_WAIT_TIMEOUT
  BLOOM_MYSQL_TRUNCATE

TXT;
}
