<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Commodity;
use App\Model\ContentTranslation;
use InvalidArgumentException;

/** Independently authored titles use the existing commodity edition storage. */
final class CommodityNames
{
    public const LIMIT = 255;
    public const FIELDS = ['name_zh_tw' => 'zh-tw', 'name_en' => 'en', 'name_ru' => 'ru', 'name_vi' => 'vi'];

    /** Omitted editions are unchanged; a submitted empty edition is an explicit removal. */
    public static function input(array $input): array
    {
        $names = [];
        foreach (array_merge(['name'], array_keys(self::FIELDS)) as $field) {
            if (!array_key_exists($field, $input)) continue;
            $text = $input[$field];
            if (!is_string($text) || !mb_check_encoding($text, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f]/', $text) || strip_tags($text) !== $text
                || mb_strlen($text, 'UTF-8') > self::LIMIT) {
                throw new InvalidArgumentException('商品名称只允许纯文本，最多255个字符');
            }
            $text = trim($text);
            if ($field === 'name' && $text === '') throw new InvalidArgumentException('简体中文商品名称不能为空');
            $names[$field] = $text;
        }
        return $names;
    }

    /** Stale/draft titles stay editable but require an explicit edit or confirmation to publish. */
    public static function fields(string $source, array $editions): array
    {
        $result = array_fill_keys(array_keys(self::FIELDS), '');
        $result['name_translation_stale'] = [];
        $result['name_translation_draft'] = [];
        $hash = ContentRules::hash($source);
        foreach (self::FIELDS as $field => $locale) {
            $edition = $editions[$locale] ?? null;
            if (!is_array($edition)) continue;
            $result[$field] = (string)($edition['text'] ?? '');
            if (!hash_equals($hash, (string)($edition['source_hash'] ?? ''))) {
                $result['name_translation_stale'][] = $field;
            } elseif (!in_array((int)($edition['status'] ?? 0), [1, 2], true)) {
                $result['name_translation_draft'][] = $field;
            }
        }
        return $result;
    }

    /** Batch lookup keeps list attributes, SKU configuration and prices unchanged. */
    public static function attach(array $commodities): array
    {
        $map = [];
        if ($commodities !== [] && StoreContentService::ready()) {
            foreach (ContentTranslation::query()->where('entity_type', 'commodity')
                ->whereIn('entity_id', array_map('strval', array_column($commodities, 'id')))
                ->where('field', 'name')->whereIn('locale', array_values(self::FIELDS))
                ->get(['entity_id', 'locale', 'text', 'status', 'source_hash']) as $edition) {
                $map[(string)$edition->entity_id][(string)$edition->locale] = $edition->getAttributes();
            }
        }
        foreach ($commodities as &$commodity) {
            $commodity = array_replace($commodity, self::fields((string)$commodity['name'], $map[(string)$commodity['id']] ?? []));
        }
        unset($commodity);
        return $commodities;
    }

    /** Caller holds the commodity lock and transaction until every submitted edition is saved. */
    public static function persist(Commodity $commodity, array $names): void
    {
        if ($names === []) return;
        if (!StoreContentService::ready()) throw new InvalidArgumentException('商品语言配置尚未就绪，请刷新后重试');
        $source = (string)$commodity->name;
        $metadata = ['source_hash' => ContentRules::hash($source), 'source_revision' => 1,
            'status' => 2, 'update_time' => date('Y-m-d H:i:s')];
        ContentTranslation::query()->updateOrCreate(['entity_type' => 'commodity', 'entity_id' => (string)$commodity->id,
            'locale' => 'zh-cn', 'field' => 'name'], ['text' => $source] + $metadata);
        foreach (self::FIELDS as $field => $locale) {
            // In particular, never rebind an unsubmitted old edition after a source/price change.
            if (!array_key_exists($field, $names)) continue;
            $key = ['entity_type' => 'commodity', 'entity_id' => (string)$commodity->id, 'locale' => $locale, 'field' => 'name'];
            if ($names[$field] === '') ContentTranslation::query()->where($key)->delete();
            else ContentTranslation::query()->updateOrCreate($key, ['text' => $names[$field]] + $metadata);
        }
    }
}
