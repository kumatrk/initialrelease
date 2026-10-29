<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP;

/**
 * Local Tor, FireHOL anonymous, and cloud CIDR check for the click write path.
 *
 * A hit labels the click Corporate so the existing campaign bot route can see it.
 * It does not change bot classification or exclude_from_stats.
 * The click path reads a packed range file and binary-searches it. It does not
 * expand millions of addresses into a PHP array. A missing file, or a large list
 * whose cache has not been built, fails open.
 */
final class AnonymousRangeLookup
{
    private const BIN_MAGIC = 'KMR1';

    /** CIDR files larger than this are not parsed on a click. The downloader builds the cache. */
    private const REQUEST_PARSE_LIMIT = 2097152;

    private const SORT_CHUNK = 100000;

    private static ?string $directoryOverride = null;

    private static bool $loaded = false;

    private static int $loadedStamp = 0;

    /** @var resource|null Open ranges.bin handle — never slurped into a PHP string. */
    private static $handle = null;

    private static int $v4Count = 0;

    private static int $v4Offset = 0;

    private static int $v6Count = 0;

    private static int $v6Offset = 0;

    private static bool $loggedSkip = false;

    public static function defaultDirectory(): string
    {
        $root = defined('ROOT_PATH') && is_string(ROOT_PATH) && ROOT_PATH !== ''
            ? ROOT_PATH
            : dirname(__DIR__, 2);

        return $root . DIRECTORY_SEPARATOR . 'geoip' . DIRECTORY_SEPARATOR . 'ranges';
    }

    /**
     * Point tests at a temp directory. Null restores the default geoip/ranges path.
     */
    public static function setDirectory(?string $directory): void
    {
        self::$directoryOverride = $directory;
        self::reset();
    }

    public static function reset(): void
    {
        if (is_resource(self::$handle)) {
            fclose(self::$handle);
        }
        self::$handle = null;
        self::$loaded = false;
        self::$loadedStamp = 0;
        self::$v4Count = 0;
        self::$v4Offset = 0;
        self::$v6Count = 0;
        self::$v6Offset = 0;
    }

    /**
     * Parse the CIDR files and write the packed cache. Used by the downloader.
     */
    public static function rebuildCache(?string $directory = null): bool
    {
        if ($directory !== null) {
            self::setDirectory($directory);
        }
        self::reset();
        try {
            self::load(true);
            return self::$handle !== null || self::sourceStamp(self::directory()) === 0;
        } catch (\Throwable $e) {
            error_log('AnonymousRangeLookup: ' . $e->getMessage());
            self::reset();
            return false;
        }
    }

    public static function contains(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || !IpUtils::isValidIp($ip) || self::isNonPublic($ip)) {
            return false;
        }

        try {
            self::load(false);
        } catch (\Throwable $e) {
            error_log('AnonymousRangeLookup: ' . $e->getMessage());
            return false;
        }

