<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Consts\User;
use App\Util\Context;
use App\Util\JWT;
use Firebase\JWT\Key;
use Kernel\Annotation\InterceptorInterface;

/**
 * 访客拦截器
 * Class UserVisitor
 * @package App\Interceptor
 */
class UserVisitor implements InterceptorInterface
{
    public function handle(int $type): void
    {
        // Old promotion links must not attach a referrer to new single-shop purchases.
        if (isset($_COOKIE['promotion_from'])) {
            unset($_COOKIE['promotion_from']);
            setcookie('promotion_from', '', time() - 3600, '/');
        }

        if (!array_key_exists(User::SESSION, $_COOKIE)) {
            return;
        }

        $userToken = base64_decode((string)$_COOKIE[User::SESSION]);

        if (!$userToken) {
            return;
        }

        $head = JWT::getHead($userToken);
        if (!isset($head['uid'])) {
            return;
        }

        $user = \App\Model\User::query()->find($head['uid']);


        if (!$user) {
            return;
        }

        try {
            $jwt = \Firebase\JWT\JWT::decode($userToken, new Key($user->password, 'HS256'));
        } catch (\Exception $e) {
            return;
        }


        if ($jwt->expire <= time() || $user->login_time != $jwt->loginTime || $user->status != 1) {
            return;
        }

        //保存会话
        Context::set(User::SESSION, $user);
    }
}
