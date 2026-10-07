<?php
declare(strict_types=1);

namespace App\Util;

/** Offline country lookup for the language-neutral storefront entry only. */
final class StoreCountry
{
    /**
     * The caller supplies Nginx's verified REMOTE_ADDR. This class never reads
     * forwarding/country headers, contacts a provider, or guesses from a UA.
     * Unknown, non-public addresses and unavailable data safely return empty.
     */
    public static function lookup(mixed $ip): string
    {
        if (!is_string($ip) || $ip === '' || strlen($ip) > 45 || trim($ip) !== $ip
            || filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
        $packed = @inet_pton($ip);
        if ($packed === false) return '';
        // An IPv4-mapped socket peer has the same country as its IPv4 address.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        if (!self::isPublic($packed)) return '';
        $width = strlen($packed);
        $recordSize = $width * 2 + 2;
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $file = $base . '/app/Data/GeoIP/country-v' . ($width === 4 ? '4' : '6') . '.bin';
        $stream = @fopen($file, 'rb');
        if ($stream === false) return '';
        try {
            $stat = @fstat($stream);
            $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
            if ($size <= 0 || $size % $recordSize !== 0) return '';
            $low = 0;
            $high = intdiv($size, $recordSize) - 1;
            while ($low <= $high) {
                $middle = $low + intdiv($high - $low, 2);
                if (@fseek($stream, $middle * $recordSize, SEEK_SET) !== 0) return '';
                $record = @fread($stream, $recordSize);
                if (!is_string($record) || strlen($record) !== $recordSize) return '';
                $start = substr($record, 0, $width);
                $end = substr($record, $width, $width);
                if (strcmp($start, $end) > 0) return '';
                if (strcmp($packed, $start) < 0) $high = $middle - 1;
                elseif (strcmp($packed, $end) > 0) $low = $middle + 1;
                else {
                    $country = substr($record, $width * 2, 2);
                    return preg_match('/^[A-Z]{2}$/D', $country) && !in_array($country, ['XX', 'ZZ'], true) ? $country : '';
                }
            }
            return '';
        } finally {
            fclose($stream);
        }
    }

    private static function isPublic(string $packed): bool
    {
        $ip = inet_ntop($packed);
        if ($ip === false || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (strlen($packed) === 4) {
            // PHP's flags do not exclude carrier NAT, documentation, benchmark
            // and multicast ranges, which must not select a visitor's region.
            $blocked = [
                ['100.64.0.0', '100.127.255.255'], ['192.0.0.0', '192.0.0.255'],
                ['192.0.2.0', '192.0.2.255'], ['192.88.99.0', '192.88.99.255'],
                ['198.18.0.0', '198.19.255.255'], ['198.51.100.0', '198.51.100.255'],
                ['203.0.113.0', '203.0.113.255'], ['224.0.0.0', '255.255.255.255'],
            ];
        } else {
            // Current global-unicast allocations occupy 2000::/3. Exclude
            // documentation and special tunnel/benchmark identifiers within it.
            $first = ord($packed[0]);
            if ($first < 0x20 || $first > 0x3f) return false;
            $blocked = [
                ['2001::', '2001:0:ffff:ffff:ffff:ffff:ffff:ffff'],
                ['2001:2::', '2001:2:0:ffff:ffff:ffff:ffff:ffff'],
                ['2001:10::', '2001:1f:ffff:ffff:ffff:ffff:ffff:ffff'],
                ['2001:20::', '2001:2f:ffff:ffff:ffff:ffff:ffff:ffff'],
                ['2001:db8::', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'],
                ['2002::', '2002:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
            ];
        }
        foreach ($blocked as [$start, $end]) {
            if (strcmp($packed, inet_pton($start)) >= 0 && strcmp($packed, inet_pton($end)) <= 0) return false;
        }
        return true;
    }
}
