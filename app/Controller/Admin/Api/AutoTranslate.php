<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Model\ManageLog;
use App\Util\AutoTranslationService;
use App\Util\ContentRules;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class AutoTranslate extends Manage
{
    private const KEYS = ['auto_translate_enabled', 'auto_translate_base_url', 'auto_translate_model', 'auto_translate_api_key'];

    private function guard(): void
    {
        if (!$this->getManage() || (int)$this->getManage()->type !== 0) throw new JSONException('仅站点管理员可以配置自动翻译');
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new JSONException('自动翻译设置仅接受 POST 请求');
    }

    private static function values(): array
    {
        $rows = Config::query()->whereIn('key', self::KEYS)->pluck('value', 'key')->all();
        $values = [];
        foreach (self::KEYS as $key) $values[$key] = (string)($rows[$key] ?? '');
        return $values;
    }

    private function response(): array
    {
        $values = self::values();
        return ['enabled' => $values['auto_translate_enabled'] === '1',
            'base_url' => $values['auto_translate_base_url'], 'model' => $values['auto_translate_model'],
            'has_key' => $values['auto_translate_api_key'] !== '', 'revision' => ContentRules::hash($values),
            'configured' => AutoTranslationService::enabled(), 'state' => AutoTranslationService::status()];
    }

    public function settings(): array
    {
        $this->guard();
        return $this->json(200, 'success', $this->response());
    }

    public function save(): array
    {
        $this->guard();
        try {
            $hex = $_POST['payload_hex'] ?? null;
            if (!is_string($hex) || $hex === '' || strlen($hex) > 20000 || strlen($hex) % 2 || !ctype_xdigit($hex)) throw new \InvalidArgumentException('设置编码不正确');
            $raw = hex2bin($hex);
            if ($raw === false || !mb_check_encoding($raw, 'UTF-8')) throw new \InvalidArgumentException('设置编码不正确');
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            $keys = ['enabled', 'base_url', 'model', 'api_key', 'clear_key', 'revision'];
            if (!is_array($data) || array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) throw new \InvalidArgumentException('设置参数不正确');
            if (!is_bool($data['enabled']) || !is_bool($data['clear_key'])) throw new \InvalidArgumentException('开关格式不正确');
            foreach (['base_url' => 2000, 'model' => 128, 'api_key' => 4096] as $field => $limit) {
                if (!is_string($data[$field]) || !mb_check_encoding($data[$field], 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $data[$field]) || mb_strlen($data[$field]) > $limit) throw new \InvalidArgumentException('接口设置格式不正确');
                $data[$field] = trim($data[$field]);
            }
            if (!is_string($data['revision']) || !preg_match('/^[a-f0-9]{64}$/D', $data['revision'])) throw new \InvalidArgumentException('设置版本不正确');
            if ($data['clear_key'] && $data['api_key'] !== '') throw new \InvalidArgumentException('清除和更换密钥不能同时操作');
            $base = $data['base_url'] === '' ? '' : AutoTranslationService::validateBaseUrl($data['base_url']);
            Config::withExclusiveLock(static function () use ($data, $base): void {
                $previous = self::values();
                if (!hash_equals(ContentRules::hash($previous), $data['revision'])) throw new \InvalidArgumentException('设置已被其他窗口修改，请刷新后重试');
                $key = $data['clear_key'] ? '' : ($data['api_key'] !== '' ? $data['api_key'] : $previous['auto_translate_api_key']);
                if ($data['enabled'] && ($base === '' || $data['model'] === '' || $key === '')) throw new \InvalidArgumentException('请先填写接口地址、模型和 API 密钥，再启用自动翻译');
                Config::putMany(['auto_translate_enabled' => $data['enabled'] ? '1' : '0', 'auto_translate_base_url' => $base,
                    'auto_translate_model' => $data['model'], 'auto_translate_api_key' => $key]);
            });
        } catch (\InvalidArgumentException | \JsonException $exception) {
            throw new JSONException($exception->getMessage());
        }
        ManageLog::log($this->getManage(), '[自动翻译]更新翻译设置');
        return $this->json(200, '设置已保存', $this->response());
    }
}
