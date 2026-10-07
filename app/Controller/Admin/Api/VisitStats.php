<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Interceptor\Owner;
use App\Util\VisitCollection;
use App\Util\VisitStatistics;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
final class VisitStats extends Manage
{
    #[Interceptor(Owner::class, Interceptor::TYPE_API)]
    public function collection(): array
    {
        if (!$this->getManage() || (int)$this->getManage()->type !== 0) throw new JSONException('仅站点管理员可以修改访问统计');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new JSONException('统计开关仅支持 POST 请求');
        header('Cache-Control: private, no-store');
        try {
            $enabled = VisitCollection::request($_POST);
            $state = VisitStatistics::setCollectionEnabled($enabled);
            return $this->json(200, '统计设置已保存', ['collection' => $state,
                'collection_enabled' => $state['enabled'], 'collection_changed_at' => $state['changed_at']]);
        } catch (\InvalidArgumentException $error) {
            throw new JSONException($error->getMessage());
        } catch (\Throwable) {
            throw new JSONException('统计开关保存失败，请刷新后重试');
        }
    }

    public function summary(): array
    {
        $manage = $this->getManage();
        if (!$manage || (int)$manage->type !== 0) throw new JSONException('仅站点管理员可以查看访问统计');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new JSONException('访问统计只支持 GET 请求');
        try {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone(VisitStatistics::TIMEZONE)))->format('Y-m-d');
            $date = VisitStatistics::validateDate($_GET['date'] ?? $today);
            header('Cache-Control: private, no-store');
            return $this->json(200, 'success', VisitStatistics::report($date));
        } catch (\InvalidArgumentException $error) {
            throw new JSONException($error->getMessage());
        } catch (\Throwable) {
            throw new JSONException('访问统计暂不可用，请稍后重试');
        }
    }
}
