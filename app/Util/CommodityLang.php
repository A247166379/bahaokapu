<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Util\Lang;

final class CommodityLang
{
    private const TAG_KEYS = ["text"];

    private const WIDGET_KEYS = ["cn", "placeholder", "error"];

    private const TEXT_FIELDS = ["delivery_message", "leave_message", "card_show_tips"];

    public static function detail(array $item): array
    {
        $manual = self::manualFor($item);
        if (isset($item['name']) && is_string($item['name'])) {
            $item['name'] = $manual['name'] ?? $item['name'];
        }
        if (isset($item['description']) && is_string($item['description'])) {
            $item['description'] = RichHtml::present(isset($manual['description']) ? RichHtml::sanitize($manual['description'], false) : $item['description']);
        }
        if (isset($item['tags'])) {
            $item['tags'] = isset($manual['tags']) ? self::restoreShape($item['tags'], $manual['tags']) : Lang::transObjectList($item['tags'], self::TAG_KEYS);
        }
        if (isset($item['widget'])) {
            $item['widget'] = isset($manual['widget']) ? self::restoreShape($item['widget'], $manual['widget']) : self::widget($item['widget']);
        }
        foreach (self::TEXT_FIELDS as $field) {
            if (!empty($item[$field]) && is_string($item[$field])) {
                $item[$field] = $manual[$field] ?? Lang::trans($item[$field], "dyn");
            }
        }
        return $item;
    }

