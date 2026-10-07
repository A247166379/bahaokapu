<?php
declare(strict_types=1);

namespace App\Controller\Base\View;

use App\Consts\Render;
use App\Model\Business;
use App\Model\Config;
use App\Util\Client;
use App\Util\CustomerServiceWidget;
use App\Util\RichHtml;
use App\Util\ViewSafe;
use App\Util\Theme;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

abstract class User extends \App\Controller\Base\User
{
    protected array $indexTemplateList = [
        // 商城访客链路必须使用同一套商城主题。登录/注册/找回密码以前被误归到
        // “会员中心主题”，导致买家从新模板点击登录或启用验证码后突然跳回旧模板。
        'INDEX', 'ITEM', 'QUERY', 'CLOSED', 'CONTENT',
        'LOGIN', 'REGISTER', 'FORGET_EMAIL', 'FORGET_PHONE'
    ];

    private const TRANSLATABLE_CONFIG = ['notice', 'shop_name', 'title', 'closed_message', 'commodity_name'];

    private function translateConfigText(array $config): array
    {
        $raw = $config;
        foreach (self::TRANSLATABLE_CONFIG as $key) {
            if (!empty($config[$key]) && is_string($config[$key])) {
                $config[$key] = lang($config[$key], "dyn");
            }
        }
        if (class_exists(\App\Util\StoreContentService::class)) {
            $config = \App\Util\CommodityLang::config($config, $raw);
        }
        return $config;
    }

    protected function render(string $title, string $template, array $data = []): string
    {
        try {
            require(BASE_PATH . "/app/View/User/Helper.php");

            $data['title'] = !empty($data['authoredTitle']) ? $title : lang($title, "tpl");
            $data['app']['version'] = \config("app")['version'];
            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            $data['config'] = $this->translateConfigText($data['config']);
            $widgetConfig = $data['config'];
            $result = View::render('User/' . $template, ViewSafe::escape($data));
            return CustomerServiceWidget::inject($result, $widgetConfig);
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }

    protected function theme(string $title, string $template, string $default, array $data = []): string
    {
        try {
            require(BASE_PATH . "/app/View/User/Helper.php");

            $data['title'] = !empty($data['authoredTitle']) ? $title : lang($title, "tpl");
            $data['app']['version'] = \config("app")['version'];
            $data['favicon'] = "/favicon.ico";

            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            // 商城、认证和账户页使用同一套已安装模板。
            $theme = 'LocalStar';
            $data['storeAccount'] = !in_array($template, $this->indexTemplateList, true);

            $data['static'] = "/app/View/User/Theme/" . $theme;
            // 插件和通用资源助手也使用同一主题上下文。
            \App\Util\Context::set(\App\Util\Helper::CURRENT_THEME, $theme);

            $business = Business::get();
            if ($business) {
                $data['isBusinessSite'] = true;
                $data['config']['shop_name'] = $business->shop_name;
                $data['config']['title'] = $business->title;
                $data['config']['notice'] = RichHtml::sanitize((string)$business->notice, false);
                $data['config']['service_url'] = $business->service_url != "" ? $business->service_url : "https://wpa.qq.com/msgrd?v=1&uin={$business->service_qq}";
                if (!$data['from']) {
                    $data['from'] = $business->user_id;
                }
                $businessUser = $business->user;

                if ($businessUser && $businessUser->avatar) {
                    $data['favicon'] = $businessUser->avatar;
                }
            }

            $data['config'] = $this->translateConfigText($data['config']);

            $themePath = "User/Theme/{$theme}/";
            $config = Theme::getConfig($theme);
            $path = $themePath . ($data['storeAccount'] ? 'Account/' : '') . $default;
            $system = true;

            if (!empty($config['theme']) && key_exists($template, $config['theme'])) {
                $path = $themePath . $config['theme'][$template];
                $system = false;
            }

            $user = $this->getUser();
            if ($user) {
                $data['user'] = $user;
                $data['group'] = $this->getUserGroup()?->toArray();
            }

            $data['setting'] = $config['setting'];
            $data['footerIcp'] = (string)($cfg['icp'] ?? ($config['setting']['icp'] ?? ''));

            //语言切换器数据源。**必须以变量形式给模板**，不能让模板去调 lang_menu()：
            //Smarty 在编译期就校验普通函数是否存在，模板一旦更新到只有旧核心的站点上，
            //整页会抛 SmartyCompilerException 白屏；而未定义的变量只会渲染成空，最多是
            //切换器不显示。模板是独立上架、可以先于核心更新的，这个降级路径必须留着。
            $data['langs'] = \Kernel\Util\Lang::menu();
            $data['storeLocale'] = \App\Util\StoreLocale::get();
            $data['storePrefix'] = \App\Util\StoreLocale::prefix();
            $data['storeLocales'] = \App\Util\StoreLocale::links();
            if (str_starts_with((string)($data['robots'] ?? ''), 'index,')) {
                $data['canonical'] ??= rtrim(Client::getUrl(), '/') . \App\Util\StoreLocale::url(\App\Util\StoreLocale::path());
                $alternateLinks = $data['storeLocales'];
                if (isset($data['storeAlternateLocales']) && is_array($data['storeAlternateLocales'])) {
                    $alternateLinks = array_filter($alternateLinks, static fn($entry) => in_array($entry['code'], $data['storeAlternateLocales'], true));
                }
                if (($data['page']['type'] ?? '') === 'helpArticle' && (int)($data['page']['id'] ?? 0) > 0
                    && !\App\Util\StoreContentService::articleHasEdition((int)$data['page']['id'], 'vi')) {
                    $alternateLinks = array_filter($alternateLinks, static fn($entry) => $entry['code'] !== 'vi');
                }
                $data['storeAlternates'] = array_map(static fn($entry) => [
                    'code' => $entry['code'],
                    'url' => rtrim(Client::getUrl(), '/') . \App\Util\StoreLocale::url(\App\Util\StoreLocale::path(), $entry['code']),
                ], $alternateLinks);
            }
            $data['storeOgLocale'] = match ($data['storeLocale']) {
                'zh-tw' => 'zh_TW', 'en' => 'en_US', 'ru' => 'ru_RU', 'vi' => 'vi_VN', default => 'zh_CN'
            };
            $data['storeBundle'] = class_exists(\App\Util\StoreContentService::class)
                ? \App\Util\StoreContentService::bundle($data['storeLocale']) : [];
            $data['publicContactUrl'] = store_contact_url($data['storeBundle']);

            $widgetConfig = $data['config'];
            $data = ViewSafe::escape($data);

            if ($config['info']['RENDER'] == Render::ENGINE_SMARTY || $system) {
                $result = View::render($path, $data);
                $result = CustomerServiceWidget::inject($result, $widgetConfig);
                \App\Util\VisitStatistics::recordCurrentRequest();
                return $result;
            } elseif ($config['info']['RENDER'] == Render::ENGINE_PHP) {
                ob_start();
                require(BASE_PATH . '/app/View/' . $path);
                $result = ob_get_contents();
                ob_end_clean();
                if (\App\Util\Csp::enabled()) {
                    $result = \App\Util\Csp::injectNonce($result);
                }
                hook(\App\Consts\Hook::RENDER_VIEW, $result);
                $result = CustomerServiceWidget::inject($result, $widgetConfig);
                \App\Util\VisitStatistics::recordCurrentRequest();
                return $result;
            }
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }

        return "";
    }
}
