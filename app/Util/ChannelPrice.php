<?php
declare(strict_types=1);
namespace App\Util;

use App\Model\Commodity;
use Kernel\Exception\JSONException;

/** Manual public channel prices. Locale, membership and wholesale never change this branch. */
final class ChannelPrice
{
    public static function money(mixed $value, bool $nullable = true): ?string
    {
        if ($nullable && ($value === null || (is_scalar($value) && trim((string)$value) === ''))) return null;
        if (!is_scalar($value) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', trim((string)$value)) || (float)$value <= 0) {
            throw new JSONException('渠道单价必须大于0，最多两位小数；留空关闭渠道价');
        }
        return number_format((float)$value, 2, '.', '');
    }

    public static function config(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        return Ini::toArray((string)$raw);
    }

    public static function options(mixed $raw): array
    {
        try { return array_keys((array)(self::config($raw)['category'] ?? [])); }
        catch (\Throwable $e) { return []; }
    }

    public static function normalizeMap(mixed $raw, mixed $config): ?string
    {
        if ($raw === null || $raw === '') return null;
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) throw new JSONException('规格渠道价格式错误');
        $options = self::options($config);
        $result = [];
        foreach ($raw as $key => $price) {
            $price = self::money($price);
            if ($price === null) continue;
            if (!in_array((string)$key, array_map('strval', $options), true)) throw new JSONException('规格渠道价包含已不存在的商品规格');
            $result[(string)$key] = $price;
        }
        return $result ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    public static function prices(Commodity $commodity): array
    {
        $raw = $commodity->channel_prices;
        return is_array($raw) ? $raw : (array)json_decode((string)$raw, true);
    }

    public static function unit(Commodity $commodity, ?string $race = null): string
    {
        // Public channel sales are for this site's own inventory. Existing substation pricing stays isolated.
        if ((int)$commodity->owner !== 0 || (int)$commodity->shared_id !== 0 || \App\Model\Business::get()) {
            throw new JSONException('该商品暂未开放渠道价');
        }
        $config = self::config($commodity->config);
        if (!empty($config['category'])) {
            if ($race === null || $race === '' || !array_key_exists($race, $config['category'])) throw new JSONException('请选择商品类型');
            $price = self::money(self::prices($commodity)[$race] ?? null);
        } else {
            $price = self::money($commodity->channel_price);
        }
        if ($price === null) throw new JSONException('该商品暂未开放渠道价');
        return $price;
    }

    public static function display(Commodity $commodity): array
    {
        $result = ['channel_price' => null, 'channel_prices' => [], 'can_channel' => false];
        if ((int)$commodity->owner !== 0 || (int)$commodity->shared_id !== 0 || \App\Model\Business::get()) return $result;
        try {
            $options = self::options($commodity->config);
            if ($options) {
                foreach ($options as $race) {
                    $price = self::money(self::prices($commodity)[$race] ?? null);
                    if ($price !== null) $result['channel_prices'][$race] = $price;
                }
                if ($result['channel_prices']) $result['channel_price'] = min($result['channel_prices']);
            } else $result['channel_price'] = self::money($commodity->channel_price);
            $result['can_channel'] = $result['channel_price'] !== null;
        } catch (\Throwable $e) { /* Bad legacy settings hide channel purchase instead of yielding a zero price. */ }
        return $result;
    }
}
