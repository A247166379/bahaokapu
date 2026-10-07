<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ContentTranslation;
use App\Model\ManageLog;
use App\Model\StoreContent as Content;
use App\Util\ContentRules;
use App\Util\AutoTranslationService;
use App\Util\RichHtml;
use App\Util\StoreContentService as Service;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class StoreContent extends Manage
{
    private function guard(): void
    {
        $manage = $this->getManage();
        if (!$manage || (int)$manage->type !== 0) throw new JSONException('仅站点管理员可以维护五语内容');
        if (!Service::ready()) throw new JSONException('五语内容表尚未迁移，请先执行部署迁移');
    }

    /** Hex transport avoids WAF false positives; content still passes strict validation and purifier. */
    private function input(): array
    {
        $hex = (string)($_POST['payload_hex'] ?? '');
        if ($hex === '' || strlen($hex) > 4000000 || strlen($hex) % 2 || !ctype_xdigit($hex)) throw new JSONException('提交内容编码不正确');
        $json = hex2bin($hex);
        if ($json === false || !mb_check_encoding($json, 'UTF-8')) throw new JSONException('提交内容不是有效 UTF-8');
        try { $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new JSONException('提交内容不是有效 JSON'); }
        if (!is_array($data)) throw new JSONException('提交内容格式不正确');
        return $data;
    }

    private static function document(mixed $input): array
    {
        if (!is_array($input)) throw new \InvalidArgumentException('译文格式不正确');
        $doc = ContentRules::document($input);
        $doc['title'] = strip_tags($doc['title']);
        $doc['summary'] = strip_tags($doc['summary']);
        $doc['body'] = RichHtml::sanitize($doc['body'], false);
        return $doc;
    }

    public function data(): array
    {
        $this->guard();
        $query = Content::query()->whereIn('type', ContentRules::TYPES)->where(static function ($query): void {
            $query->where('type', '<>', 'policy')->orWhere('slug', '<>', Service::POLICY_SETTINGS_SLUG);
        });
        $type = (string)($_POST['type'] ?? '');
        // Articles and historical questions are maintained in one article center.
        if (in_array($type, ['article', 'faq'], true)) $query->whereIn('type', ['article', 'faq']);
        elseif ($type !== '' && in_array($type, ContentRules::TYPES, true)) $query->where('type', $type);
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = min(100, max(1, (int)($_POST['limit'] ?? 50)));
        $total = (clone $query)->count();
        $list = [];
        if (!in_array($type, ['article', 'faq'], true)) $query->orderBy('type');
        $articles = null;
        foreach ($query->orderBy('sort')->orderBy('id')->forPage($page, $limit)->get() as $row) {
            if ($row->type === 'banner') {
                $list[] = Service::bannerDescriptor($row);
                continue;
            }
            if ($row->type === 'policy') {
                $articles ??= Service::articleOptions();
                $list[] = Service::policyDescriptor($row, $articles);
                continue;
            }
            $entry = $row->toArray();
            $entry['missing'] = ContentRules::missing((string)$row->type, Service::sourceDocument($row), Service::editions((int)$row->id));
            unset($entry['body']);
            $list[] = $entry;
        }
        return $this->json(200, 'success', ['list' => $list, 'total' => $total, 'auto_translation' => AutoTranslationService::enabled()]);
    }

    public function get(): array
    {
        $this->guard();
        $row = Content::query()->find((int)($_POST['id'] ?? 0));
        if (!$row) throw new JSONException('内容不存在');
        if ($row->type === 'policy') throw new JSONException('政策与联系入口只能选择文章，请在对应设置中编辑');
        if ($row->type === 'tip') throw new JSONException('温馨提示为整块文字，请使用温馨提示设置');
        if (in_array($row->type, ['banner', 'banner-trash'], true)) throw new JSONException('轮播只维护图片，请使用轮播图片设置');
        return $this->json(200, 'success', ['item' => $row->toArray(), 'sourceHash' => ContentRules::hash(Service::sourceDocument($row)), 'translations' => Service::editions((int)$row->id), 'auto_translation' => AutoTranslationService::enabled()]);
    }

    /** Private draft preview: no public URL, no script execution, and no database mutation. */
    public function preview(): array
    {
        $this->guard(); $input = $this->input();
        try { $doc = self::document($input['document'] ?? []); }
        catch (\InvalidArgumentException $e) { throw new JSONException($e->getMessage()); }
        $locale = (string)($input['locale'] ?? 'zh-cn');
        if (!in_array($locale, ContentRules::LOCALES, true)) throw new JSONException('预览语言不正确');
        $escape = static fn(string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="' . $escape($locale) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src https: http: data:; base-uri &#39;none&#39;; form-action &#39;none&#39;"><title>' . $escape($doc['title']) . '</title><style>body{font:16px/1.7 system-ui,sans-serif;margin:0;padding:24px;color:#202124;background:#fff;overflow-wrap:anywhere}main{max-width:850px;margin:auto}h1{font-size:28px;line-height:1.3}p{margin:0 0 1em}img{max-width:100%;height:auto}table{display:block;max-width:100%;overflow:auto}a{color:#3167c9}pre{overflow:auto}blockquote{padding:12px;border-left:3px solid #ddd}*{box-sizing:border-box}</style><main><h1>' . $escape($doc['title']) . '</h1>' . ($doc['summary'] === '' ? '' : '<p>' . $escape($doc['summary']) . '</p>') . RichHtml::present($doc['body']) . '</main></html>';
        return $this->json(200, 'success', ['html' => $html]);
    }

    public function save(): array
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new JSONException('文章保存与下架仅接受 POST 请求');
        $this->guard();
        $data = $this->input();
        $automatic = AutoTranslationService::enabled();
        try {
            $type = (string)($data['type'] ?? '');
            if (!in_array($type, ContentRules::TYPES, true)) throw new \InvalidArgumentException('内容类型不正确');
            if (in_array($type, ['policy', 'tip', 'banner'], true)) throw new \InvalidArgumentException('政策入口、温馨提示和轮播请使用对应设置，不能单独编辑正文');
            $slug = ContentRules::slug((string)($data['slug'] ?? ''));
            $editions = [];
            foreach (ContentRules::LOCALES as $locale) {
                $entry = $data['translations'][$locale] ?? [];
                $editions[$locale] = ['document' => self::document((array)($entry['document'] ?? [])), 'status' => ($entry['reviewed'] ?? false) === true ? 2 : 0];
            }
            $source = $editions['zh-cn']['document'];
            if ($source['title'] === '') throw new \InvalidArgumentException('请填写简体中文标题');
            $editions['zh-cn']['status'] = 2;
            $url = ContentRules::url((string)($data['url'] ?? ''));
            $image = ContentRules::url((string)($data['image'] ?? ''));
            if ($image !== '' && !str_starts_with($image, '/') && !preg_match('#^https?://#i', $image)) throw new \InvalidArgumentException('图片只允许本站或 HTTP(S) 地址');
            $payload = $data['payload'] ?? [];
            if (!is_array($payload)) throw new \InvalidArgumentException('扩展设置必须为 JSON 对象');
            if ($type === 'contact' && array_key_exists('value', $payload)) {
                $value = $payload['value'];
                if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 256) {
                    throw new \InvalidArgumentException('联系账号必须为有效文字，不能含空字符，最多256个字符');
                }
            }
            // Structured settings are strings/scalars only and are never rendered as executable HTML.
            $walk = static function (mixed $value) use (&$walk): mixed {
                if (is_array($value)) return array_map($walk, $value);
                if (is_string($value)) return strip_tags($value);
                if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
                throw new \InvalidArgumentException('扩展设置字段不正确');
            };
            $cleanPayload = $walk($payload);
            // Contact values remain exact plain strings; frontend ViewSafe escapes them recursively.
            if ($type === 'contact' && array_key_exists('value', $payload)) $cleanPayload['value'] = $payload['value'];
            $payloadJson = json_encode($cleanPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($payloadJson) > 20000) throw new \InvalidArgumentException('扩展设置最多20KB');
            $id = DB::connection()->transaction(function () use ($data, $type, $slug, $source, $editions, $url, $image, $payloadJson, $automatic): int {
                $id = (int)($data['id'] ?? 0);
                $row = $id > 0 ? Content::query()->lockForUpdate()->find($id) : new Content();
                if (!$row) throw new \InvalidArgumentException('内容已被删除');
                if ($id > 0 && in_array($row->type, ['policy', 'tip', 'banner', 'banner-trash'], true)) throw new \InvalidArgumentException('政策入口、温馨提示和轮播不能转换为其他内容类型');
                if ($id > 0 && (int)($data['revision'] ?? 0) !== (int)$row->revision) throw new \InvalidArgumentException('内容已被其他窗口修改，请刷新后重试');
                $slugTypes = in_array($type, ['article', 'faq'], true) ? ['article', 'faq'] : [$type];
                if (Content::query()->whereIn('type', $slugTypes)->where('slug', $slug)->where('id', '<>', $id)->exists()) throw new \InvalidArgumentException(in_array($type, ['article', 'faq'], true) ? '文章地址标识已存在，请使用其他标识' : '同类型的地址标识已存在');
                $hash = ContentRules::hash($source);
                $changed = !$id || !hash_equals(ContentRules::hash(Service::sourceDocument($row)), $hash);
                $sourceRevision = $id ? (int)$row->source_revision + (int)$changed : 1;
                $states = $editions;
                foreach ($states as &$edition) $edition['source_hash'] = $hash;
                unset($edition);
                $publish = ($data['publish'] ?? false) === true;
                $missing = ContentRules::missing($type, $source, $states);
                if ($publish && !$automatic && $missing !== []) throw new \InvalidArgumentException('发布前请补齐并确认五语内容：' . implode('、', $missing));
                $row->fill(['type' => $type, 'slug' => $slug, 'title' => $source['title'], 'summary' => $source['summary'], 'body' => $source['body'], 'url' => $url, 'image' => $image, 'payload' => $payloadJson, 'sort' => max(-100000, min(100000, (int)($data['sort'] ?? 0))), 'status' => $publish ? 1 : 0, 'source_revision' => $sourceRevision, 'revision' => $id ? (int)$row->revision + 1 : 1, 'update_time' => date('Y-m-d H:i:s')]);
                if (!$id) $row->create_time = date('Y-m-d H:i:s');
                $row->save();
                foreach ($editions as $locale => $edition) {
                    // A metadata-only edit must not demote a current machine translation
                    // merely because its human-review checkbox is correctly unchecked.
                    if (!$changed && $edition['status'] === 0) {
                        $current = ContentTranslation::query()->where('entity_type', 'store')->where('entity_id', (string)$row->id)->where('locale', $locale)->where('field', 'document')->first();
                        if ($current && (int)$current->status === 1 && hash_equals($hash, (string)$current->source_hash)
                            && ContentRules::hash(json_decode((string)$current->text, true) ?: []) === ContentRules::hash($edition['document'])) $edition['status'] = 1;
                    }
                    ContentTranslation::query()->updateOrCreate(['entity_type' => 'store', 'entity_id' => (string)$row->id, 'locale' => $locale, 'field' => 'document'], ['text' => json_encode($edition['document'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'source_hash' => $hash, 'source_revision' => $sourceRevision, 'status' => $edition['status'], 'update_time' => date('Y-m-d H:i:s')]);
                }
                return (int)$row->id;
            });
        } catch (\InvalidArgumentException | \JsonException $e) { throw new JSONException($e->getMessage()); }
        catch (\Illuminate\Database\QueryException $e) {
            if ((string)$e->getCode() === '23000') throw new JSONException('内容已由其他窗口创建或修改，请刷新列表后重试');
            throw $e;
        }
        ManageLog::log($this->getManage(), '[五语店铺内容]保存内容#' . $id);
        return $this->json(200, '保存成功', ['id' => $id, 'auto_translation' => $automatic]);
    }

    public function unpublish(): array
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new JSONException('文章保存与下架仅接受 POST 请求');
        $this->guard();
        $rawId = $_POST['id'] ?? null;
        if ((!is_int($rawId) && !is_string($rawId)) || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new JSONException('请选择有效的内容');
        $id = (int)$rawId;
        if (Content::query()->where('id', $id)->where('type', 'policy')->where('slug', Service::POLICY_SETTINGS_SLUG)->exists()) throw new JSONException('政策设置请通过专属设置保存');
        if (Content::query()->where('id', $id)->whereIn('type', ['banner', 'banner-trash'])->exists()) throw new JSONException('轮播显示状态请通过轮播图片设置保存');
        $affected = Content::query()->where('id', $id)->whereNotIn('type', ['banner', 'banner-trash'])->update(['status' => 0, 'revision' => DB::raw('revision + 1'), 'update_time' => date('Y-m-d H:i:s')]);
        if ($affected === 0) throw new JSONException('内容不存在或状态已变化，请刷新列表后重试');
        ManageLog::log($this->getManage(), '[五语店铺内容]下架内容#' . $id);
        return $this->json(200, '已下架');
    }

    public function bannerData(): array
    {
        $this->guard();
        $query = Content::query()->where('type', 'banner');
        $total = (clone $query)->count();
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = min(100, max(1, (int)($_POST['limit'] ?? 50)));
        $list = [];
        foreach ($query->orderBy('sort')->orderBy('id')->forPage($page, $limit)->get() as $row) $list[] = Service::bannerDescriptor($row);
        $trashPage = max(1, (int)($_POST['trash_page'] ?? 1));
        $trashLimit = min(100, max(1, (int)($_POST['trash_limit'] ?? 50)));
        $trashQuery = Content::query()->where('type', 'banner-trash');
        $trashTotal = (clone $trashQuery)->count(); $trash = [];
        foreach ($trashQuery->orderBy('sort')->orderBy('id')->forPage($trashPage, $trashLimit)->get() as $row) $trash[] = Service::bannerDescriptor($row);
        return $this->json(200, 'success', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'trash' => $trash, 'trash_total' => $trashTotal, 'trash_page' => $trashPage, 'trash_limit' => $trashLimit]);
    }

    public function bannerDelete(): array { return $this->bannerTransition(false); }

    public function bannerRestore(): array { return $this->bannerTransition(true); }

    /** Archive only the content identity; files, source fields and language editions stay untouched. */
    private function bannerTransition(bool $restore): array
    {
        $this->guard();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new JSONException('请使用 POST 删除或恢复');
        try {
            $data = Service::bannerIdentityInput($this->input());
            $result = DB::connection()->transaction(function () use ($data, $restore): array {
                $row = Content::query()->lockForUpdate()->find($data['id']);
                if (!$row) throw new \InvalidArgumentException('轮播不存在，请刷新列表');
                if ((int)$row->revision !== $data['revision']) throw new \InvalidArgumentException('轮播已被其他窗口修改，请刷新后重试');
                if ($row->type !== ($restore ? 'banner-trash' : 'banner')) throw new \InvalidArgumentException('轮播状态已变化，请刷新后重试');
                if ($restore && Content::query()->where('type', 'banner')->where('slug', (string)$row->slug)->where('id', '<>', (int)$row->id)->exists()) {
                    throw new \InvalidArgumentException('相同标识的轮播已存在，无法恢复，请刷新后检查');
                }
                $row->fill(['type' => $restore ? 'banner' : 'banner-trash', 'revision' => (int)$row->revision + 1,
                    'update_time' => date('Y-m-d H:i:s')]);
                $row->save();
                return ['id' => (int)$row->id, 'revision' => (int)$row->revision, 'item' => Service::bannerDescriptor($row)];
            });
        } catch (\InvalidArgumentException $exception) { throw new JSONException($exception->getMessage()); }
        catch (\Illuminate\Database\QueryException $exception) {
            if ((string)$exception->getCode() === '23000') throw new JSONException('轮播标识发生冲突，请刷新后检查');
            throw $exception;
        }
        ManageLog::log($this->getManage(), '[轮播图片]' . ($restore ? '恢复#' : '删除#') . $result['id']);
        return $this->json(200, $restore ? '已恢复轮播' : '已删除轮播，可在已删除图片中恢复', $result);
    }

    public function bannerSave(): array
    {
        $this->guard();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new JSONException('请使用 POST 保存');
        try {
            $data = Service::bannerInput($this->input());
            $result = DB::connection()->transaction(function () use ($data): array {
                $id = $data['id'];
                $row = $id > 0 ? Content::query()->lockForUpdate()->find($id) : new Content();
                if (!$row) throw new \InvalidArgumentException('轮播已被删除，请刷新列表');
                if ($id > 0 && $row->type === 'banner-trash') throw new \InvalidArgumentException('轮播已删除，请先恢复后再编辑');
                if ($id > 0 && $row->type !== 'banner') throw new \InvalidArgumentException('只能修改轮播图片，不能转换其他内容类型');
                if ($id > 0 && (int)$row->revision !== $data['revision']) throw new \InvalidArgumentException('轮播已被其他窗口修改，请刷新后重试');
                if ($data['enabled'] && $data['image'] === ''
                    && (!$id || !Service::bannerLegacyAvailable($row->toArray(), Service::editions($id)))) {
                    throw new \InvalidArgumentException('显示轮播前请上传图片；原已发布的有效文字轮播除外');
                }
                $now = date('Y-m-d H:i:s');
                if (!$id) {
                    $row->fill(['type' => 'banner', 'slug' => 'banner-' . bin2hex(random_bytes(10)), 'title' => '',
                        'summary' => '', 'body' => '', 'payload' => '{}', 'source_revision' => 1, 'create_time' => $now]);
                }
                // Preserve every authored source field and translation on old
                // rows; an image edit never increments the text source version.
                $row->fill(['image' => $data['image'], 'url' => $data['url'] ?? (string)$row->url, 'sort' => $data['sort'],
                    'status' => $data['enabled'] ? 1 : 0, 'revision' => $id ? (int)$row->revision + 1 : 1, 'update_time' => $now]);
                // Cached image-only clients leave the complete raw payload untouched.
                if (array_key_exists('overlay', $data)) $row->payload = Service::bannerPayloadWithOverlay($row->payload, $data['overlay']);
                $row->save();
                return ['id' => (int)$row->id, 'revision' => (int)$row->revision, 'item' => Service::bannerDescriptor($row)];
            });
        } catch (\InvalidArgumentException | \JsonException $exception) { throw new JSONException($exception->getMessage()); }
        catch (\Illuminate\Database\QueryException $exception) {
            if ((string)$exception->getCode() === '23000') throw new JSONException('轮播已由其他窗口创建或修改，请刷新后重试');
            throw $exception;
        }
        ManageLog::log($this->getManage(), '[轮播图片]保存#' . $result['id']);
        return $this->json(200, '保存成功', $result);
    }

    public function policyData(): array
    {
        $this->guard();
        $placement = (string)($_POST['placement'] ?? 'policy');
        if (!in_array($placement, ['policy', 'contact'], true)) throw new JSONException('文章引用位置不正确');
        $articles = Service::articleOptions(); $list = [];
        foreach (Content::query()->where('type', 'policy')->where('slug', '<>', Service::POLICY_SETTINGS_SLUG)->orderBy('sort')->orderBy('id')->get() as $row) {
            $entry = Service::policyDescriptor($row, $articles);
            if ($entry['placement'] === $placement && ($placement !== 'contact' || $row->slug === 'contact-page')) $list[] = $entry;
        }
        $total = count($list); $page = max(1, (int)($_POST['page'] ?? 1)); $limit = min(100, max(1, (int)($_POST['limit'] ?? 50)));
        return $this->json(200, 'success', ['list' => array_slice($list, ($page - 1) * $limit, $limit), 'total' => $total]);
    }

    public function policySave(): array
    {
        $this->guard();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new JSONException('请使用 POST 保存');
        try {
            $data = Service::policyInput($this->input());
            $id = DB::connection()->transaction(function () use ($data): int {
                $id = $data['id'];
                $row = $id > 0 ? Content::query()->lockForUpdate()->find($id) : new Content();
                if (!$row) throw new \InvalidArgumentException('文章入口已被删除');
                if ($id > 0 && Service::isPolicySettings($row)) throw new \InvalidArgumentException('政策设置请通过专属设置保存');
                if ($id > 0 && ($row->type !== 'policy' || Service::policyReference($row->payload)['placement'] !== $data['placement'])) throw new \InvalidArgumentException('文章入口位置已变化，请刷新后重试');
                if ($id > 0 && (int)$row->revision !== $data['revision']) throw new \InvalidArgumentException('文章入口已被其他窗口修改，请刷新后重试');
                if ($data['placement'] === 'contact') {
                    if ($id > 0 && $row->slug !== 'contact-page') throw new \InvalidArgumentException('联系入口标识不正确');
                    if (!$id && Content::query()->where('type', 'policy')->where('slug', 'contact-page')->lockForUpdate()->exists()) throw new \InvalidArgumentException('联系入口已存在，请刷新后编辑');
                }
                $article = Content::query()->whereIn('type', ['article', 'faq'])->lockForUpdate()->find($data['article_id']);
                if (!$article) throw new \InvalidArgumentException('请选择文章中心中已有的文章');
                $options = Service::articleOptions();
                $choice = null;
                foreach ($options as $option) if ($option['id'] === $data['article_id']) { $choice = $option; break; }
                if ($data['publish'] && (!$choice || $choice['missing'] !== [])) throw new \InvalidArgumentException('发布入口前，请选择已发布且五语均可访问的文章');
                $previous = $id ? json_decode((string)$row->payload, true) : [];
                $payload = ['article_id' => $data['article_id'], 'placement' => $data['placement']];
                if (is_string($previous['legacy_alias'] ?? null)) $payload['legacy_alias'] = $previous['legacy_alias'];
                $now = date('Y-m-d H:i:s');
                $row->fill(['type' => 'policy', 'slug' => $id ? (string)$row->slug : ($data['placement'] === 'contact' ? 'contact-page' : 'policy-link-' . bin2hex(random_bytes(10))),
                    'title' => '', 'summary' => '', 'body' => '', 'url' => '', 'image' => '',
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'sort' => $data['sort'],
                    'status' => $data['publish'] ? 1 : 0, 'revision' => $id ? (int)$row->revision + 1 : 1,
                    'source_revision' => $id ? (int)$row->source_revision : 1, 'update_time' => $now]);
                if (!$id) $row->create_time = $now;
                $row->save();
                return (int)$row->id;
            });
        } catch (\InvalidArgumentException | \JsonException $exception) { throw new JSONException($exception->getMessage()); }
        catch (\Illuminate\Database\QueryException $exception) {
            if ((string)$exception->getCode() === '23000') throw new JSONException('联系入口已由其他窗口创建，请刷新后编辑');
            throw $exception;
        }
        $row = Content::query()->find($id);
        ManageLog::log($this->getManage(), '[文章入口]保存#' . $id);
        return $this->json(200, '保存成功', ['id' => $id, 'revision' => (int)$row->revision, 'item' => Service::policyDescriptor($row, Service::articleOptions())]);
    }

    public function policySettingsGet(): array
    {
        $this->guard();
        try { $item = Service::policySettingsState(); }
        catch (\InvalidArgumentException $exception) { throw new JSONException($exception->getMessage()); }
        return $this->json(200, 'success', ['item' => $item, 'preview_urls' => $this->policySettingsPreviewUrls()]);
    }

    private function policySettingsPreviewUrls(): array
    {
        $urls = [];
        foreach (ContentRules::LOCALES as $locale) $urls[$locale] = \App\Util\StoreLocale::url('/index.html', $locale);
        return $urls;
    }

    /** Manual language blocks are independent; saving Chinese never invalidates another language. */
    public function policySettingsSave(): array
    {
        $this->guard();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new JSONException('请使用 POST 保存');
        try {
            $data = Service::policySettingsInput($this->input());
            $item = DB::connection()->transaction(function () use ($data): array {
                $existing = Content::query()->where('type', 'policy')->where('slug', Service::POLICY_SETTINGS_SLUG)->lockForUpdate()->first();
                if ($data['id'] > 0 && (!$existing || (int)$existing->id !== $data['id'])) throw new \InvalidArgumentException('政策设置记录已变化，请刷新后重试');
                if ($existing && ($data['id'] === 0 || (int)$existing->revision !== $data['revision'])) throw new \InvalidArgumentException('政策设置已被其他窗口修改，请刷新后重试');
                $state = Service::policySettingsState($existing);
                foreach ($data['links'] ?? [] as $key => $url) $state['links'][$key] = $url;
                foreach ($data['texts'] ?? [] as $locale => $text) $state['texts'][$locale] = $text;
                $payload = ['kind' => 'policy-settings', 'links' => $state['links'], 'texts' => $state['texts']];
                $row = $existing ?? new Content(); $now = date('Y-m-d H:i:s');
                $row->fill(['type' => 'policy', 'slug' => Service::POLICY_SETTINGS_SLUG, 'title' => '', 'summary' => '', 'body' => '',
                    'url' => '', 'image' => '', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'sort' => 0, 'status' => 1, 'source_revision' => 1, 'revision' => $existing ? (int)$existing->revision + 1 : 1, 'update_time' => $now]);
                if (!$existing) $row->create_time = $now;
                $row->save();
                return Service::policySettingsState($row);
            });
        } catch (\InvalidArgumentException | \JsonException $exception) { throw new JSONException($exception->getMessage()); }
        catch (\Illuminate\Database\QueryException $exception) {
            if ((string)$exception->getCode() === '23000') throw new JSONException('政策设置已由其他窗口创建，请刷新后重试');
            throw $exception;
        }
        ManageLog::log($this->getManage(), '[政策设置]保存#' . $item['id']);
        return $this->json(200, '保存成功', ['item' => $item, 'preview_urls' => $this->policySettingsPreviewUrls()]);
    }

    public function tipGet(): array
    {
        $this->guard();
        $row = Content::query()->where('type', 'tip')->where('slug', 'warm-tips')->first();
        $source = $row ? Service::sourceDocument($row) : Service::tipDocument('zh-cn', '');
        $hash = ContentRules::hash($source); $editions = $row ? Service::editions((int)$row->id) : [];
        $translations = [];
        foreach (ContentRules::LOCALES as $locale) {
            $edition = $editions[$locale] ?? [];
            $current = $row && ($edition['source_hash'] ?? '') === $hash && (int)($edition['sourceRevision'] ?? -1) === (int)$row->source_revision;
            $document = $locale === 'zh-cn' ? $source : ($edition['document'] ?? []);
            $translations[$locale] = ['text' => Service::tipPlainText((string)($document['body'] ?? '')),
                'reviewed' => $locale === 'zh-cn' || ($current && (int)($edition['status'] ?? 0) === 2),
                'status' => (int)($edition['status'] ?? 0), 'stale' => !$current];
        }
        return $this->json(200, 'success', ['item' => ['id' => (int)($row->id ?? 0), 'revision' => (int)($row->revision ?? 0), 'status' => (int)($row->status ?? 0)],
            'sourceHash' => $hash, 'sourceRevision' => (int)($row->source_revision ?? 0), 'translations' => $translations, 'auto_translation' => AutoTranslationService::enabled()]);
    }

    public function tipSave(): array
    {
        $this->guard();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new JSONException('请使用 POST 保存');
        $automatic = AutoTranslationService::enabled();
        try {
            $data = $this->input();
            if (array_diff(array_keys($data), ['id', 'revision', 'translations', 'publish']) !== []
                || !is_int($data['id'] ?? null) || $data['id'] < 0 || !is_int($data['revision'] ?? null) || $data['revision'] < 0
                || !is_bool($data['publish'] ?? null) || !is_array($data['translations'] ?? null)
                || array_diff(array_keys($data['translations']), ContentRules::LOCALES) !== []) throw new \InvalidArgumentException('温馨提示提交格式不正确');
            if ($data['id'] === 0 && $data['revision'] !== 0) throw new \InvalidArgumentException('新温馨提示版本不正确');
            $editions = [];
            foreach (ContentRules::LOCALES as $locale) {
                $entry = $data['translations'][$locale] ?? ['text' => '', 'reviewed' => false];
                if (!is_array($entry) || array_diff(array_keys($entry), ['text', 'reviewed']) !== [] || !is_bool($entry['reviewed'] ?? null)) throw new \InvalidArgumentException('温馨提示译文格式不正确');
                $editions[$locale] = ['document' => Service::tipDocument($locale, $entry['text'] ?? ''), 'status' => $locale === 'zh-cn' || $entry['reviewed'] ? 2 : 0];
            }
            $source = $editions['zh-cn']['document'];
            if ($source['body'] === '') throw new \InvalidArgumentException('请填写简体温馨提示内容');
            $id = DB::connection()->transaction(function () use ($data, $editions, $source, $automatic): int {
                $id = $data['id'];
                $row = $id > 0 ? Content::query()->lockForUpdate()->find($id) : new Content();
                if (!$row) throw new \InvalidArgumentException('温馨提示已被删除');
                if ($id > 0 && ($row->type !== 'tip' || $row->slug !== 'warm-tips')) throw new \InvalidArgumentException('只能编辑整块温馨提示');
                if ($id > 0 && (int)$row->revision !== $data['revision']) throw new \InvalidArgumentException('温馨提示已被其他窗口修改，请刷新后重试');
                if (!$id && Content::query()->where('type', 'tip')->where('slug', 'warm-tips')->lockForUpdate()->exists()) throw new \InvalidArgumentException('温馨提示已存在，请刷新后编辑');
                $hash = ContentRules::hash($source);
                $changed = !$id || ContentRules::hash(Service::sourceDocument($row)) !== $hash;
                $sourceRevision = $id ? (int)$row->source_revision + (int)$changed : 1;
                $states = $editions;
                $currentEditions = $id && !$changed ? Service::editions($id) : [];
                foreach ($states as $locale => &$edition) {
                    $edition['source_hash'] = $hash;
                    if ($edition['document']['body'] === '') $edition['status'] = 0;
                    if (!$changed && $edition['status'] === 0 && $id) {
                        $current = $currentEditions[$locale] ?? [];
                        if ((int)($current['status'] ?? 0) === 1 && ($current['source_hash'] ?? '') === $hash
                            && (int)($current['sourceRevision'] ?? -1) === $sourceRevision
                            && Service::tipPlainText((string)($current['document']['body'] ?? '')) === Service::tipPlainText($edition['document']['body'])) $edition['status'] = 1;
                    }
                }
                unset($edition);
                $missing = ContentRules::missing('tip', $source, $states);
                if ($data['publish'] && !$automatic && $missing !== []) throw new \InvalidArgumentException('发布前请补齐并确认五语温馨提示：' . implode('、', $missing));
                $now = date('Y-m-d H:i:s');
                $row->fill(['type' => 'tip', 'slug' => 'warm-tips', 'title' => $source['title'], 'summary' => '', 'body' => $source['body'],
                    'url' => '', 'image' => '', 'payload' => '{}', 'sort' => 0, 'status' => $data['publish'] ? 1 : 0,
                    'revision' => $id ? (int)$row->revision + 1 : 1, 'source_revision' => $sourceRevision, 'update_time' => $now]);
                if (!$id) $row->create_time = $now;
                $row->save();
                foreach ($states as $locale => $edition) ContentTranslation::query()->updateOrCreate(
                    ['entity_type' => 'store', 'entity_id' => (string)$row->id, 'locale' => $locale, 'field' => 'document'],
                    ['text' => json_encode($edition['document'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'source_hash' => $hash, 'source_revision' => $sourceRevision, 'status' => $edition['status'], 'update_time' => $now]);
                return (int)$row->id;
            });
        } catch (\InvalidArgumentException | \JsonException $exception) { throw new JSONException($exception->getMessage()); }
        catch (\Illuminate\Database\QueryException $exception) {
            if ((string)$exception->getCode() === '23000') throw new JSONException('温馨提示已由其他窗口创建，请刷新后编辑');
            throw $exception;
        }
        ManageLog::log($this->getManage(), '[整块温馨提示]保存#' . $id);
        return $this->json(200, '保存成功', ['id' => $id, 'auto_translation' => $automatic]);
    }

    public function options(): array
    {
        $this->guard();
        return $this->json(200, 'success', ['commodity' => \App\Model\Commodity::query()->orderBy('id')->get(['id', 'name'])->toArray(), 'category' => \App\Model\Category::query()->orderBy('id')->get(['id', 'name'])->toArray(), 'config' => [['id' => 'site', 'name' => '站点公告与基础文案']], 'article' => Service::articleOptions(), 'auto_translation' => AutoTranslationService::enabled()]);
    }

    public function entity(): array
    {
        $this->guard();
        $type = (string)($_POST['type'] ?? ''); $id = (string)($_POST['id'] ?? '');
        try { $source = Service::entitySource($type, $id); }
        catch (\InvalidArgumentException $e) { throw new JSONException($e->getMessage()); }
        return $this->json(200, 'success', ['source' => $source, 'sourceRevision' => ContentRules::hash($source), 'translations' => Service::entityEditions($type, $id, $source), 'auto_translation' => AutoTranslationService::enabled()]);
    }

    public function saveEntity(): array
    {
        $this->guard(); $data = $this->input();
        $type = (string)($data['type'] ?? ''); $id = (string)($data['id'] ?? '');
        try {
            $source = Service::entitySource($type, $id);
            if (!hash_equals(ContentRules::hash($source), (string)($data['sourceRevision'] ?? ''))) throw new \InvalidArgumentException('原商品或配置已变化，请刷新原文后再保存');
            $updates = [];
            foreach (ContentRules::LOCALES as $locale) {
                if ($locale === 'zh-cn') continue; // The source is maintained in the existing product/category/settings module.
                foreach ($source as $field => $original) {
                    $entry = $data['translations'][$locale][$field] ?? null;
                    if (!is_array($entry)) continue;
                    $text = $entry['text'] ?? '';
                    if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || mb_strlen($text) > 100000) throw new \InvalidArgumentException('实体译文格式或长度不正确');
                    if (in_array($field, ['tags', 'widget'], true)) {
                        if (trim($text) !== '') $text = ContentRules::translatedObject($field, $original, $text);
                    } elseif (in_array($field, ['description', 'notice', 'closed_message'], true)) $text = RichHtml::sanitize($text, false);
                    else $text = strip_tags($text);
                    $updates[] = ['entity_type' => $type, 'entity_id' => $id, 'locale' => $locale, 'field' => $field, 'text' => $text, 'source_hash' => ContentRules::hash($original), 'source_revision' => 1, 'status' => ($entry['reviewed'] ?? false) === true && trim($text) !== '' ? 2 : 0, 'update_time' => date('Y-m-d H:i:s')];
                }
            }
            DB::connection()->transaction(function () use ($updates): void {
                foreach ($updates as $entry) {
                    $key = array_intersect_key($entry, array_flip(['entity_type', 'entity_id', 'locale', 'field']));
                    ContentTranslation::query()->updateOrCreate($key, array_diff_key($entry, $key));
                }
            });
        } catch (\InvalidArgumentException | \JsonException $e) { throw new JSONException($e->getMessage()); }
        ManageLog::log($this->getManage(), '[五语店铺内容]更新实体译文 ' . $type . '#' . $id);
        return $this->json(200, '实体译文已保存；原价格、库存和表单提交值保持不变');
    }
}
