<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class)]
class VisitStats extends Manage
{
    public function index(): string
    {
        $manage = $this->getManage();
        if (!$manage || (int)$manage->type !== 0) throw new JSONException('仅站点管理员可以查看访问统计');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $selected = is_string($_GET['date'] ?? null) ? $_GET['date'] : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $selected)) $selected = $today;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $selected, new \DateTimeZone('Asia/Shanghai'));
        if (!$parsed || $parsed->format('Y-m-d') !== $selected || $selected > $today) $selected = $today;
        return $this->render('访问统计', 'VisitStats/Index.html', ['toolbar' => [], 'visitStatsDate' => $selected, 'visitStatsToday' => $today]);
    }
}
