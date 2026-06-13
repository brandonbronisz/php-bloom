<?php

declare(strict_types=1);

final class PurePhpBloomFilter
{
    private const EXPORT_MAGIC = 'BLM1';
    private const EXPORT_HEADER_SIZE = 36;
    private const UINT32_BASE = 4294967296;
    private const UINT32_MASK = 0xffffffff;
    private const FNV_OFFSET_HI = 0xcbf29ce4;
    private const FNV_OFFSET_LO = 0x84222325;
    private const FNV_PRIME_HI = 0x00000100;
    private const FNV_PRIME_LO = 0x000001b3;

    private int $capacity;
    private int $bits;
    private int $hashes;
    private int $byteCount;
    private string $bitset;

    /** @var array<int, int>|null */
    private static ?array $popcountTable = null;

    private static ?ReflectionClass $reflection = null;

    public function __construct(int $capacity, float $falsePositiveRate = 0.01)
    {
        self::assertValidCapacity($capacity);
        self::assertValidFalsePositiveRate($falsePositiveRate);

        $bits = self::optimalBits($capacity, $falsePositiveRate);
        $hashes = self::optimalHashes($bits, $capacity);
        $byteCount = intdiv($bits + 7, 8);

        $this->capacity = $capacity;
        $this->bits = $bits;
        $this->hashes = $hashes;
        $this->byteCount = $byteCount;
        $this->bitset = str_repeat("\0", $byteCount);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function bits(): int
    {
        return $this->bits;
    }

    public function hashes(): int
    {
        return $this->hashes;
    }

    public function bytes(): int
    {
        return $this->byteCount;
    }

    public function add(string $value): void
    {
        [$hi, $lo, $h2Hi, $h2Lo] = self::hashPair($value);

        for ($i = 0; $i < $this->hashes; $i++) {
            $this->setBit(self::u64Mod($hi, $lo, $this->bits));
            self::addU64InPlace($hi, $lo, $h2Hi, $h2Lo);
        }
    }

    public function mightContain(string $value): bool
    {
        [$hi, $lo, $h2Hi, $h2Lo] = self::hashPair($value);

        for ($i = 0; $i < $this->hashes; $i++) {
            if (!$this->getBit(self::u64Mod($hi, $lo, $this->bits))) {
                return false;
            }

            self::addU64InPlace($hi, $lo, $h2Hi, $h2Lo);
        }

        return true;
    }

    public function export(): string
    {
        return self::EXPORT_MAGIC
            . self::packU64Le($this->capacity)
            . self::packU64Le($this->bits)
            . self::packU64Le($this->hashes)
            . self::packU64Le($this->byteCount)
            . $this->bitset;
    }

    public static function import(string $data): self
    {
        if (strlen($data) < self::EXPORT_HEADER_SIZE || substr($data, 0, 4) !== self::EXPORT_MAGIC) {
            throw new ValueError('invalid bloom filter data');
        }

        $capacity = self::unpackU64Le(substr($data, 4, 8));
        $bits = self::unpackU64Le(substr($data, 12, 8));
        $hashes = self::unpackU64Le(substr($data, 20, 8));
        $byteCount = self::unpackU64Le(substr($data, 28, 8));

        if (
            $capacity <= 0
            || $bits <= 0
            || $hashes <= 0
            || $byteCount <= 0
            || $byteCount !== intdiv($bits + 7, 8)
            || strlen($data) !== self::EXPORT_HEADER_SIZE + $byteCount
        ) {
            throw new ValueError('invalid bloom filter data');
        }

        $filter = self::newWithoutConstructor();
        $filter->capacity = $capacity;
        $filter->bits = $bits;
        $filter->hashes = $hashes;
        $filter->byteCount = $byteCount;
        $filter->bitset = substr($data, self::EXPORT_HEADER_SIZE);

        return $filter;
    }

    public function setBits(): int
    {
        $table = self::popcountTable();
        $count = 0;
        $lastIndex = $this->byteCount - 1;

        for ($i = 0; $i < $lastIndex; $i++) {
            $count += $table[ord($this->bitset[$i])];
        }

        $validBitsInLastByte = $this->bits % 8;
        $lastByte = ord($this->bitset[$lastIndex]);

        if ($validBitsInLastByte !== 0) {
            $lastByte &= (1 << $validBitsInLastByte) - 1;
        }

        return $count + $table[$lastByte];
    }

    public function fillRatio(): float
    {
        return $this->setBits() / $this->bits;
    }

    public function estimatedFalsePositiveRate(): float
    {
        return $this->fillRatio() ** $this->hashes;
    }

    /**
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
    public function stats(): array
    {
        $setBits = $this->setBits();
        $fillRatio = $setBits / $this->bits;

        return [
            'capacity' => $this->capacity,
            'bits' => $this->bits,
            'hashes' => $this->hashes,
            'bytes' => $this->byteCount,
            'set_bits' => $setBits,
            'fill_ratio' => $fillRatio,
            'estimated_false_positive_rate' => $fillRatio ** $this->hashes,
        ];
    }

    public static function optimalBits(int $capacity, float $falsePositiveRate): int
    {
        self::assertValidCapacity($capacity);
        self::assertValidFalsePositiveRate($falsePositiveRate);

        $bits = -(($capacity * log($falsePositiveRate)) / (log(2.0) * log(2.0)));

        if (!is_finite($bits) || $bits > PHP_INT_MAX) {
            throw new ValueError('calculated bit size is too large');
        }

        return (int) ceil($bits);
    }

    public static function optimalHashes(int $bits, int $capacity): int
    {
        if ($bits <= 0) {
            throw new ValueError('bits must be greater than 0');
        }

        self::assertValidCapacity($capacity);

        $hashes = ($bits / $capacity) * log(2.0);

        if (!is_finite($hashes) || $hashes > PHP_INT_MAX) {
            throw new ValueError('calculated hash count is too large');
        }

        if ($hashes < 1.0) {
            return 1;
        }

        return (int) ceil($hashes);
    }

    private static function assertValidCapacity(int $capacity): void
    {
        if ($capacity <= 0) {
            throw new ValueError('capacity must be greater than 0');
        }
    }

    private static function assertValidFalsePositiveRate(float $falsePositiveRate): void
    {
        if (!is_finite($falsePositiveRate) || $falsePositiveRate <= 0.0 || $falsePositiveRate >= 1.0) {
            throw new ValueError('falsePositiveRate must be greater than 0 and less than 1');
        }
    }

    private function setBit(int $bit): void
    {
        $byteIndex = intdiv($bit, 8);
        $mask = 1 << ($bit % 8);
        $this->bitset[$byteIndex] = chr(ord($this->bitset[$byteIndex]) | $mask);
    }

    private function getBit(int $bit): bool
    {
        $byteIndex = intdiv($bit, 8);
        $mask = 1 << ($bit % 8);

        return (ord($this->bitset[$byteIndex]) & $mask) !== 0;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function hashPair(string $value): array
    {
        [$h1Hi, $h1Lo] = self::fnv1a64($value, 0);
        [$h2Hi, $h2Lo] = self::fnv1a64($value, 1);

        if ($h2Hi === 0 && $h2Lo === 0) {
            $h2Lo = 1;
        }

        return [$h1Hi, $h1Lo, $h2Hi, $h2Lo];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function fnv1a64(string $value, int $seed): array
    {
        $hi = self::FNV_OFFSET_HI;
        $lo = self::FNV_OFFSET_LO;

        $seedHi = intdiv($seed, self::UINT32_BASE);
        $seedLo = $seed % self::UINT32_BASE;

        $hi ^= $seedHi;
        $lo ^= $seedLo;
        self::multiplyByFnvPrime($hi, $lo);

        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $lo ^= ord($value[$i]);
            self::multiplyByFnvPrime($hi, $lo);
        }

        return [$hi, $lo];
    }

    private static function multiplyByFnvPrime(int &$hi, int &$lo): void
    {
        $loProduct = $lo * self::FNV_PRIME_LO;
        $newLo = $loProduct % self::UINT32_BASE;
        $carry = intdiv($loProduct, self::UINT32_BASE);

        $hiProduct = ($hi * self::FNV_PRIME_LO) + ($lo * self::FNV_PRIME_HI) + $carry;
        $newHi = $hiProduct % self::UINT32_BASE;

        $hi = $newHi;
        $lo = $newLo;
    }

    private static function addU64InPlace(int &$hi, int &$lo, int $addHi, int $addLo): void
    {
        $lo += $addLo;
        $carry = 0;

        if ($lo >= self::UINT32_BASE) {
            $lo -= self::UINT32_BASE;
            $carry = 1;
        }

        $hi += $addHi + $carry;

        if ($hi >= self::UINT32_BASE) {
            $hi -= self::UINT32_BASE;
        }
    }

    private static function u64Mod(int $hi, int $lo, int $mod): int
    {
        if ($mod <= 3037000499) {
            return (($hi % $mod) * (self::UINT32_BASE % $mod) + ($lo % $mod)) % $mod;
        }

        $result = 0;
        $chunks = [
            $hi >> 16,
            $hi & 0xffff,
            $lo >> 16,
            $lo & 0xffff,
        ];

        foreach ($chunks as $chunk) {
            $result = (($result * 65536) + $chunk) % $mod;
        }

        return $result;
    }

    private static function packU64Le(int $value): string
    {
        if ($value < 0) {
            throw new ValueError('cannot encode negative integer as uint64');
        }

        $lo = $value % self::UINT32_BASE;
        $hi = intdiv($value, self::UINT32_BASE);

        return pack('V2', $lo, $hi);
    }

    private static function unpackU64Le(string $bytes): int
    {
        if (strlen($bytes) !== 8) {
            throw new ValueError('invalid bloom filter data');
        }

        $parts = unpack('Vlo/Vhi', $bytes);

        if (!is_array($parts) || !isset($parts['lo'], $parts['hi'])) {
            throw new ValueError('invalid bloom filter data');
        }

        $lo = $parts['lo'];
        $hi = $parts['hi'];

        if ($hi > intdiv(PHP_INT_MAX - $lo, self::UINT32_BASE)) {
            throw new ValueError('invalid bloom filter data');
        }

        return ($hi * self::UINT32_BASE) + $lo;
    }

    /**
     * @return array<int, int>
     */
    private static function popcountTable(): array
    {
        if (self::$popcountTable !== null) {
            return self::$popcountTable;
        }

        $table = [];

        for ($i = 0; $i < 256; $i++) {
            $value = $i;
            $count = 0;

            while ($value !== 0) {
                $count += $value & 1;
                $value >>= 1;
            }

            $table[$i] = $count;
        }

        self::$popcountTable = $table;

        return $table;
    }

    private static function newWithoutConstructor(): self
    {
        self::$reflection ??= new ReflectionClass(self::class);

        /** @var self $filter */
        $filter = self::$reflection->newInstanceWithoutConstructor();

        return $filter;
    }
}
