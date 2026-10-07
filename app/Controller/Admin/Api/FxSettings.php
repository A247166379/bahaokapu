<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Model\ManageLog;
use App\Util\DisplayCurrency;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class FxSettings extends Manage
{
    private function guard(): void
    {
        if (!$this->getManage() || (int)$this->getManage()->type !== 0) {
            throw new JSONException('仅站点管理员可以配置汇率显示');
        }
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new JSONException('汇率设置仅接受 POST 请求');
        }
    }

    private function response(): array
    {
        return DisplayCurrency::settings() + ['currencies' => DisplayCurrency::currencies(), 'state' => DisplayCurrency::adminState()];
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
            if (count($_POST) !== 1 || !array_key_exists('payload_hex', $_POST)) {
                throw new \InvalidArgumentException('汇率显示设置不接受其他参数');
            }
            $hex = $_POST['payload_hex'] ?? null;
            if (!is_string($hex) || $hex === '' || strlen($hex) > 8000 || strlen($hex) % 2 || !ctype_xdigit($hex)) {
                throw new \InvalidArgumentException('设置编码不正确');
            }
            $raw = hex2bin($hex);
            if ($raw === false || !mb_check_encoding($raw, 'UTF-8')) throw new \InvalidArgumentException('设置编码不正确');
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || count($data) !== 3 || !isset($data['revision'])
                || !is_string($data['revision']) || !preg_match('/^[a-f0-9]{64}$/D', $data['revision'])) {
                throw new \InvalidArgumentException('设置参数或版本不正确');
            }
            $revision = $data['revision'];
            unset($data['revision']);
            $settings = DisplayCurrency::normalizeSettings($data);
            Config::withExclusiveLock(static function () use ($revision, $settings): void {
                if (!hash_equals(DisplayCurrency::settings()['revision'], $revision)) {
                    throw new \InvalidArgumentException('设置已被其他窗口修改，请刷新后重试');
                }
                Config::putMany([DisplayCurrency::CONFIG_KEY => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            });
        } catch (\InvalidArgumentException | \JsonException $error) {
            throw new JSONException($error->getMessage());
        } catch (\Throwable) {
            throw new JSONException('无法保存汇率显示设置，请检查服务器配置后重试');
        }
        ManageLog::log($this->getManage(), '[汇率显示]更新外币展示开关与显示调整');
        return $this->json(200, '汇率显示设置已保存', $this->response());
    }

    public function refresh(): array
    {
        $this->guard();
        if ($_POST !== []) throw new JSONException('刷新汇率不接受自定义接口或其他参数');
        try {
            $result = DisplayCurrency::refresh(true);
        } catch (\RuntimeException $error) {
            throw new JSONException($error->getMessage());
        }
        if ($result['result'] === 'updated') ManageLog::log($this->getManage(), '[汇率显示]手动更新参考汇率');
        return $this->json(200, $result['message'], $this->response() + ['refresh_result' => $result]);
    }
}