    public static function listTags(array $rows): array
    {
        $ids = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $rows)));
        $map = StoreContentService::entityMap('commodity', $ids);
        $source = [];
        if ($map !== []) {
            foreach (\App\Model\Commodity::query()->whereIn('id', $ids)->get(['id', 'name', 'tags']) as $row) $source[(int)$row->id] = $row->getAttributes();
        }
        foreach ($rows as $i => $row) {
            if (is_array($row) && isset($row['tags'])) {
                $rows[$i]['tags'] = Lang::transObjectList($row['tags'], self::TAG_KEYS);
            }
            $id = (int)($row['id'] ?? 0);
            foreach (['name', 'tags'] as $field) {
                if (isset($source[$id]) && isset($map[$id][$field]) && hash_equals(ContentRules::hash($source[$id][$field]), $map[$id][$field]['source_hash'])) {
                    $rows[$i][$field] = $field === 'tags' ? self::restoreShape($row[$field] ?? [], $map[$id][$field]['text']) : $map[$id][$field]['text'];
                }
            }
        }
        return $rows;
    }

    public static function categories(array $rows): array
    {
        $map = StoreContentService::entityMap('category', array_column($rows, 'id'));
        foreach ($rows as $i => $row) {
            $edition = $map[(string)($row['id'] ?? '')]['name'] ?? null;
            // Category names are separately authored per language. A missing or
            // outdated edition falls back to the saved Chinese name, never a
            // dictionary entry that could belong to a different category.
            $source = (string)($row['name'] ?? '');
            $rows[$i]['name'] = $edition && trim((string)($edition['text'] ?? '')) !== ''
                && hash_equals(ContentRules::hash($source), (string)($edition['source_hash'] ?? ''))
                ? $edition['text'] : $source;
        }
        return $rows;
    }

    /** Preserve product fields while translating their associated category in one bulk lookup. */
    public static function categoryRelations(array $rows): array
    {
        $categories = [];
        foreach ($rows as $row) {
            $category = $row['category'] ?? null;
            if (is_array($category) && (int)($category['id'] ?? 0) > 0 && is_string($category['name'] ?? null)) {
                $categories[(int)$category['id']] = $category;
            }
        }
        if ($categories === []) return $rows;
        $translated = [];
        foreach (self::categories(array_values($categories)) as $category) $translated[(int)$category['id']] = $category['name'];
        foreach ($rows as $i => $row) {
            $id = (int)($row['category']['id'] ?? 0);
            if (isset($translated[$id])) $rows[$i]['category']['name'] = $translated[$id];
        }
        return $rows;
    }

    /** Category names are plain text even where legacy owner names allow HTML. */
    public static function categoryView(array $tree): array
    {
        foreach ($tree as $i => $category) {
            if (!is_array($category)) continue;
            if (is_string($category['name'] ?? null)) {
                // Html carries already escaped display text through ViewSafe
                // without either interpreting stored entities or escaping twice.
                $tree[$i]['name'] = new Html(htmlspecialchars($category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            if (is_array($category['children'] ?? null)) $tree[$i]['children'] = self::categoryView($category['children']);
        }
        return $tree;
    }

    /** Apply after the generic dictionary so reviewed entity text is not translated twice. */
    public static function config(array $config, ?array $source = null): array
    {
        $source = $source ?? $config;
        foreach (ContentRules::FIELDS['config'] as $field) {
            if (!isset($source[$field])) continue;
            $value = StoreContentService::entityValue('config', 'site', $field, $source[$field]);
            if ($value !== null) $config[$field] = $value;
        }
        return $config;
    }

    private static function restoreShape(mixed $original, string $text): mixed
    {
        return is_string($original) ? $text : (json_decode($text, true) ?: []);
    }

    private static function manualFor(array $item): array
    {
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) return [];
        $map = StoreContentService::entityMap('commodity', [$id]);
        if (!isset($map[$id])) return [];
        $source = StoreContentService::entitySource('commodity', (string)$id);
        $result = [];
        foreach ($map[$id] as $field => $entry) {
            if (array_key_exists($field, $source) && hash_equals(ContentRules::hash($source[$field]), $entry['source_hash'])) $result[$field] = $entry['text'];
        }
        return $result;
    }

    /** An historical delivery snapshot may only use an edition made from that exact text. */
    public static function orderMessage(int $commodityId, string $snapshot): string
    {
        $text = $commodityId > 0 ? StoreContentService::entityValue('commodity', (string)$commodityId, 'leave_message', $snapshot) : null;
        return $text ?? Lang::trans($snapshot, 'dyn');
    }

    /** Translate historical form labels without changing a submitted value or its meaning. */
    public static function orderWidgetLabels(int $commodityId, array $snapshot): array
    {
        $labels = [];
        if ($commodityId > 0 && $snapshot !== []) {
            $source = \App\Model\Commodity::query()->where('id', $commodityId)->value('widget');
            $edition = is_string($source) ? StoreContentService::entityValue('commodity', (string)$commodityId, 'widget', $source) : null;
            if ($edition !== null) {
                try {
                    // Defend against malformed legacy/manual imports as well as editor submissions.
                    $translated = json_decode(ContentRules::translatedObject('widget', $source, $edition), true);
                    $original = json_decode($source, true);
                    foreach ($original as $i => $field) {
                        if (is_string($field['name'] ?? null) && is_string($field['cn'] ?? null)
                            && is_string($translated[$i]['cn'] ?? null) && $translated[$i]['cn'] !== '') {
                            $labels[$field['name']] = ['source' => $field['cn'], 'text' => $translated[$i]['cn']];
                        }
                    }
                } catch (\InvalidArgumentException | \JsonException) { /* Fall back to the historical label. */ }
            }
        }
        foreach ($snapshot as $name => &$field) {
            if (!is_array($field) || !is_string($field['cn'] ?? null)) continue;
            $label = $labels[$name] ?? null;
            $field['cn'] = $label && hash_equals($label['source'], $field['cn'])
                ? $label['text'] : Lang::trans($field['cn'], 'dyn');
        }
        unset($field);
        return $snapshot;
    }

    private static function widget(mixed $widget): mixed
    {
        $widget = Lang::transObjectList($widget, self::WIDGET_KEYS);

        $wasJson = is_string($widget);
        $list = $wasJson ? json_decode($widget, true) : $widget;
        if (!is_array($list) || $list === []) {
            return $widget;
        }

        $changed = false;
        foreach ($list as $i => $field) {
            if (!is_array($field) || empty($field['dict']) || !is_string($field['dict'])) {
                continue;
            }
            $pairs = [];
            foreach (explode(',', $field['dict']) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) !== 2) {
                    $pairs[] = $pair;
                    continue;
                }
                $label = trim($kv[0]);
                $translated = $label === "" ? $label : Lang::trans($label, "dyn");
                if ($translated !== $label) {
                    $changed = true;
                }
                $pairs[] = $translated . '=' . $kv[1];
            }
            $list[$i]['dict'] = implode(',', $pairs);
        }

        if (!$changed) {
            return $widget;
        }
        return $wasJson ? (string)json_encode($list, JSON_UNESCAPED_UNICODE) : $list;
    }
}
