<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

final class BuyerProfile
{
    public static function fields(array $post): array
    {
        foreach (['alipay', 'settlement', 'wallet_address', 'wechat', 'plugin', 'app_key'] as $removed) {
            if (array_key_exists($removed, $post)) throw new JSONException('此功能已关闭');
        }
        $values = [];
        foreach (['avatar' => 255, 'qq' => 16, 'nicename' => 10] as $field => $limit) {
            if (!array_key_exists($field, $post) || !is_scalar($post[$field])) throw new JSONException('个人资料参数不完整');
            $value = trim((string)$post[$field]);
            if (str_contains($value, "\0") || mb_strlen($value) > $limit) throw new JSONException('个人资料内容过长或格式不正确');
            $values[$field] = $value;
        }
        if ($values['qq'] !== '' && !ctype_digit($values['qq'])) throw new JSONException('QQ 号格式不正确');
        $avatar = $values['avatar'];
        if ($avatar !== '' && !str_starts_with($avatar, '/assets/cache/') && $avatar !== '/favicon.ico'
            && !preg_match('#^https?://[^\s]+$#iD', $avatar)) throw new JSONException('头像地址不正确');
        return $values;
    }
}
