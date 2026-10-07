<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class)]
class AutoTranslate extends Manage
{
    public function index(): string
    {
        if (!$this->getManage() || (int)$this->getManage()->type !== 0) throw new JSONException('仅站点管理员可以配置自动翻译');
        return $this->render('自动翻译', 'Config/AutoTranslate.html', ['toolbar' => []]);
    }
}
