<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Image;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\File;
use App\Util\UploadUtil;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Upload extends User
{
    #[Inject]
    private \App\Service\Upload $upload;

    #[Inject]
    private Query $query;

    #[Inject]
    private Image $image;
    const MIME = ['image', 'video', 'doc', 'other'];

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function send(Request $request): array
    {
        $type = strtolower((string)$request->get("mime"));
        $thumbHeight = (int)$request->get("thumb_height");

        //前端上传地址永远带 ?mime=；到这里为空基本都是服务器环境把 URL 参数吞了（issue #794）
        if ($type === '') {
            throw new JSONException("上传参数丢失：服务器未收到 mime 参数，请检查伪静态规则(try_files 是否带 \$args)、WAF 或 CDN 是否丢弃了链接参数");
        }

        if ($thumbHeight < 0 || $thumbHeight > 2000) {
            throw new JSONException("缩略图高度不正确，最大支持2000像素");
        }
        if (!in_array($type, self::MIME, true)) {
            throw new JSONException("mime not supported");
        }
        $static_path = "/assets/cache/user/{$this->getUser()->id}/{$type}/";
        $handle = $this->upload->handle($_FILES['file'] ?? null, BASE_PATH . $static_path, UploadUtil::extensions($type), UploadUtil::MAX_KIB);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }

        $fileName = $static_path . $handle['new_name'];

        // 去重只在「当前用户自己的上传记录」内进行:
        // 否则同一张图若已被管理员(/general/)或其它用户上传过,全局去重会命中他人记录,
        // 导致本次上传既不落库、文件又被删除,该用户「相册」永远看不到自己传的图。
        if ($tmp = $this->upload->get(md5_file(BASE_PATH . $fileName), $this->getUser()->id)) {
            File::remove(BASE_PATH . $fileName);
            $fileName = $tmp;
        } else {
            //并发上传相同内容时只复用同一用户的记录，不返回管理员或其他用户路径。
            $shared = $this->upload->add($fileName, $type, $this->getUser()->id);
            if ($shared !== null && $shared !== $fileName) {
                File::remove(BASE_PATH . $fileName);
                $fileName = $shared;
            }
        }

        $append = [];
        //生成缩略图
        if ($type == self::MIME[0] && $thumbHeight > 0) {
            $thumbUrl = $this->image->createThumbnail($fileName, $thumbHeight);
            if (!$thumbUrl) {
                throw new JSONException("图片上传失败，原因：生成缩略图失败");
            }
            $append['thumb_url'] = $thumbUrl;
        }

        return $this->json(200, '上传成功', ["url" => $fileName, "append" => $append]);
    }

    /**
     * @return array
     */
    public function get(): array
    {
        $get = new Get(\App\Model\Upload::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy('id');
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("user_id", $this->getUser()->id);
        });
        foreach ($data['list'] as &$item) {
            $baseImagePathInfo = pathinfo($item['path']);
            $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];
            if (is_file(BASE_PATH . $thumbPath)) {
                $item['thumb_url'] = $thumbPath;
            }
        }

        return $this->json(data: $data);
    }

    /**
     * 文件上传
     * @return array
     * @throws JSONException
     */
    public function handle(): array
    {
        $userId = $this->getUser()->id;

        if (!isset($_FILES['file'])) {
            throw new JSONException(UploadUtil::missingFileMessage());
        }
        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . '/assets/cache/user/' . $userId . '/images', UploadUtil::IMAGES, UploadUtil::MAX_KIB);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }
        return $this->json(200, '上传成功', ['path' => '/assets/cache/user/' . $userId . '/images/' . $handle['new_name']]);
    }

    /**
     * 获取图像列表
     * @return array
     */
    public function images(): array
    {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 20)));
        $userId = $this->getUser()->id;

        $path = BASE_PATH . '/assets/cache/user/' . $userId . '/images/';

        $list = is_dir($path) ? (array)scandir($path, SCANDIR_SORT_DESCENDING) : [];
        $list = array_values(array_filter($list, static fn($name) => $name !== '.' && $name !== '..' && is_file($path . $name) && !is_link($path . $name)));

        $ext = UploadUtil::IMAGES;
        foreach ($list as $index => $val) {
            $exp = explode(".", $val);
            if (!in_array(strtolower((string)end($exp)), $ext, true)) {
                unset($list[$index]);
                continue;
            }

            $list[$index] = '/assets/cache/user/' . $userId . '/images/' . $val;
        }

        $list = array_values($list);
        $count = count($list);
        $offset = ($page - 1) * $limit;
        $data = array_slice($list, $offset, $limit);
        $json = $this->json(200, "success", $data);
        $json['count'] = $count;
        return $json;
    }
}
