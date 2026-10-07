<?php
declare(strict_types=1);

namespace App\Controller\Base\View;

use App\Model\Business;
use App\Model\Config;
use App\Util\Client;
use App\Util\CustomerServiceWidget;
use App\Util\RichHtml;
use App\Util\Theme;
use App\Util\StoreLocale;
use App\Util\StoreContentService;
use App\Util\ViewSafe;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

abstract class UserPlugin extends \App\Controller\Base\User
{
    protected function render(?string $title, string $template, array $data = [], bool $controller = false): string
    {
        try {
            require(BASE_PATH . '/app/View/User/Helper.php');
            $data['title'] = $title;
            $cfg = Config::list();
            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            if (Client::isMobile() && $data['config']['background_mobile_url']) {
                $data['config']['background_url'] = $data['config']['background_mobile_url'];
            }

            $domain = Client::getDomain();
            $business = Business::query()->where("subdomain", $domain)->first() ?? Business::query()->where("topdomain", $domain)->first();
            if ($business) {
                $data['config']['shop_name'] = $business->shop_name;
                $data['config']['title'] = $business->title;
                $data['config']['notice'] = RichHtml::sanitize((string)$business->notice, false);
                $data['config']['service_url'] = $business->service_url != "" ? $business->service_url : "https://wpa.qq.com/msgrd?v=1&uin={$business->service_qq}";
            }
            $user = $this->getUser();
            if ($user) {
                $data['user'] = $user;

                $data['group'] = $this->getUserGroup()?->toArray();
            }
            $themeConfig = Theme::getConfig('LocalStar');
            $data['setting'] = $themeConfig['setting'];
            $data['footerIcp'] = (string)($cfg['icp'] ?? ($themeConfig['setting']['icp'] ?? ''));
            $data['default_view_path'] = BASE_PATH . '/app/View/User/Theme/LocalStar/Account/';
            $data['static'] = '/app/View/User/Theme/LocalStar';
            \App\Util\Context::set(\App\Util\Helper::CURRENT_THEME, 'LocalStar');
            $data['storeAccount'] = true;
            $data['app']['version'] = \config('app')['version'];
            $data['favicon'] = '/favicon.ico';
            $data['langs'] = \Kernel\Util\Lang::menu();
            $data['storeLocale'] = StoreLocale::get();
            $data['storePrefix'] = StoreLocale::prefix();
            $data['storeLocales'] = StoreLocale::links();
            $data['storeBundle'] = StoreContentService::bundle($data['storeLocale']);
            $data['publicContactUrl'] = store_contact_url($data['storeBundle']);
            $data['storeOgLocale'] = match ($data['storeLocale']) {
                'zh-tw' => 'zh_TW', 'en' => 'en_US', 'ru' => 'ru_RU', 'vi' => 'vi_VN', default => 'zh_CN'
            };
            $result = View::render(
                $template,
                ViewSafe::escape($data),
                BASE_PATH . "/app/Plugin/" . ($controller ? \Kernel\Util\Plugin::$currentControllerPluginName : \Kernel\Util\Plugin::$currentPluginName) . "/View",
                $controller
            );
            return CustomerServiceWidget::inject($result, $data['config']);
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }
}
