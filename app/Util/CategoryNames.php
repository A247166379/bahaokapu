<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Category;
use App\Model\ContentTranslation;
use InvalidArgumentException;

/** Authored category names share the established entity translation storage. */
final class CategoryNames
{
    public const LIMIT = 255;
    public const FIELDS = ['name_zh_tw' => 'zh-tw', 'name_en' => 'en', 'name_ru' => 'ru', 'name_vi' => 'vi'];

    /** Only present name fields are changes; an explicit empty translation means fallback. */
    public static function input(array $input): array
    {
        $names = [];
        foreach (['name' => '简体中文'] + self::FIELDS as $field => $label) {
            if (!array_key_exists($field, $input)) continue;
            $text = $input[$field];
            if (!is_string($text) || !mb_check_encoding($text, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f]/', $text) || strip_tags($text) !== $text
                || mb_strlen($text, 'UTF-8') > self::LIMIT) {
                throw new InvalidArgumentException('分类名称只允许纯文本，最多255个字符');
            }
            $text = trim($text);
            if ($field === 'name' && $text === '') throw new InvalidArgumentException('分类名称不能为空');
            $names[$field] = $text;
        }
        return $names;
    }

    /** Pure projection keeps stale or unreviewed text out of the category editor. */
    public static function fields(string $source, array $editions): array
    {
        $result = array_fill_keys(array_keys(self::FIELDS), '');
        $result['name_translation_stale'] = [];
        $hash = ContentRules::hash($source);
        foreach (self::FIELDS as $field => $locale) {
            $edition = $editions[$locale] ?? null;
            if (!is_array($edition)) continue;
            if (!hash_equals($hash, (string)($edition['source_hash'] ?? ''))) {
                $result['name_translation_stale'][] = $field;
                continue;
            }
            if (in_array((int)($edition['status'] ?? 0), [1, 2], true)) {
                $result[$field] = (string)($edition['text'] ?? '');
            }
        }
        return $result;
    }

    /** One lookup for the whole category tree, preserving every existing list attribute. */
    public static function attach(array $categories): array
    {
        $map = [];
        if ($categories !== [] && StoreContentService::ready()) {
            foreach (ContentTranslation::query()->where('entity_type', 'category')
                ->whereIn('entity_id', array_map('strval', array_column($categories, 'id')))
                ->where('field', 'name')->whereIn('locale', array_values(self::FIELDS))
                ->get(['entity_id', 'locale', 'text', 'status', 'source_hash']) as $edition) {
                $map[(string)$edition->entity_id][(string)$edition->locale] = $edition->getAttributes();
            }
        }
        foreach ($categories as &$category) {
            $category = array_replace($category, self::fields((string)$category['name'], $map[(string)$category['id']] ?? []));
        }
        unset($category);
        return $categories;
    }

    /** Caller holds the category row lock and transaction through category and edition saves. */
    public static function persist(Category $category, array $names): void
    {
        if ($names === []) return;
        if (!StoreContentService::ready()) throw new InvalidArgumentException('分类语言配置尚未就绪，请刷新后重试');
        $source = (string)$category->name;
        $metadata = ['source_hash' => ContentRules::hash($source), 'source_revision' => 1,
            'status' => 2, 'update_time' => date('Y-m-d H:i:s')];
        // The Simplified Chinese edition must always equal the canonical category source.
        ContentTranslation::query()->updateOrCreate(['entity_type' => 'category', 'entity_id' => (string)$category->id,
            'locale' => 'zh-cn', 'field' => 'name'], ['text' => $source] + $metadata);
        foreach (self::FIELDS as $field => $locale) {
            if (!array_key_exists($field, $names)) continue;
            $key = ['entity_type' => 'category', 'entity_id' => (string)$category->id, 'locale' => $locale, 'field' => 'name'];
            if ($names[$field] === '') ContentTranslation::query()->where($key)->delete();
            else ContentTranslation::query()->updateOrCreate($key, ['text' => $names[$field]] + $metadata);
        }
    }
}
