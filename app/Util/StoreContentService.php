<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use App\Model\ContentTranslation;
use App\Model\StoreContent;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Util\Lang;

final class StoreContentService
{
    private static ?bool $ready = null;
    public const TIP_TITLES = ['zh-cn' => '温馨提示', 'zh-tw' => '溫馨提示', 'en' => 'Friendly reminder', 'ru' => 'Важная информация', 'vi' => 'Lưu ý'];
    public const POLICY_LABELS = ['terms' => '服务条款', 'privacy' => '隐私政策', 'disclaimer' => '免责声明'];
    public const POLICY_SETTINGS_SLUG = 'policy-settings';

    /** Three web destinations and independently authored language blocks. */
    public static function policySettingsInput(array $input): array
    {
        if (array_diff(array_keys($input), ['id', 'revision', 'links', 'texts']) !== []) throw new \InvalidArgumentException('政策设置只能填写三个链接和说明内容');
        foreach (['id', 'revision'] as $field) if (!array_key_exists($field, $input) || !is_int($input[$field]) || $input[$field] < 0) throw new \InvalidArgumentException('政策设置编号与版本不正确');
        if ($input['id'] === 0 && $input['revision'] !== 0) throw new \InvalidArgumentException('新政策设置版本不正确');
        $changes = 0;
        foreach (['links', 'texts'] as $field) {
            if (!array_key_exists($field, $input)) continue;
            if (!is_array($input[$field])) throw new \InvalidArgumentException('政策链接或语言内容格式不正确');
            $allowed = $field === 'links' ? array_keys(self::POLICY_LABELS) : ContentRules::LOCALES;
            if (array_diff(array_keys($input[$field]), $allowed) !== []) throw new \InvalidArgumentException('政策链接或语言标识不正确');
            foreach ($input[$field] as $key => $value) {
                if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new \InvalidArgumentException('政策链接和说明须为有效文字');
                $input[$field][$key] = $field === 'links' ? self::bannerUrl($value) : self::policyText($value);
                $changes++;
            }
        }
        if ($changes === 0) throw new \InvalidArgumentException('请填写要保存的政策链接或说明内容');
        return $input;
    }

    public static function policyText(mixed $text): string
    {
        $text = self::policyStoredText($text);
        if (mb_strlen($text) > 10000) throw new \InvalidArgumentException('政策说明最多10000个字符，不能含空字符');
        return trim($text);
    }

    private static function policyStoredText(mixed $text): string
    {
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) throw new \InvalidArgumentException('政策说明须为有效文字');
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function policyBody(mixed $text): string
    {
        // Imported rich documents retain every visible paragraph; only new authored input has the editor limit.
        $text = trim(self::policyStoredText($text));
        $paragraphs = [];
        foreach (explode("\n", $text) as $line) {
            $safe = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            if (self::hasVisibleText($safe)) $paragraphs[] = '<p>' . $safe . '</p>';
        }
        return implode('', $paragraphs);
    }

    public static function isPolicySettings(StoreContent $row): bool
    {
        return $row->type === 'policy' && $row->slug === self::POLICY_SETTINGS_SLUG;
    }

    /** Read old values without altering policy references, articles or translated documents. */
    public static function policySettingsState(?StoreContent $row = null): array
    {
        $state = ['id' => 0, 'revision' => 0, 'links' => array_fill_keys(array_keys(self::POLICY_LABELS), ''), 'texts' => array_fill_keys(ContentRules::LOCALES, '')];
        $row ??= StoreContent::query()->where('type', 'policy')->where('slug', self::POLICY_SETTINGS_SLUG)->first();
        if ($row) {
            $payload = json_decode((string)$row->payload, true);
            if (!self::isPolicySettings($row) || !is_array($payload) || ($payload['kind'] ?? '') !== 'policy-settings') throw new \InvalidArgumentException('政策设置记录格式不正确，请联系管理员检查');
            $state['id'] = (int)$row->id; $state['revision'] = (int)$row->revision;
            foreach (self::POLICY_LABELS as $key => $_label) $state['links'][$key] = self::bannerDestination(is_string($payload['links'][$key] ?? null) ? $payload['links'][$key] : '');
            foreach (ContentRules::LOCALES as $locale) {
                $value = $payload['texts'][$locale] ?? '';
                try { $state['texts'][$locale] = trim(self::policyStoredText($value)); }
                catch (\InvalidArgumentException) { $state['texts'][$locale] = ''; }
            }
            return $state;
        }
        return self::policySettingsLegacyState();
    }

