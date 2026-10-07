<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class StoreContent extends Manage
{
    public function index(): string
    {
        $manage = $this->getManage();
        if (!$manage || (int)$manage->type !== 0) throw new \Kernel\Exception\JSONException('仅站点管理员可以维护五语内容');
        $types = ['banner' => '轮播图片', 'announcement' => '店铺公告', 'tip' => '温馨提示', 'article' => '文章中心', 'policy' => '政策按钮', 'footer' => '右侧政策卡', 'contact' => '联系我们', 'navigation' => '顶部菜单'];
        $entities = ['commodity' => '商品译文', 'category' => '分类译文', 'config' => '站点译文'];
        $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
        // Keep old administration links usable without converting existing FAQ records.
        if ($type === 'faq') $type = 'article';
        $entity = is_string($_GET['entity'] ?? null) ? $_GET['entity'] : 'commodity';
        $mode = ($_GET['mode'] ?? '') === 'entity' ? 'entity' : 'content';
        if (isset($entities[$type])) { $mode = 'entity'; $entity = $type; $type = ''; }
        if (!isset($types[$type])) $type = '';
        if (!isset($entities[$entity])) $entity = 'commodity';
        $heading = $mode === 'entity' ? $entities[$entity] : ($types[$type] ?? '当前前台内容');
        return $this->render($heading, 'Config/StoreContent.html', [
            'toolbar' => [], 'storeContentMode' => $mode, 'storeContentType' => $type,
            'storeContentEntity' => $entity, 'storeContentHeading' => $heading,
            'storefrontAddresses' => \App\Util\StoreLocale::homepages(\App\Util\Client::getUrl()),
        ]);
    }
}
