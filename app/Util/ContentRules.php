<?php
declare(strict_types=1);

namespace App\Util;

use InvalidArgumentException;

/** Pure validation shared by API, migrations, and tests. */
final class ContentRules
{
    public const LOCALES = ['zh-cn', 'zh-tw', 'en', 'ru', 'vi'];
    public const TYPES = ['announcement', 'tip', 'banner', 'navigation', 'footer', 'faq', 'article', 'policy', 'contact'];
    public const FIELDS = [
        'commodity' => ['name', 'description', 'delivery_message', 'leave_message', 'tags', 'widget'],
        'category' => ['name'],
        'config' => ['notice', 'shop_name', 'title', 'keywords', 'description', 'closed_message', 'commodity_name', 'contact_widget_title', 'contact_widget_name', 'contact_widget_tip'],
    ];

    public static function hash(mixed $source): string
    {
        if (!is_string($source)) {
            $source = json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return hash('sha256', $source);
    }

    public static function slug(string $slug): string
    {
        $slug = trim($slug);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', $slug)) {
            throw new InvalidArgumentException('地址标识只允许小写字母、数字、下划线和短横线，最多96字');
        }
        return $slug;
    }

    public static function document(array $doc): array
    {
        $clean = [];
        foreach (['title' => 250, 'summary' => 2000, 'body' => 100000] as $key => $limit) {
            $value = $doc[$key] ?? '';
            if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) {
                throw new InvalidArgumentException('标题、摘要或正文格式不正确，或长度超过限制');
            }
            $clean[$key] = trim($value);
        }
        return $clean;
    }

    public static function missing(string $type, array $source, array $translations): array
    {
        $missing = [];
        foreach (self::LOCALES as $locale) {
            $row = $translations[$locale] ?? null;
            $doc = $row['document'] ?? [];
            if (!$row || !in_array((int)($row['status'] ?? 0), [1, 2], true) || ($row['source_hash'] ?? '') !== self::hash($source)) {
                $missing[] = $locale;
                continue;
            }
            if (trim((string)($doc['title'] ?? '')) === '' || (in_array($type, ['faq', 'article', 'policy'], true) && trim(strip_tags((string)($doc['body'] ?? ''))) === '')) {
                $missing[] = $locale;
            }
        }
        return $missing;
    }

    public static function url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\\\\]/', $url) || str_starts_with($url, '//')) {
            throw new InvalidArgumentException('链接格式不正确');
        }
        if (str_starts_with($url, '/')) return $url;
        if (!preg_match('/^(https?:\/\/|mailto:|tel:)/i', $url)) {
            throw new InvalidArgumentException('链接只允许本站路径、HTTP(S)、邮件和电话');
        }
        return $url;
    }

    /** A widget translation may only change labels, not submitted values or validation. */
    public static function translatedObject(string $field, mixed $source, mixed $text): string
    {
        $original = is_string($source) ? json_decode($source, true) : $source;
        $translated = is_string($text) ? json_decode($text, true) : $text;
        if (!is_array($original) || !is_array($translated) || count($original) !== count($translated)) {
            throw new InvalidArgumentException('标签或表单译文必须保持原 JSON 项目数量');
        }
        foreach ($original as $i => $entry) {
            if (!is_array($entry) || !isset($translated[$i]) || !is_array($translated[$i])) throw new InvalidArgumentException('译文 JSON 结构不正确');
            $allowed = $field === 'tags' ? ['text'] : ['cn', 'placeholder', 'error', 'dict'];
            foreach ($translated[$i] as $key => $value) {
                if (!in_array($key, $allowed, true) && (!array_key_exists($key, $entry) || $value !== $entry[$key])) {
                    throw new InvalidArgumentException('译文不能修改表单类型、字段标识、必填规则或提交值');
                }
            }
            foreach ($entry as $key => $value) {
                if (!in_array($key, $allowed, true) && ($translated[$i][$key] ?? null) !== $value) throw new InvalidArgumentException('译文不能删除表单业务属性');
            }
            if ($field === 'widget' && isset($entry['dict'])) {
                $values = static function ($dict): array {
                    return array_map(static fn($pair) => explode('=', $pair, 2)[1] ?? '', explode(',', (string)$dict));
                };
                if ($values($entry['dict']) !== $values($translated[$i]['dict'] ?? '')) throw new InvalidArgumentException('表单选项译文不能修改提交值');
            }
            if ($field === 'widget' && !isset($entry['dict']) && isset($translated[$i]['dict'])) throw new InvalidArgumentException('译文不能新增表单提交选项');
            foreach ($allowed as $key) {
                if (isset($translated[$i][$key]) && (!is_string($translated[$i][$key]) || mb_strlen($translated[$i][$key]) > 4000 || strip_tags($translated[$i][$key]) !== $translated[$i][$key])) {
                    throw new InvalidArgumentException('表单和标签译文只允许纯文本');
                }
            }
        }
        return json_encode($translated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