    /** Admin imports keep authored drafts; an unsaved public fallback keeps the old publication rules. */
    private static function policySettingsLegacyState(?string $publicLocale = null): array
    {
        $state = ['id' => 0, 'revision' => 0, 'links' => array_fill_keys(array_keys(self::POLICY_LABELS), ''), 'texts' => array_fill_keys(ContentRules::LOCALES, '')];
        $aliases = ['terms-of-service' => 'terms', 'privacy-policy' => 'privacy', 'disclaimer' => 'disclaimer'];
        $policies = StoreContent::query()->where('type', 'policy')->where('status', 1)->orderBy('sort')->orderBy('id')->get();
        $articleIds = [];
        foreach ($policies as $policy) {
            $reference = self::policyReference($policy->payload);
            if ($reference['placement'] === 'policy' && $reference['article_id'] > 0) $articleIds[] = $reference['article_id'];
        }
        $articles = StoreContent::query()->whereIn('type', ['article', 'faq'])->whereIn('id', $articleIds)->get()->keyBy('id');
        foreach ($policies as $policy) {
            $reference = self::policyReference($policy->payload);
            if ($reference['placement'] !== 'policy') continue;
            $article = $articles->get($reference['article_id']);
            $payload = json_decode((string)$policy->payload, true) ?: [];
            $key = $aliases[(string)$policy->slug] ?? $aliases[(string)($payload['legacy_alias'] ?? '')] ?? ($article ? ($aliases[(string)$article->slug] ?? null) : null);
            if ($key === null || $state['links'][$key] !== '') continue;
            if ($publicLocale !== null && (!$article || !self::articleAvailability($article->toArray(), self::editions((int)$article->id))[$publicLocale])) continue;
            if ($article && preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', (string)$article->slug)) $state['links'][$key] = '/help/' . $article->slug . '.html';
            else $state['links'][$key] = self::bannerDestination((string)$policy->url);
        }
        $copyQuery = StoreContent::query()->where('type', 'footer')->where('slug', 'policy-card-copy');
        if ($publicLocale !== null) $copyQuery->where('status', 1);
        $copy = $copyQuery->first();
        $copy ??= StoreContent::query()->where('type', 'footer')->where('status', 1)->where('body', '<>', '')->orderBy('sort')->orderBy('id')->first();
        if ($copy) {
            $state['texts']['zh-cn'] = self::tipPlainText((string)$copy->body);
            $hash = ContentRules::hash(self::sourceDocument($copy));
            foreach (self::editions((int)$copy->id) as $locale => $edition) {
                if (!in_array($locale, ContentRules::LOCALES, true)) continue;
                if ($publicLocale !== null && ($locale === 'zh-cn' || !in_array((int)$edition['status'], [1, 2], true)
                    || (string)$edition['source_hash'] !== $hash || (int)$edition['sourceRevision'] !== (int)$copy->source_revision)) continue;
                $state['texts'][$locale] = self::tipPlainText((string)($edition['document']['body'] ?? ''));
            }
        }
        return $state;
    }

    /** Public labels are fixed translation keys; description paragraphs contain only escaped text. */
    private static function policySettingsPublic(string $locale): array
    {
        $empty = ['id' => 0, 'links' => array_fill_keys(array_keys(self::POLICY_LABELS), ''), 'text' => '', 'body' => new Html('')];
        $row = StoreContent::query()->where('type', 'policy')->where('slug', self::POLICY_SETTINGS_SLUG)->first();
        if ($row && (int)$row->status !== 1) return $empty;
        try { $state = $row ? self::policySettingsState($row) : self::policySettingsLegacyState($locale); }
        catch (\InvalidArgumentException) { return $empty; }
        foreach ($state['links'] as &$url) $url = self::bannerDestination($url, $locale);
        unset($url);
        $text = $state['texts'][$locale];
        try { $body = self::policyBody($text); }
        catch (\InvalidArgumentException) { $text = $body = ''; }
        return ['id' => $state['id'], 'links' => $state['links'], 'text' => $text, 'body' => new Html($body)];
    }

    /** Shared image metadata and an optional independent five-language overlay. */
    public static function bannerInput(array $input): array
    {
        if (array_diff(array_keys($input), ['id', 'revision', 'image', 'url', 'sort', 'enabled', 'overlay']) !== []) throw new \InvalidArgumentException('轮播只能设置图片、跳转链接、排序、显示状态和轮播文案');
        foreach (['id', 'revision', 'sort'] as $field) {
            if (!array_key_exists($field, $input) || !is_int($input[$field])) throw new \InvalidArgumentException('轮播编号、版本和排序必须为整数');
        }
        if ($input['id'] < 0 || $input['revision'] < 0 || $input['sort'] < -100000 || $input['sort'] > 100000) throw new \InvalidArgumentException('轮播编号或排序超出范围');
        if ($input['id'] === 0 && $input['revision'] !== 0) throw new \InvalidArgumentException('新轮播版本不正确');
        if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) throw new \InvalidArgumentException('轮播显示状态必须为布尔值');
        if (!array_key_exists('image', $input) || !is_string($input['image']) || !mb_check_encoding($input['image'], 'UTF-8')) throw new \InvalidArgumentException('轮播图片须为有效地址文字');
        $input['image'] = self::bannerImage($input['image']);
        if (array_key_exists('url', $input)) {
            if (!is_string($input['url']) || !mb_check_encoding($input['url'], 'UTF-8')) throw new \InvalidArgumentException('轮播跳转链接须为有效地址文字');
            $input['url'] = self::bannerUrl($input['url']);
        }
        if (array_key_exists('overlay', $input)) $input['overlay'] = self::bannerOverlayInput($input['overlay']);
        return $input;
    }