        if (self::$handle === null) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = self::ipv4ToInt($ip);
            return $n !== null && self::containsV4($n);
        }

        $packed = @inet_pton($ip);
        if (!is_string($packed) || strlen($packed) !== 16) {
            return false;
        }

        return self::containsV6($packed);
    }

    private static function directory(): string
    {
        return self::$directoryOverride ?? self::defaultDirectory();
    }

    private static function load(bool $forceParse): void
    {
        $dir = self::directory();
        $bin = $dir . DIRECTORY_SEPARATOR . 'ranges.bin';

        // Hot path: stamp from ranges.bin mtime only — never glob *.cidr on a click.
        if (!$forceParse) {
            $binStamp = is_file($bin) ? (int) @filemtime($bin) : 0;
            if (self::$loaded && self::$loadedStamp === $binStamp && self::$handle !== null) {
                return;
            }
            if ($binStamp > 0 && self::openBin($bin)) {
                self::$loaded = true;
                self::$loadedStamp = $binStamp;
                return;
            }
            // Missing/corrupt cache: fail open unless the list is small enough to parse here.
            if (!is_dir($dir)) {
                self::reset();
                self::$loaded = true;
                self::$loadedStamp = 0;
                return;
            }
            $bytes = self::cidrBytes($dir);
            if ($bytes > self::REQUEST_PARSE_LIMIT) {
                if (!self::$loggedSkip) {
                    self::$loggedSkip = true;
                    error_log('AnonymousRangeLookup: range list is not cached. Run php scripts/download-anonymous-ranges.php');
                }
                self::reset();
                self::$loaded = true;
                self::$loadedStamp = 0;
                return;
            }
            if ($bytes === 0) {
                self::reset();
                self::$loaded = true;
                self::$loadedStamp = 0;
                return;
            }
            self::buildBin($dir, $bin);
            $binStamp = is_file($bin) ? (int) @filemtime($bin) : 0;
            self::openBin($bin);
            self::$loaded = true;
            self::$loadedStamp = $binStamp;
            return;
        }

        // Force rebuild (downloader / CLI): CIDR mtimes decide freshness.
        $stamp = self::sourceStamp($dir);
        self::reset();
        self::$loaded = true;
        self::$loadedStamp = is_file($bin) ? (int) @filemtime($bin) : $stamp;

        if ($stamp === 0 || !is_dir($dir)) {
            return;
        }

        self::buildBin($dir, $bin);
        if (self::openBin($bin)) {
            self::$loadedStamp = (int) @filemtime($bin);
        }
    }

    private static function sourceStamp(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $max = 0;
        foreach (self::cidrFiles($dir) as $file) {
            $max = max($max, (int) @filemtime($file));
        }

        return $max;
    }

    private static function cidrBytes(string $dir): int
    {
        $bytes = 0;
        foreach (self::cidrFiles($dir) as $file) {
            $bytes += (int) @filesize($file);
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private static function cidrFiles(string $dir): array
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.cidr');
        if (!is_array($files)) {
            return [];
        }
        sort($files);

        return $files;
    }

    private static function buildBin(string $dir, string $binPath): void
    {
        $work = $dir . DIRECTORY_SEPARATOR . 'build-tmp';
        if (!is_dir($work) && !mkdir($work, 0755, true) && !is_dir($work)) {
            throw new \RuntimeException('Cannot create range build directory');
        }

        $v4Raw = $work . DIRECTORY_SEPARATOR . 'v4.raw';
        $v6Raw = $work . DIRECTORY_SEPARATOR . 'v6.raw';
        $v4Out = fopen($v4Raw, 'wb');
        $v6Out = fopen($v6Raw, 'wb');
        if ($v4Out === false || $v6Out === false) {
            throw new \RuntimeException('Cannot write range build files');
        }

        try {
            foreach (self::cidrFiles($dir) as $file) {
                self::writeRawRanges($file, $v4Out, $v6Out);
            }
            fclose($v4Out);
            fclose($v6Out);
            $v4Out = null;
            $v6Out = null;

            $v4Merged = self::sortAndMerge($v4Raw, 8, true, $work, 'v4');
            $v6Merged = self::sortAndMerge($v6Raw, 32, false, $work, 'v6');
            self::assembleBin($binPath, $v4Merged, $v6Merged);
        } finally {
            if (is_resource($v4Out)) {
                fclose($v4Out);
            }
            if (is_resource($v6Out)) {
                fclose($v6Out);
            }
            self::removeTree($work);
        }
    }

    /**
     * @param resource $v4Out
     * @param resource $v6Out
     */
    private static function writeRawRanges(string $file, $v4Out, $v6Out): void
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $token = preg_split('/\s+/', $line)[0] ?? '';
                if ($token === '' || $token[0] === '!') {
                    continue;
                }
                if (str_contains($token, ':')) {
                    $range = self::ipv6Cidr($token);
                    if ($range !== null) {
                        fwrite($v6Out, $range[0] . $range[1]);
                    }
                    continue;
                }
                $range = self::ipv4Cidr($token);
                if ($range !== null) {
                    fwrite($v4Out, pack('V', $range[0]) . pack('V', $range[1]));
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private static function sortAndMerge(string $rawPath, int $width, bool $v4, string $work, string $tag): string
    {
        $merged = $work . DIRECTORY_SEPARATOR . $tag . '.merged';
        $in = fopen($rawPath, 'rb');
        if ($in === false) {
            file_put_contents($merged, '');
            return $merged;
        }

        $chunkFiles = [];
        $chunk = [];
        $index = 0;
        try {
            while (!feof($in)) {
                $block = fread($in, $width * 8192);
                if (!is_string($block) || $block === '') {
                    break;
                }
                $count = intdiv(strlen($block), $width);
                for ($i = 0; $i < $count; $i++) {
                    $chunk[] = substr($block, $i * $width, $width);
                    if (count($chunk) >= self::SORT_CHUNK) {
                        $chunkFiles[] = self::flushChunk($chunk, $work, $tag, $index, $v4);
                        $index++;
                        $chunk = [];
                    }
                }
            }
            if ($chunk !== []) {
                $chunkFiles[] = self::flushChunk($chunk, $work, $tag, $index, $v4);
            }
        } finally {
            fclose($in);
        }

        if ($chunkFiles === []) {
            file_put_contents($merged, '');
            return $merged;
        }

        self::mergeChunks($chunkFiles, $width, $v4, $merged);
        foreach ($chunkFiles as $file) {
            @unlink($file);
        }

        return $merged;
    }

    /**
     * @param list<string> $chunk
     */
    private static function flushChunk(array $chunk, string $work, string $tag, int $index, bool $v4): string
    {
        usort($chunk, static function (string $a, string $b) use ($v4): int {
            return self::compareStart($a, $b, $v4);
        });
        $path = $work . DIRECTORY_SEPARATOR . $tag . '-' . $index . '.chunk';
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write range sort chunk');
        }
        foreach ($chunk as $record) {
            fwrite($out, $record);
        }
        fclose($out);

        return $path;
    }

    /**
     * @param list<string> $chunkFiles
     */
    private static function mergeChunks(array $chunkFiles, int $width, bool $v4, string $mergedPath): void
    {
        $handles = [];
        $heads = [];
        foreach ($chunkFiles as $i => $file) {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Cannot read range sort chunk');
            }
            $handles[$i] = $handle;
            $heads[$i] = self::readRecord($handle, $width);
        }

        $out = fopen($mergedPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write merged ranges');
        }

        $current = null;
        try {
            while (true) {
                $bestIndex = null;
                foreach ($heads as $i => $record) {
                    if ($record === null) {
                        continue;
                    }
                    if ($bestIndex === null || self::compareStart($record, $heads[$bestIndex], $v4) < 0) {
                        $bestIndex = $i;
                    }
                }
                if ($bestIndex === null) {
                    break;
                }
                $next = $heads[$bestIndex];
                $heads[$bestIndex] = self::readRecord($handles[$bestIndex], $width);
                if ($current === null) {
                    $current = $next;
                    continue;
                }
                $extended = self::extend($current, $next, $v4);
                if ($extended !== null) {
                    $current = $extended;
                    continue;
                }
                fwrite($out, $current);
                $current = $next;
            }
            if ($current !== null) {
                fwrite($out, $current);
            }
        } finally {
            fclose($out);
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
    }

    /**
     * @param resource $handle
     */
    private static function readRecord($handle, int $width): ?string
    {
        $record = fread($handle, $width);
        if (!is_string($record) || strlen($record) !== $width) {
            return null;
        }

        return $record;
    }

    private static function compareStart(string $a, string $b, bool $v4): int
    {
        if ($v4) {
            $as = self::readUint32($a, 0);
            $bs = self::readUint32($b, 0);
            return ($as ?? 0) <=> ($bs ?? 0);
        }

        return substr_compare($a, $b, 0, 16);
    }

    private static function extend(string $current, string $next, bool $v4): ?string
    {
        if ($v4) {
            $start = self::readUint32($current, 0);
            $end = self::readUint32($current, 4);
            $nextStart = self::readUint32($next, 0);
            $nextEnd = self::readUint32($next, 4);
            if ($start === null || $end === null || $nextStart === null || $nextEnd === null) {
                return null;
            }
            $touches = $end === 4294967295 ? $nextStart <= $end : $nextStart <= $end + 1;
            if (!$touches) {
                return null;
            }
            $far = $nextEnd > $end ? $nextEnd : $end;

            return pack('V', $start) . pack('V', $far);
        }

        $currentEnd = substr($current, 16, 16);
        $nextStart = substr($next, 0, 16);
        if (strcmp($nextStart, $currentEnd) > 0) {
            return null;
        }
        $end = $currentEnd;
        $nextEnd = substr($next, 16, 16);
        if (strcmp($nextEnd, $end) > 0) {
            $end = $nextEnd;
        }

        return substr($current, 0, 16) . $end;
    }

    private static function assembleBin(string $binPath, string $v4Merged, string $v6Merged): void
    {
        $v4Size = is_file($v4Merged) ? (int) filesize($v4Merged) : 0;
        $v6Size = is_file($v6Merged) ? (int) filesize($v6Merged) : 0;
        $v4Count = intdiv($v4Size, 8);
        $v6Count = intdiv($v6Size, 32);
        $tmp = $binPath . '.tmp';
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write range cache');
        }
        try {
            fwrite($out, self::BIN_MAGIC);
            fwrite($out, pack('V', $v4Count));
            self::copyFile($v4Merged, $out, $v4Count * 8);
            fwrite($out, pack('V', $v6Count));
            self::copyFile($v6Merged, $out, $v6Count * 32);
        } finally {
            fclose($out);
        }
        if (is_file($binPath)) {
            @unlink($binPath);
        }
        if (!@rename($tmp, $binPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot replace range cache');
        }
    }

    /**
     * @param resource $out
     */
    private static function copyFile(string $path, $out, int $bytes): void
    {
        if ($bytes <= 0 || !is_file($path)) {
            return;
        }
        $in = fopen($path, 'rb');
        if ($in === false) {
            return;
        }
        $left = $bytes;
        try {
            while ($left > 0 && !feof($in)) {
                $block = fread($in, min(1048576, $left));
                if (!is_string($block) || $block === '') {
                    break;
                }
                fwrite($out, $block);
                $left -= strlen($block);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Open ranges.bin and read header only. Lookup probes use fseek — not a 15MB string.
     */
    private static function openBin(string $path): bool
    {
        if (is_resource(self::$handle)) {
            fclose(self::$handle);
        }
        self::$handle = null;
        self::$v4Count = 0;
        self::$v4Offset = 0;
        self::$v6Count = 0;
        self::$v6Offset = 0;

        $size = @filesize($path);
        if ($size === false || $size < 8) {
            return false;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }

        $header = fread($fh, 8);
        if (!is_string($header) || strlen($header) !== 8 || substr($header, 0, 4) !== self::BIN_MAGIC) {
            fclose($fh);
            return false;
        }

        $v4Count = self::readUint32($header, 4);
        if ($v4Count === null) {
            fclose($fh);
            return false;
        }

        $v4Offset = 8;
        $v6CountOffset = $v4Offset + ($v4Count * 8);
        if ($size < $v6CountOffset + 4) {
            fclose($fh);
            return false;
        }

        if (fseek($fh, $v6CountOffset) !== 0) {
            fclose($fh);
            return false;
        }
        $v6Header = fread($fh, 4);
        if (!is_string($v6Header) || strlen($v6Header) !== 4) {
            fclose($fh);
            return false;
        }
        $v6Count = self::readUint32($v6Header, 0);
        if ($v6Count === null) {
            fclose($fh);
            return false;
        }

        $v6Offset = $v6CountOffset + 4;
        if ($size < $v6Offset + ($v6Count * 32)) {
            fclose($fh);
            return false;
        }

        self::$handle = $fh;
        self::$v4Count = $v4Count;
        self::$v4Offset = $v4Offset;
        self::$v6Count = $v6Count;
        self::$v6Offset = $v6Offset;

        return true;
    }

    private static function readBytes(int $offset, int $length): ?string
    {
        if (!is_resource(self::$handle) || $length <= 0) {
            return null;
        }
        if (fseek(self::$handle, $offset) !== 0) {
            return null;
        }
        $data = fread(self::$handle, $length);
        if (!is_string($data) || strlen($data) !== $length) {
            return null;
        }

        return $data;
    }

    private static function containsV4(int $ip): bool
    {
        $lo = 0;
        $hi = self::$v4Count - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $pos = self::$v4Offset + ($mid * 8);
            $record = self::readBytes($pos, 8);
            if ($record === null) {
                return false;
            }
            $start = self::readUint32($record, 0);
            $end = self::readUint32($record, 4);
            if ($start === null || $end === null) {
                return false;
            }
            if ($ip < $start) {
                $hi = $mid - 1;
                continue;
            }
            if ($ip > $end) {
                $lo = $mid + 1;
                continue;
            }

            return true;
        }

        return false;
    }

    private static function containsV6(string $packed): bool
    {
        $lo = 0;
        $hi = self::$v6Count - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $pos = self::$v6Offset + ($mid * 32);
            $record = self::readBytes($pos, 32);
            if ($record === null) {
                return false;
            }
            $startCmp = strcmp(substr($record, 0, 16), $packed);
            if ($startCmp > 0) {
                $hi = $mid - 1;
                continue;
            }
            $endCmp = strcmp(substr($record, 16, 16), $packed);
            if ($endCmp < 0) {
                $lo = $mid + 1;
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function ipv4Cidr(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        $start = self::ipv4ToInt($parts[0]);
        if ($start === null) {
            return null;
        }
        $prefix = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : 32;
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }
        if ($prefix === 0) {
            return [0, 4294967295];
        }
        $hostBits = 32 - $prefix;
        $mask = (0xFFFFFFFF << $hostBits) & 0xFFFFFFFF;
        $network = $start & $mask;
        $end = $network | ((1 << $hostBits) - 1);

        return [$network & 0xFFFFFFFF, $end & 0xFFFFFFFF];
    }

    private static function ipv4ToInt(string $ip): ?int
    {
        $long = ip2long($ip);
        if ($long === false) {
            return null;
        }

        return (int) sprintf('%u', $long);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function ipv6Cidr(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        $packed = @inet_pton($parts[0]);
        if (!is_string($packed) || strlen($packed) !== 16) {
            return null;
        }
        $prefix = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : 128;
        if ($prefix < 0 || $prefix > 128) {
            return null;
        }
        $bytes = array_values(unpack('C*', $packed) ?: []);
        if (count($bytes) !== 16) {
            return null;
        }
        $start = $bytes;
        $end = $bytes;
        for ($i = 0; $i < 16; $i++) {
            $bitsHere = $prefix - ($i * 8);
            if ($bitsHere >= 8) {
                continue;
            }
            if ($bitsHere <= 0) {
                $start[$i] = 0;
                $end[$i] = 255;
                continue;
            }
            $mask = (0xFF << (8 - $bitsHere)) & 0xFF;
            $start[$i] = $bytes[$i] & $mask;
            $end[$i] = $start[$i] | (~$mask & 0xFF);
        }

        return [pack('C*', ...$start), pack('C*', ...$end)];
    }

    private static function readUint32(string $raw, int $offset): ?int
    {
        if ($offset < 0 || strlen($raw) < $offset + 4) {
            return null;
        }
        $unpacked = unpack('V', substr($raw, $offset, 4));
        if (!is_array($unpacked) || !isset($unpacked[1])) {
            return null;
        }

        return (int) $unpacked[1];
    }

    /**
     * Loopback, RFC1918, and link-local only. Documentation ranges stay matchable for tests.
     */
    private static function isNonPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = self::ipv4ToInt($ip);
            if ($n === null) {
                return true;
            }
            if ($n <= 0x00FFFFFF) {
                return true;
            }
            if (($n & 0xFF000000) === 0x0A000000) {
                return true;
            }
            if (($n & 0xFF000000) === 0x7F000000) {
                return true;
            }
            if (($n & 0xFFFF0000) === 0xA9FE0000) {
                return true;
            }
            if (($n & 0xFFF00000) === 0xAC100000) {
                return true;
            }
            if (($n & 0xFFFF0000) === 0xC0A80000) {
                return true;
            }

            return false;
        }

        $lower = strtolower($ip);
        if ($lower === '::1' || str_starts_with($lower, '::ffff:')) {
            return true;
        }
        if (str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
            return true;
        }

        return false;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::removeTree($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }
}
