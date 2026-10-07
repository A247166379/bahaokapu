<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Util\CallbackIpWhitelist;
use App\Util\Client;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Config extends Manage
{
    private array $TOOLBAR = [
        ["name" => '基础设置', "url" => "/admin/config/index"],
        ["name" => '首页内容', "url" => "/admin/config/storefront"],
    ];

    public function index(): string
    {
        $this->requireStoreOwner();
        $config = \App\Model\Config::list();
        return $this->render("基础设置", "Config/Setting.html", [
            "toolbar" => $this->TOOLBAR,
            "footer_icp" => $config['icp'] ?? (Theme::getConfig(Theme::ACTIVE_THEME)['setting']['icp'] ?? ''),
        ]);
    }

    private function requireStoreOwner(): void
    {
        $manage = $this->getManage();
        if (!$manage || (int)$manage->type !== 0) {
            throw new JSONException('仅站点管理员可以维护前台设置');
        }
    }

    public function storefront(): string
    {
        $this->requireStoreOwner();
        if (!isset($_GET['type']) && !isset($_GET['mode'])) $_GET['type'] = 'banner';
        return (new StoreContent())->index();
    }

    public function sms(): string
    {
        $smsConfig = json_decode(\App\Model\Config::get("sms_config"), true);
        $smsConfig = is_array($smsConfig) ? $smsConfig : [];
        foreach (['accessKeyId', 'accessKeySecret', 'tencentSecretId', 'tencentSecretKey', 'dxbao_password'] as $key) {
            unset($smsConfig[$key]);
        }
        return $this->render("短信设置", "Config/Sms.html", ["toolbar" => $this->TOOLBAR, "sms" => $smsConfig]);
    }

    public function email(): string
    {
        $emailConfig = json_decode(\App\Model\Config::get("email_config"), true);
        $emailConfig = is_array($emailConfig) ? $emailConfig : [];
        unset($emailConfig['password']);
        return $this->render("邮箱设置", "Config/Email.html", ["toolbar" => $this->TOOLBAR, "email" => $emailConfig]);
    }

    public function security(): string
    {
        $modes = [
            'REMOTE_ADDR',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CF_CONNECTING_IP'
        ];
        for ($i = 0; $i <= 8; $i++) {
            $ip = Client::getIp($i);
            $modes[$i] = $modes[$i] . " - " . ($ip ?: "此模式不适用");
        }

        return $this->render("安全设置", "Config/Security.html", [
            "toolbar" => $this->TOOLBAR,
            "ip_get_mode" => $modes,
            "ip_mode" => Client::getClientMode(),
            "trusted_proxy_ips" => Client::getTrustedProxyConfig(),
            "link_domain_auto" => implode('、', \App\Util\LinkDomainGuard::allowList()),
            "admin_entrance" => (string)\App\Model\Config::get('admin_entrance'),
            "request_log_key" => \App\Util\RequestLogCrypto::keyB64(),
            "request_log_summary" => \Kernel\Util\RequestLogger::summary(),
            "csp_summary" => \App\Util\Csp::summary(),
            "csp_violations" => \App\Util\Csp::violations(30),
            "csp_allow" => array_map(static fn(string $src): array => [
                'source' => $src,
                //只填到域名的条目范围最大，界面上要标出来
                'broad' => !str_contains(preg_replace('#^https?://#', '', $src) ?? $src, '/'),
            ], \App\Util\Csp::allowList()),
        ]);
    }

    public function other(): string
    {
        $category = \App\Model\Category::query()->where("status", 1)->where("owner", 0)->get();
        return $this->render("其他设置", "Config/Other.html", [
            "toolbar" => $this->TOOLBAR,
            "category" => $category->toArray(),
            "config" => [
                CallbackIpWhitelist::ENABLED_CONFIG => \App\Model\Config::get(CallbackIpWhitelist::ENABLED_CONFIG),
                CallbackIpWhitelist::RULES_CONFIG => \App\Model\Config::get(CallbackIpWhitelist::RULES_CONFIG),
            ],
        ]);
    }
}