    /** A visibility lifecycle action cannot carry editable content or coerced identities. */
    public static function bannerIdentityInput(array $input): array
    {
        if (count($input) !== 2 || !array_key_exists('id', $input) || !array_key_exists('revision', $input)
            || !is_int($input['id']) || $input['id'] <= 0 || !is_int($input['revision']) || $input['revision'] <= 0) {
            throw new \InvalidArgumentException('轮播编号和版本必须为正整数，删除或恢复只能提交编号和版本');
        }
        return $input;
    }

    /** Complete editor defaults; existing images gain no visible text automatically. */
    public static function bannerOverlayDefaults(): array
    {
        return ['version' => 1, 'enabled' => false, 'position' => 'left', 'title_size' => 28, 'text_size' => 14,
            'color' => '#ffffff', 'button_background' => '#e0f7ff', 'button_color' => '#0b2330',
            'texts' => array_fill_keys(ContentRules::LOCALES, ['eyebrow' => '', 'title' => '', 'description' => '', 'button_text' => '', 'button_url' => ''])];
    }

    /** Strict typed styles and plain UTF-8 text; HTML-like characters stay literal text. */
    public static function bannerOverlayInput(mixed $input): array
    {
        $defaults = self::bannerOverlayDefaults();
        if (!is_array($input) || array_diff(array_keys($input), array_keys($defaults)) !== []
            || array_diff(array_keys($defaults), array_keys($input)) !== []) throw new \InvalidArgumentException('轮播文案字段不完整或含不支持的设置');
        if ($input['version'] !== 1 || !is_bool($input['enabled'])) throw new \InvalidArgumentException('轮播文案版本或开关不正确');
        if (!is_string($input['position']) || !in_array($input['position'], ['left', 'center', 'right'], true)) throw new \InvalidArgumentException('轮播文案位置不正确');
        foreach (['title_size' => [18, 48], 'text_size' => [12, 22]] as $field => [$minimum, $maximum]) {
            if (!is_int($input[$field]) || $input[$field] < $minimum || $input[$field] > $maximum) throw new \InvalidArgumentException('轮播文案字号超出范围');
        }
        foreach (['color', 'button_background', 'button_color'] as $field) {
            if (!is_string($input[$field]) || preg_match('/^#[0-9a-fA-F]{6}$/D', $input[$field]) !== 1) throw new \InvalidArgumentException('轮播文案颜色须为六位十六进制颜色');
        }
        if (!is_array($input['texts']) || array_diff(array_keys($input['texts']), ContentRules::LOCALES) !== []) throw new \InvalidArgumentException('轮播文案语言不正确');
        $limits = ['eyebrow' => 100, 'title' => 160, 'description' => 5000, 'button_text' => 60, 'button_url' => 2000];
        $texts = $defaults['texts'];
        foreach ($input['texts'] as $locale => $document) {
            if (!is_array($document) || array_diff(array_keys($document), array_keys($limits)) !== []) throw new \InvalidArgumentException('轮播文案只允许纯文字和安全按钮链接');
            foreach ($document as $field => $value) {
                if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')
                    || mb_strlen($value, 'UTF-8') > $limits[$field]) throw new \InvalidArgumentException('轮播文案须为有效纯文字，且不能超过字段长度限制');
                $texts[$locale][$field] = $field === 'button_url' ? self::bannerUrl($value) : $value;
            }
        }
        $input['texts'] = $texts;
        return $input;
    }

    /** Invalid historical payloads disable only their overlay; the image stays public. */
    public static function bannerOverlayFromPayload(mixed $payload): array
    {
        try {
            if (!is_string($payload)) return self::bannerOverlayDefaults();
            $object = json_decode($payload, false, 64, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass || !property_exists($object, 'banner_overlay')) return self::bannerOverlayDefaults();
            $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            return self::bannerOverlayInput($decoded['banner_overlay']);
        } catch (\InvalidArgumentException | \JsonException) {
            return self::bannerOverlayDefaults();
        }
    }

    /** Current-language text only, without falling back to another language. */
    public static function bannerOverlayPublic(mixed $payload, string $locale): array
    {
        $overlay = self::bannerOverlayFromPayload($payload);
        $text = $overlay['texts'][$locale] ?? ['eyebrow' => '', 'title' => '', 'description' => '', 'button_text' => '', 'button_url' => ''];
        unset($overlay['texts']);
        $hasText = false;
        foreach (['eyebrow', 'title', 'description'] as $field) {
            if ((string)preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $text[$field]) !== '') $hasText = true;
        }
        $overlay['enabled'] = $overlay['enabled'] && $hasText;
        $text['button_url'] = self::publicLink($text['button_url'], $locale);
        return array_merge($overlay, $text);
    }

    /** Merge only the overlay; preserve unknown fields and nested JSON object shapes. */
    public static function bannerPayloadWithOverlay(mixed $payload, array $overlay): string
    {
        try {
            $object = is_string($payload) ? json_decode($payload, false, 64, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            throw new \InvalidArgumentException('历史轮播扩展设置格式不正确，无法安全保存文案');
        }
        if (!$object instanceof \stdClass) throw new \InvalidArgumentException('历史轮播扩展设置格式不正确，无法安全保存文案');
        $object->banner_overlay = self::bannerOverlayInput($overlay);
        $json = json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65535) throw new \InvalidArgumentException('轮播五语文案总长度过长，请缩短后保存');
        return $json;
    }

    /** A slide opens a web page, so mail, telephone and executable schemes are excluded. */
    public static function bannerUrl(string $url): string
    {
        if (!mb_check_encoding($url, 'UTF-8')) throw new \InvalidArgumentException('轮播跳转链接须为有效地址文字');
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) throw new \InvalidArgumentException('链接格式不正确');
        $url = ContentRules::url($url);
        if ($url === '' || str_starts_with($url, '/')) return $url;
        $parts = parse_url($url);
        if (!preg_match('#^https?://#i', $url) || !is_array($parts) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) throw new \InvalidArgumentException('跳转链接只允许本站路径或 HTTP(S) 地址');
        return $url;
    }

    /** Malformed historical destinations never become public links or admin input values. */
    private static function bannerDestination(string $value, ?string $locale = null): string
    {
        try { $value = self::bannerUrl($value); }
        catch (\InvalidArgumentException) { return ''; }
        return $locale === null ? $value : self::publicLink($value, $locale);
    }

    /** No data URI, protocol-relative address, file path or executable scheme. */
    public static function bannerImage(string $image): string
    {
        if (!mb_check_encoding($image, 'UTF-8')) throw new \InvalidArgumentException('轮播图片须为有效地址文字');
        $image = ContentRules::url($image);
        if ($image !== '' && !str_starts_with($image, '/') && !preg_match('#^https?://#i', $image)) throw new \InvalidArgumentException('轮播图片只允许本站或 HTTP(S) 地址');
        return $image;
    }

    /** Existing text-only slides may stay published, but cannot be created or re-enabled. */
    public static function bannerLegacyAvailable(array $row, array $editions): bool
    {
        if (($row['type'] ?? '') !== 'banner' || (int)($row['id'] ?? 0) <= 0 || (int)($row['status'] ?? 0) !== 1 || trim((string)($row['image'] ?? '')) !== '') return false;
        $source = ['title' => (string)($row['title'] ?? ''), 'summary' => (string)($row['summary'] ?? ''), 'body' => (string)($row['body'] ?? '')];
        if (!self::hasVisibleText($source['title']) && !self::hasVisibleText($source['body'])) return false;
        // Adding a language must not withdraw an already published legacy slide.
        $originalLocales = array_diff(ContentRules::LOCALES, ['vi']);
        foreach ($originalLocales as $locale) {
            $edition = $editions[$locale] ?? null;
            $doc = is_array($edition) ? ($edition['document'] ?? null) : null;
            if (!is_array($doc) || !is_string($doc['title'] ?? null) || !is_string($doc['body'] ?? null)) return false;
        }
        if (array_intersect(ContentRules::missing('banner', $source, $editions), $originalLocales) !== []) return false;
        foreach ($originalLocales as $locale) {
            $edition = $editions[$locale] ?? [];
            if ((int)($edition['sourceRevision'] ?? -1) !== (int)($row['source_revision'] ?? 0)) return false;
            $doc = $edition['document'] ?? [];
            if (!self::hasVisibleText((string)($doc['title'] ?? '')) && !self::hasVisibleText((string)($doc['body'] ?? ''))) return false;
        }
        return true;
    }

    public static function bannerDescriptor(StoreContent $row): array
    {
        return ['id' => (int)$row->id, 'revision' => (int)$row->revision, 'image' => ViewSafe::url((string)$row->image),
            'url' => self::bannerDestination((string)$row->url),
            'sort' => (int)$row->sort, 'enabled' => (int)$row->status === 1,
            'overlay' => self::bannerOverlayFromPayload($row->payload),
            'legacy' => trim((string)$row->image) === '' && (self::hasVisibleText((string)$row->title) || self::hasVisibleText((string)$row->body))];
    }

    /** Policy references contain identifiers only; old standalone policy bodies are never public. */
    public static function policyReference(mixed $payload): array
    {
        if (is_string($payload)) $payload = json_decode($payload, true);
        if (!is_array($payload)) return ['article_id' => 0, 'placement' => 'policy'];
        $placement = $payload['placement'] ?? 'policy';
        $id = $payload['article_id'] ?? 0;
        if (!in_array($placement, ['policy', 'contact'], true)) return ['article_id' => 0, 'placement' => 'policy'];
        if (!is_int($id) || $id <= 0) return ['article_id' => 0, 'placement' => $placement];
        return ['article_id' => $id, 'placement' => $placement];
    }

    /** Strict, pure validation for the narrow policy-button write endpoint. */
    public static function policyInput(array $input): array
    {
        if (array_diff(array_keys($input), ['id', 'revision', 'article_id', 'sort', 'publish', 'placement']) !== []) throw new \InvalidArgumentException('政策按钮只能设置文章、排序和发布状态');
        foreach (['id', 'revision', 'article_id', 'sort'] as $field) {
            if (!array_key_exists($field, $input) || !is_int($input[$field])) throw new \InvalidArgumentException('政策按钮编号和排序必须为整数');
        }
        if ($input['id'] < 0 || $input['revision'] < 0 || $input['article_id'] <= 0 || abs($input['sort']) > 100000) throw new \InvalidArgumentException('政策按钮编号或排序超出范围');
        if ($input['id'] === 0 && $input['revision'] !== 0) throw new \InvalidArgumentException('新文章入口版本不正确');
        if (!array_key_exists('publish', $input) || !is_bool($input['publish'])) throw new \InvalidArgumentException('政策按钮发布状态不正确');
        if (!array_key_exists('placement', $input)) $input['placement'] = 'policy';
        if (!in_array($input['placement'], ['policy', 'contact'], true)) throw new \InvalidArgumentException('文章引用位置不正确');
        return $input;
    }

    /** Exact source hash and revision are both required, including machine-ready editions. */
    public static function articleAvailability(array $article, array $editions): array
    {
        $availability = array_fill_keys(ContentRules::LOCALES, false);
        if (!in_array($article['type'] ?? '', ['article', 'faq'], true) || (int)($article['status'] ?? 0) !== 1
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', (string)($article['slug'] ?? ''))) return $availability;
        $source = ['title' => (string)($article['title'] ?? ''), 'summary' => (string)($article['summary'] ?? ''), 'body' => (string)($article['body'] ?? '')];
        foreach ($editions as $locale => $edition) {
            $document = is_array($edition) ? ($edition['document'] ?? null) : null;
            if (!is_array($document) || !is_string($document['title'] ?? null) || !is_string($document['body'] ?? null)) unset($editions[$locale]);
        }
        foreach (ContentRules::LOCALES as $locale) {
            $edition = self::documentEdition($source, (int)($article['source_revision'] ?? 0), $editions, $locale, true) ?? [];
            $body = (string)($edition['document']['body'] ?? '');
            $availability[$locale] = $edition !== []
                && self::hasVisibleText((string)($edition['document']['title'] ?? '')) && self::hasVisibleText($body);
        }
        return $availability;
    }

    /** Every storefront language requires its own current published edition. */
    public static function documentEdition(array $source, int $revision, array $editions, string $locale, bool $requireBody = false): ?array
    {
        if (!in_array($locale, ContentRules::LOCALES, true)) return null;
        foreach ([$locale] as $candidate) {
            $edition = $editions[$candidate] ?? null;
            $document = is_array($edition) ? ($edition['document'] ?? null) : null;
            if (!is_array($document) || !is_string($document['title'] ?? null) || !is_string($document['body'] ?? null)
                || !in_array((int)($edition['status'] ?? 0), [1, 2], true)
                || !hash_equals(ContentRules::hash($source), (string)($edition['source_hash'] ?? ''))
                || (int)($edition['sourceRevision'] ?? -1) !== $revision) continue;
            if (!self::hasVisibleText($document['title'])
                || ($requireBody && !self::hasVisibleText($document['body']))) continue;
            return $edition + ['contentLocale' => $candidate];
        }
        return null;
    }

    /** SEO advertises only real current article editions, never a presentation fallback. */
    public static function articleHasEdition(int $id, string $locale): bool
    {
        if ($id <= 0 || !in_array($locale, ContentRules::LOCALES, true) || !self::ready()) return false;
        $row = StoreContent::query()->whereIn('type', ['article', 'faq'])->where('status', 1)->find($id);
        if (!$row) return false;
        $editions = self::editions($id);
        $edition = self::documentEdition(self::sourceDocument($row), (int)$row->source_revision, $editions, $locale, true);
        return $edition !== null && $edition['contentLocale'] === $locale
            && self::articleAvailability($row->toArray(), $editions)[$locale];
    }

    private static function hasVisibleText(string $html): bool
    {
        $html = (string)preg_replace('#<(?:script|style)\b[^>]*>.*?</(?:script|style)>#is', '', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string)preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $text) !== '';
    }

    /** Plain multi-line text only. HTML-like text is rendered visibly, never executed. */
    public static function tipText(mixed $text): string
    {
        if (!is_string($text) || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) throw new \InvalidArgumentException('温馨提示须为有效文字，最多10000个字符');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (mb_strlen($text) > 10000) throw new \InvalidArgumentException('温馨提示须为有效文字，最多10000个字符');
        return trim($text);
    }

    public static function tipDocument(string $locale, mixed $text): array
    {
        if (!isset(self::TIP_TITLES[$locale])) throw new \InvalidArgumentException('温馨提示语言不正确');
        $text = self::tipText($text);
        $paragraphs = [];
        foreach (explode("\n", $text) as $line) {
            $safe = htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (self::hasVisibleText($safe)) $paragraphs[] = '<p>' . $safe . '</p>';
        }
        return ['title' => self::TIP_TITLES[$locale], 'summary' => '', 'body' => implode('', $paragraphs)];
    }

    public static function tipPlainText(string $body): string
    {
        $body = (string)preg_replace('#<(?:script|style)\b[^>]*>.*?</(?:script|style)>#is', '', $body);
        $body = (string)preg_replace('#<br\s*/?>#i', "\n", $body);
        $body = (string)preg_replace('#</(?:p|div|li|h[1-6])\s*>#i', "\n", $body);
        return trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Read-only availability check: request handlers must never create tables. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = DB::schema()->hasTable('store_content') && DB::schema()->hasTable('content_translation');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function bundle(?string $locale = null): array
    {
        $bundle = ['announcement' => null, 'tips' => [], 'banners' => [], 'slides' => [], 'navigation' => [], 'footer' => [], 'faq' => [], 'articles' => [], 'helpArticles' => [], 'policies' => [], 'policySettings' => ['id' => 0, 'links' => [], 'text' => '', 'body' => new Html('')], 'contact' => [], 'contactArticle' => null];
        $locale = $locale ?? Lang::detect();
        if (!in_array($locale, ContentRules::LOCALES, true) || !self::ready()) return $bundle;
        $bundle['policySettings'] = self::policySettingsPublic($locale);
        // Unpublished references are also read so legacy /terms links can be hidden.
        $rows = StoreContent::query()->whereIn('type', ContentRules::TYPES)->where(static fn($query) => $query->where('status', 1)->orWhere('type', 'policy'))->orderBy('sort')->orderBy('id')->get();
        if ($rows->isEmpty()) return $bundle;
        $translated = ContentTranslation::query()->where('entity_type', 'store')->whereIn('entity_id', $rows->pluck('id')->map(static fn($id) => (string)$id)->all())->where('locale', $locale)->where('field', 'document')->whereIn('status', [1, 2])->get()->groupBy('entity_id');
        $map = ['tip' => 'tips', 'banner' => 'banners', 'article' => 'articles'];
        $helpSlugs = []; $articleEntries = [];
        foreach ($rows as $row) {
            if ($row->type === 'policy' || (int)$row->status !== 1 || ($row->type === 'tip' && $row->slug !== 'warm-tips')) continue;
            // An image is shared by all languages. Its availability does not
            // depend on old caption documents or external translation jobs.
            if ($row->type === 'banner' && trim((string)$row->image) !== '') {
                try { $image = self::bannerImage((string)$row->image); }
                catch (\InvalidArgumentException) { continue; }
                $bundle['banners'][] = ['id' => (int)$row->id, 'type' => 'banner', 'slug' => (string)$row->slug,
                    'title' => '', 'summary' => '', 'body' => new Html(''), 'image' => $image,
                    'url' => self::bannerDestination((string)$row->url, $locale), 'payload' => [],
                    'overlay' => self::bannerOverlayPublic($row->payload, $locale), 'sort' => (int)$row->sort,
                    'sourceRevision' => (int)$row->source_revision];
                continue;
            }
            $editions = [];
            foreach ($translated->get((string)$row->id, []) as $edition) {
                $editions[(string)$edition->locale] = ['document' => json_decode((string)$edition->text, true),
                    'status' => (int)$edition->status, 'source_hash' => (string)$edition->source_hash,
                    'sourceRevision' => (int)$edition->source_revision];
            }
            $edition = self::documentEdition(self::sourceDocument($row), (int)$row->source_revision, $editions, $locale, in_array($row->type, ['article', 'faq'], true));
            if ($edition === null) continue;
            $doc = $edition['document'];
            $safeBody = RichHtml::sanitize((string)($doc['body'] ?? ''), false);
            $entry = [
                'id' => (int)$row->id, 'type' => $row->type, 'slug' => $row->slug,
                'title' => (string)($doc['title'] ?? ''), 'summary' => (string)($doc['summary'] ?? ''),
                'body' => new Html(RichHtml::present($safeBody)),
                'url' => $row->type === 'banner' ? self::bannerDestination((string)$row->url, $locale) : self::publicLink((string)$row->url, $locale), 'image' => ViewSafe::url((string)$row->image),
                'payload' => json_decode((string)$row->payload, true) ?: [], 'sort' => (int)$row->sort,
                'sourceRevision' => (int)$row->source_revision,
                'contentLocale' => $edition['contentLocale'],
            ];
            if (in_array($row->type, ['faq', 'article'], true)
                && preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', (string)$row->slug)
                && !isset($helpSlugs[$row->slug])
                && self::articleAvailability($row->toArray(), [$locale => ['document' => ['title' => $entry['title'], 'body' => $safeBody],
                    'status' => $edition['status'], 'source_hash' => $edition['source_hash'],
                    'sourceRevision' => $edition['sourceRevision']]])[$locale]) {
                // Older FAQ and article rows used separate slug namespaces. Resolve
                // historical collisions in the same sort/id order for list and detail.
                $helpSlugs[$row->slug] = true;
                $entry['helpSummary'] = self::policyExcerpt($entry['summary'], '');
                $entry['helpUrl'] = StoreLocale::url('/help/' . $row->slug, $locale);
                $bundle['helpArticles'][] = $entry;
                $entry['excerpt'] = self::policyExcerpt($entry['summary'], $safeBody);
                $articleEntries[(int)$row->id] = $entry;
            }
            if ($row->type === 'announcement') {
                if ($bundle['announcement'] === null) $bundle['announcement'] = $entry;
            } else {
                $key = $map[$row->type] ?? $row->type;
                if (array_key_exists($key, $bundle)) $bundle[$key][] = $entry;
            }
        }
        $aliases = [];
        foreach ($rows as $row) {
            if ($row->type !== 'policy') continue;
            $reference = self::policyReference($row->payload);
            $aliases[(string)$row->slug] = null;
            $payload = json_decode((string)$row->payload, true) ?: [];
            if (is_string($payload['legacy_alias'] ?? null)) $aliases[$payload['legacy_alias']] = null;
            $target = $articleEntries[$reference['article_id']] ?? null;
            if ((int)$row->status !== 1 || !$target) continue;
            $target['targetArticleId'] = $reference['article_id'];
            $target['articleSlug'] = $target['slug'];
            $target['policyId'] = (int)$row->id;
            if ($reference['placement'] === 'contact') {
                if ($row->slug === 'contact-page' && $bundle['contactArticle'] === null) $bundle['contactArticle'] = $target;
                continue;
            }
            $entry = array_merge($target, ['id' => (int)$row->id, 'type' => 'policy', 'slug' => (string)$row->slug,
                'url' => $target['helpUrl'], 'sort' => (int)$row->sort, 'payload' => ['article_id' => $reference['article_id'], 'placement' => 'policy']]);
            $bundle['policies'][] = $entry;
            $aliases[(string)$row->slug] = $entry['helpUrl'];
            if (is_string($payload['legacy_alias'] ?? null)) $aliases[$payload['legacy_alias']] = $entry['helpUrl'];
        }
        foreach (['footer', 'navigation'] as $key) {
            $entries = [];
            foreach ($bundle[$key] as $entry) {
                $path = (string)(parse_url($entry['url'], PHP_URL_PATH) ?: '');
                if (str_starts_with($entry['url'], '/') && !str_starts_with($entry['url'], '//')
                    && preg_match('#^/(?:zh-tw/|en/|ru/|vi/)?terms/([a-z0-9][a-z0-9_-]{0,95})(?:\.html)?/?$#D', $path, $match) && array_key_exists($match[1], $aliases)) {
                    if ($aliases[$match[1]] === null) continue;
                    $entry['url'] = $aliases[$match[1]];
                }
                $entries[] = $entry;
            }
            $bundle[$key] = $entries;
        }
        $bundle['slides'] = $bundle['banners'];
        return $bundle;
    }

    /** Admin choices include drafts; availability means the exact public help URL resolves to this ID. */
    public static function articleOptions(): array
    {
        $available = array_fill_keys(ContentRules::LOCALES, []);
        foreach (ContentRules::LOCALES as $locale) {
            foreach (self::helpArticles($locale) as $article) $available[$locale][(int)$article['id']] = true;
        }
        $options = [];
        foreach (StoreContent::query()->whereIn('type', ['article', 'faq'])->orderBy('sort')->orderBy('id')->get() as $row) {
            $availability = []; $urls = []; $missing = [];
            $validSlug = (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', (string)$row->slug);
            foreach (ContentRules::LOCALES as $locale) {
                $availability[$locale] = isset($available[$locale][(int)$row->id]);
                $urls[$locale] = $validSlug ? StoreLocale::url('/help/' . $row->slug, $locale) : '';
                if (!$availability[$locale]) $missing[] = $locale;
            }
            $options[] = ['id' => (int)$row->id, 'title' => (string)$row->title, 'slug' => (string)$row->slug,
                'status' => (int)$row->status, 'urls' => $urls, 'availability' => $availability, 'missing' => $missing];
        }
        return $options;
    }

    public static function policyDescriptor(StoreContent $row, array $articles): array
    {
        $reference = self::policyReference($row->payload);
        $target = null;
        foreach ($articles as $article) if ((int)$article['id'] === $reference['article_id']) { $target = $article; break; }
        return ['id' => (int)$row->id, 'type' => 'policy', 'revision' => (int)$row->revision,
            'status' => (int)$row->status, 'sort' => (int)$row->sort, 'article_id' => $reference['article_id'],
            'placement' => $reference['placement'], 'title' => (string)($target['title'] ?? ''), 'slug' => (string)$row->slug,
            'article_slug' => (string)($target['slug'] ?? ''), 'urls' => $target['urls'] ?? array_fill_keys(ContentRules::LOCALES, ''),
            'availability' => $target['availability'] ?? array_fill_keys(ContentRules::LOCALES, false),
            'missing' => $target['missing'] ?? ContentRules::LOCALES];
    }

    /** Public navigation links are canonical in server HTML as well as in the browser. */
    private static function publicLink(string $value, string $locale): string
    {
        $value = ViewSafe::url($value);
        return StoreLocale::localizePublicLink($value, null, $locale);
    }

    /** Published, current reviewed editions for the public article-based help center. */
    public static function helpArticles(?string $locale = null): array
    {
        return self::bundle($locale)['helpArticles'];
    }

    /** Plain text only; the normal ViewSafe pass escapes this alongside titles. */
    private static function policyExcerpt(string $summary, string $safeBody): string
    {
        $extract = static function (string $html, bool $paragraphOnly): string {
            if (trim($html) === '') return '';
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return '';
                $xpath = new \DOMXPath($dom);
                // Never turn styles or scripts into visible summary text.
                foreach ($xpath->query('//style | //script') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                foreach ($xpath->query('//br') as $node) {
                    $node->parentNode?->replaceChild($dom->createTextNode(' '), $node);
                }
                $nodes = $paragraphOnly ? $dom->getElementsByTagName('p') : $dom->getElementsByTagName('body');
                foreach ($nodes as $node) {
                    $text = trim((string)preg_replace('/[\s\p{Z}]+/u', ' ', $node->textContent));
                    if ($text !== '') return $text;
                }
                return '';
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        };
        $text = $extract($summary, false);
        return $text !== '' ? $text : $extract($safeBody, true);
    }

    public static function sourceDocument(StoreContent $row): array
    {
        return ['title' => (string)$row->title, 'summary' => (string)$row->summary, 'body' => (string)$row->body];
    }

    public static function editions(int $id): array
    {
        $editions = [];
        foreach (ContentTranslation::query()->where('entity_type', 'store')->where('entity_id', (string)$id)->where('field', 'document')->get() as $row) {
            $editions[$row->locale] = ['document' => json_decode((string)$row->text, true) ?: [], 'status' => (int)$row->status, 'source_hash' => (string)$row->source_hash, 'sourceRevision' => (int)$row->source_revision];
        }
        return $editions;
    }

    /** Entity source fields are narrowly whitelisted so admin translations cannot expose credentials. */
    public static function entitySource(string $type, string $id): array
    {
        if (!isset(ContentRules::FIELDS[$type])) throw new \InvalidArgumentException('不支持的翻译实体');
        if ($type === 'config') {
            if ($id !== 'site') throw new \InvalidArgumentException('配置实体不正确');
            $source = [];
            foreach (ContentRules::FIELDS['config'] as $key) $source[$key] = (string)(Config::get($key) ?? '');
            return $source;
        }
        if (!ctype_digit($id) || (int)$id <= 0) throw new \InvalidArgumentException('实体 ID 不正确');
        $model = $type === 'commodity' ? \App\Model\Commodity::class : \App\Model\Category::class;
        $row = $model::query()->where('id', (int)$id)->first(ContentRules::FIELDS[$type]);
        if (!$row) throw new \InvalidArgumentException('商品或分类不存在');
        return $row->getAttributes();
    }

    public static function entityEditions(string $type, string $id, array $source): array
    {
        $result = array_fill_keys(ContentRules::LOCALES, []);
        foreach (ContentTranslation::query()->where('entity_type', $type)->where('entity_id', $id)->get() as $row) {
            if (!isset($result[$row->locale]) || !array_key_exists($row->field, $source)) continue;
            $result[$row->locale][$row->field] = ['text' => (string)$row->text, 'reviewed' => (int)$row->status === 2, 'machine' => (int)$row->status === 1, 'stale' => !hash_equals(ContentRules::hash($source[$row->field]), (string)$row->source_hash)];
        }
        return $result;
    }

    /** Return null when missing or stale; the established Lang fallback remains intact. */
    public static function entityValue(string $type, string $id, string $field, mixed $source, ?string $locale = null): ?string
    {
        $locale = $locale ?? Lang::detect();
        if (!self::ready() || !in_array($locale, ContentRules::LOCALES, true) || !in_array($field, ContentRules::FIELDS[$type] ?? [], true)) return null;
        $row = ContentTranslation::query()->where('entity_type', $type)->where('entity_id', $id)->where('locale', $locale)->where('field', $field)->whereIn('status', [1, 2])->first(['text', 'source_hash']);
        if (!$row || !hash_equals(ContentRules::hash($source), (string)$row->source_hash)) return null;
        return (string)$row->text;
    }

    /** Bulk look-up keeps product/category lists from making a query per field per item. */
    public static function entityMap(string $type, array $ids, ?string $locale = null): array
    {
        $locale = $locale ?? Lang::detect();
        if ($ids === [] || !self::ready() || !in_array($locale, ContentRules::LOCALES, true)) return [];
        $map = [];
        foreach (ContentTranslation::query()->where('entity_type', $type)->whereIn('entity_id', array_map('strval', $ids))->where('locale', $locale)->whereIn('status', [1, 2])->get(['entity_id', 'field', 'text', 'source_hash']) as $row) {
            $map[$row->entity_id][$row->field] = ['text' => (string)$row->text, 'source_hash' => (string)$row->source_hash];
        }
        return $map;
    }
}
