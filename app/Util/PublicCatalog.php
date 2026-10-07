<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Commodity;
use App\Model\UserGroup;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Util\Lang;

/** A bounded, public first page. Pricing and stock continue through the existing commerce API. */
final class PublicCatalog
{
    public static function firstPage(
        array $categoryList,
        ?UserGroup $group = null,
        int|string|null $categoryId = 0,
        string $priceType = 'retail',
        int $limit = 24
    ): array {
        $limit = max(1, min(24, $limit));
        // This list comes from Shop::getCategory($group), which already applies
        // the existing published/local/category membership visibility rules.
        $categoryIds = array_values(array_unique(array_filter(array_map(
            static fn(array $category): int => is_numeric($category['id'] ?? null) ? (int)$category['id'] : 0,
            $categoryList
        ), static fn(int $id): bool => $id > 0)));
        if ($categoryIds === []) return [];
        $categoryId = $categoryId ?? 0;
        if ($categoryId === '') $categoryId = 0;
        if ($categoryId !== 'recommend' && !ctype_digit((string)$categoryId)) return [];
        if ($categoryId !== 'recommend' && (int)$categoryId > 0 && !in_array((int)$categoryId, $categoryIds, true)) return [];

        $query = Commodity::query()->where('owner', 0)->where(static function (Builder $local): void {
            $local->where('shared_id', 0)->orWhereNull('shared_id');
        })->where('status', 1)->whereIn('category_id', $categoryIds);
        if ($categoryId === 'recommend') $query->where('recommend', 1);
        elseif ((int)$categoryId !== 0) $query->where('category_id', (int)$categoryId);

        // Hidden product membership rules use the same parser as Api\Index::commodity.
        // Scan a bounded number so hidden rows do not consume the visible first page.
        $candidates = $query->orderBy('sort', 'asc')->orderBy('id', 'asc')
            ->limit(min(240, $limit * 10))->get([
                'id', 'name', 'cover', 'category_id', 'hide', 'level_price', 'delivery_way',
                'owner', 'shared_id', 'config', 'channel_price', 'channel_prices'
            ]);
        $rows = [];
        foreach ($candidates as $commodity) {
            if ((int)$commodity->hide === 1) {
                try { $permissions = Commodity::parseGroupConfig($commodity->level_price, $group); }
                catch (\Throwable $ignored) { $permissions = null; }
                if (!$permissions || (int)($permissions['show'] ?? 0) !== 1) continue;
            }
            if ($priceType === 'channel' && !ChannelPrice::display($commodity)['can_channel']) continue;
            $rows[] = [
                'id' => (int)$commodity->id,
                'name' => (string)$commodity->name,
                'cover' => self::cover((string)$commodity->cover),
                'delivery_way' => (int)$commodity->delivery_way,
                'url' => StoreLocale::url('/buy/' . (int)$commodity->id)
                    . ($priceType === 'channel' ? '?price_type=channel' : ''),
            ];
            if (count($rows) === $limit) break;
        }
        // Product names use reviewed editions or the saved source text.
        $translated = CommodityLang::listTags($rows);
        foreach ($rows as $index => &$row) {
            $row['name'] = trim(html_entity_decode(strip_tags((string)($translated[$index]['name'] ?? $row['name'])),
                ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        unset($row);
        // No inventory counts, card contents, cost/group structures or private
        // identifiers leave this helper, even if translation adds other fields.
        return $rows;
    }

    private static function cover(string $value): string
    {
        if ($value === '' || preg_match('/[\x00-\x20\\\\]/', $value)) return '/favicon.ico';
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return $value;
        $parts = parse_url($value);
        return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $value : '/favicon.ico';
    }
}
