<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 商品的可配置展示销量增量。
 * 按保存配置的时刻计算，避免依赖定时任务和重复累加。
 *
 * 类名和数据库字段沿用旧名称，避免升级时破坏已有数据。
 */
final class DisplayHeat
{
    public const LIMIT = 2147483647;
    private const RANDOM_DAYS = 64;

    public static function value(?int $base, int $period, int $step, int $startedAt, ?int $now = null, bool $random = false, int $dailyCap = 0, int $seed = 0): ?int
    {
        if ($base === null) {
            return null;
        }

        $base = min(self::LIMIT, max(0, $base));
        $seconds = match ($period) {
            1 => 3600,
            2 => 86400,
            default => 0,
        };
        if ($seconds === 0 || $step <= 0 || $startedAt <= 0) {
            return $base;
        }

        $elapsed = max(0, ($now ?? time()) - $startedAt);
        if ($random) {
            // 新模式仅按小时结算；每日上限是从配置起点开始的连续 24 小时窗口。
            if ($period !== 1 || $dailyCap <= 0) return $base;
            return $base + self::randomGrowth(intdiv($elapsed, 3600), min(self::LIMIT, $step), min(self::LIMIT, $dailyCap), $seed, $startedAt, self::LIMIT - $base);
        }
        $periods = intdiv($elapsed, $seconds);
        if ($periods === 0) {
            return $base;
        }
        $room = self::LIMIT - $base;
        return $periods > intdiv($room, $step)
            ? self::LIMIT
            : $base + $periods * $step;
    }

    /**
     * 前台统一展示的销量：基础销量 + 展示增长量 + 支付成功商品数量。
     */
    public static function total(?int $base, int $period, int $step, int $startedAt, int $orderSold, ?int $now = null, bool $random = false, int $dailyCap = 0, int $seed = 0): int
    {
        $configured = self::value($base, $period, $step, $startedAt, $now, $random, $dailyCap, $seed) ?? 0;
        $orderSold = min(self::LIMIT, max(0, $orderSold));
        $room = self::LIMIT - $configured;

        return $orderSold > $room ? self::LIMIT : $configured + $orderSold;
    }

    /**
     * 保存已校验的设置。只改增长规则时保留已增长量；明确修改基础值才重新起算。
     * 未触及或未改变设置时保持原起点，普通商品编辑不会重置增长。
     *
     * @param array|null $current 现有数据库字段
     * @param array $next 已校验并归一化的基础值、周期、步长、随机开关和每日上限
     */
    public static function configure(?array $current, array $next, int $seed, ?int $now = null): array
    {
        $keys = ['display_sales', 'display_heat_period', 'display_heat_step', 'display_heat_random', 'display_heat_daily_cap'];
        $changed = $current === null;
        foreach ($keys as $key) {
            $old = $key === 'display_sales' ? ($current[$key] ?? null) : (int)($current[$key] ?? 0);
            if (($next[$key] ?? null) !== $old) $changed = true;
        }
        $oldStart = (int)($current['display_heat_started_at'] ?? 0);
        if ((int)$next['display_heat_period'] !== 0 && $oldStart <= 0) $changed = true;
        if (!$changed) return $next + ['display_heat_started_at' => $oldStart];

        $now ??= time();
        if ($current !== null && $next['display_sales'] !== null && $next['display_sales'] === ($current['display_sales'] ?? null)) {
            $next['display_sales'] = self::value(
                $current['display_sales'], (int)($current['display_heat_period'] ?? 0),
                (int)($current['display_heat_step'] ?? 0), $oldStart, $now,
                (int)($current['display_heat_random'] ?? 0) === 1,
                (int)($current['display_heat_daily_cap'] ?? 0), $seed
            );
        }
        $next['display_heat_started_at'] = (int)$next['display_heat_period'] === 0 ? 0 : max(1, $now);
        return $next;
    }

    /**
     * 确定性展示序列：64 天预算循环可整段求和，最后一天最多分配 24 次。
     * 商品、起点相同则结果相同；不使用 rand、不写增长表，访问量不会改变增长。
     * 每日预算先受日上限约束，再分配到各小时，每小时受 step 约束。
     */
    private static function randomGrowth(int $hours, int $step, int $dailyCap, int $seed, int $startedAt, int $room): int
    {
        if ($hours <= 0 || $room <= 0) return 0;
        $maxDaily = min($dailyCap, $step * 24);
        $prefix = $seed . ':' . $startedAt . ':display-sales-v1:';
        $budgets = [];
        $cycle = 0;
        for ($day = 0; $day < self::RANDOM_DAYS; $day++) {
            $budgets[$day] = self::draw($prefix . 'day:' . $day, $maxDaily);
            $cycle += $budgets[$day];
        }

        $days = intdiv($hours, 24);
        $cycles = intdiv($days, self::RANDOM_DAYS);
        if ($cycle > 0 && $cycles > intdiv($room, $cycle)) return $room;
        $growth = $cycles * $cycle;
        $dayIndex = $days % self::RANDOM_DAYS;
        for ($day = 0; $day < $dayIndex; $day++) {
            if ($budgets[$day] >= $room - $growth) return $room;
            $growth += $budgets[$day];
        }

        $budget = $budgets[$dayIndex];
        $remainingHours = $hours % 24;
        for ($hour = 0; $hour < $remainingHours; $hour++) {
            // 保留剩余小时容纳余下预算的空间，因此 24 次的和正好等于日预算。
            $minimum = max(0, $budget - (23 - $hour) * $step);
            $maximum = min($step, $budget);
            $amount = $minimum + self::draw($prefix . 'hour:' . $dayIndex . ':' . $hour, $maximum - $minimum);
            if ($amount >= $room - $growth) return $room;
            $growth += $amount;
            $budget -= $amount;
        }
        return $growth;
    }

    private static function draw(string $key, int $maximum): int
    {
        return (int)hexdec(substr(hash('sha256', $key), 0, 8)) % ($maximum + 1);
    }
}
