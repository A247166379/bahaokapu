<?php
declare(strict_types=1);

namespace App\Util;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Pure switch/recording-gap rules. Metadata values stay well below VARCHAR(255). */
final class VisitCollection
{
    public const ENABLED = 'collection_enabled';
    public const CHANGED = 'collection_changed_at';
    public const OPEN = 'collection_pause_open';
    public const PAUSE_PREFIX = 'collection_pause_';

    public static function request(array $payload): bool
    {
        if (array_keys($payload) !== ['enabled'] || !in_array($payload['enabled'], [0, 1, '0', '1'], true)) {
            throw new \InvalidArgumentException('统计开关仅接受 enabled=0 或 enabled=1');
        }
        return $payload['enabled'] === 1 || $payload['enabled'] === '1';
    }

    public static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) return null;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone(VisitStatistics::TIMEZONE));
        return $parsed && $parsed->format('Y-m-d H:i:s') === $value ? $parsed : null;
    }

    public static function state(array $metadata): array
    {
        $value = $metadata[self::ENABLED] ?? null;
        // Only absence is the legacy default-on case; corrupt persisted values stop collection safely.
        $enabled = $value === null || $value === '1';
        $changed = self::time($metadata[self::CHANGED] ?? null);
        return ['enabled' => $enabled, 'changed_at' => $changed?->format('Y-m-d H:i:s'), 'timezone' => VisitStatistics::TIMEZONE];
    }

    public static function pauseKey(string $key): bool
    {
        return (bool)preg_match('/^collection_pause_[0-9]{14}_[a-f0-9]{8}$/D', $key);
    }

    public static function history(array $metadata): array
    {
        $intervals = []; $valid = true; $open = 0;
        foreach ($metadata as $key => $value) {
            if (!self::pauseKey((string)$key)) continue;
            try {
                if (!is_string($value) || strlen($value) > 255) throw new \InvalidArgumentException();
                $pause = json_decode($value, true, 4, JSON_THROW_ON_ERROR);
                if (!is_array($pause) || array_keys($pause) !== ['from', 'until']) throw new \InvalidArgumentException();
                $from = self::time($pause['from']); $until = self::time($pause['until']);
                if ($from === null || ($pause['until'] !== null && $until === null) || ($until !== null && $until < $from)) throw new \InvalidArgumentException();
                if ($until === null) $open++;
                $intervals[] = ['from' => $from->getTimestamp(), 'until' => $until?->getTimestamp()];
            } catch (\Throwable) { $valid = false; }
        }
        if ($open > 1) $valid = false;
        if (array_key_exists(self::ENABLED, $metadata)) {
            $pointer = $metadata[self::OPEN] ?? '';
            if ($metadata[self::ENABLED] === '0') {
                if (!is_string($pointer) || !self::pauseKey($pointer) || !isset($metadata[$pointer]) || $open !== 1) $valid = false;
                else {
                    try {
                        $active = json_decode($metadata[$pointer], true, 4, JSON_THROW_ON_ERROR);
                        if (!is_array($active) || !array_key_exists('until', $active) || $active['until'] !== null) $valid = false;
                    } catch (\Throwable) { $valid = false; }
                }
            } elseif ($metadata[self::ENABLED] !== '1' || $open !== 0 || $pointer !== '') $valid = false;
        }
        usort($intervals, static fn(array $a, array $b): int => $a['from'] <=> $b['from']);
        return ['intervals' => $intervals, 'valid' => $valid];
    }

    public static function intersects(array $history, DateTimeInterface $from, DateTimeInterface $until): bool
    {
        if ($until <= $from) return false;
        if (!($history['valid'] ?? false)) return true;
        foreach ($history['intervals'] ?? [] as $pause) {
            // Half-open intervals: a zero-second off/on pair creates no imaginary recording gap.
            if ($pause['from'] < $until->getTimestamp() && ($pause['until'] === null || $pause['until'] > $from->getTimestamp())
                && ($pause['until'] === null || $pause['until'] > $pause['from'])) return true;
        }
        return false;
    }

    public static function fullyPaused(array $history, DateTimeInterface $from, DateTimeInterface $until): bool
    {
        if ($until <= $from || !($history['valid'] ?? false)) return false;
        $cursor = $from->getTimestamp(); $end = $until->getTimestamp();
        foreach ($history['intervals'] ?? [] as $pause) {
            $stop = $pause['until'] ?? PHP_INT_MAX;
            if ($stop <= $cursor) continue;
            if ($pause['from'] > $cursor) return false;
            $cursor = max($cursor, $stop);
            if ($cursor >= $end) return true;
        }
        return false;
    }
}
