<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class)]
class FxSettings extends Manage
{
    public function index(): string
    {
        if (!$this->getManage() || (int)$this->getManage()->type !== 0) {
            throw new JSONException('仅站点管理员可以配置汇率显示');
        }
        return $this->render('汇率设置', 'FxSettings/Index.html', ['toolbar' => []]);
    }
}
