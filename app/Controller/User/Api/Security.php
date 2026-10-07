<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Email;
use App\Service\Sms;
use App\Util\Captcha;
use App\Util\Str;
use App\Util\Validation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Security extends User
{
    #[Inject]
    private Email $email;

    #[Inject]
    private Sms $sms;

    /**
     * @return array
     * @throws JSONException
     */
    public function personal(): array
    {
        $user = $this->getUser();
        $fields = \App\Util\BuyerProfile::fields((array)$this->request->post());
        foreach ($fields as $key => $value) $user->$key = $value;
        $user->save();
        return $this->json(200, "修改成功");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function email(): array
    {
        //改绑前必须用登录密码二次验证：只凭会话（可能经 XSS/共享设备被窃）就能改绑，会被攻击者改到
        //自己的邮箱再走找回密码永久接管（F-33）。要求账号密码=只有会话也改不了绑定。
        $user = $this->getUser();
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("登录密码不正确");
        }
        if (!$this->email->checkCaptcha($_POST['email'], Email::CAPTCHA_BIND_NEW, (int)$_POST['email_captcha'])) {
            throw new JSONException("邮箱验证码不正确");
        }
        $user->email = $_POST['email'];
        $user->save();

        $this->email->destroyCaptcha($user->email, Email::CAPTCHA_BIND_NEW);
        return $this->json(200, "修改成功");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function phone(): array
    {
        //改绑前必须用登录密码二次验证（同 email()，防会话被窃后改绑手机再走找回密码永久接管，F-33）。
        $user = $this->getUser();
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("登录密码不正确");
        }
        if (!$this->sms->checkCaptcha($_POST['phone'], Sms::CAPTCHA_BIND_NEW, (int)$_POST['phone_captcha'])) {
            throw new JSONException("手机验证码不正确");
        }
        $user->phone = $_POST['phone'];
        $user->save();

        $this->sms->destroyCaptcha($user->phone, Sms::CAPTCHA_BIND_NEW);
        return $this->json(200, "修改成功");
    }

    /**
     * @throws JSONException
     */
    public function password(): array
    {
        $oldPassword = (string)$_POST['old_password'];
        $password = (string)$_POST['password'];
        $rePassword = (string)$_POST['re_password'];
        $user = $this->getUser();
        //兼容旧清洗管线时代哈希的特殊字符密码（#833），改密成功后即升级为新形态
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, $oldPassword, (string)$this->request->unsafePost('old_password'))) {
            throw new JSONException("旧密码输入不正确");
        }
        if ($password != $rePassword) {
            throw new JSONException("两次密码输入不一致");
        }

        if (!Validation::password($password)) {
            throw new JSONException("新密码格式不正确，密码必须6位以上");
        }

        $user->password = Str::generatePassword($password, $user->salt);
        $user->save();
        return $this->json(200, "修改成功");
    }

    /**
     * @throws JSONException
     */
    public function emailBindNew(): array
    {
        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "emailBindNew")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['email']) || !Validation::email((string)$_POST['email'])) {
            throw new JSONException("邮箱地址不正确");
        }

        if (\App\Model\User::query()->where("email", $_POST['email'])->first()) {
            throw new JSONException("该邮箱已被他人绑定");
        }
        $this->email->sendCaptcha((string)$_POST['email'], Email::CAPTCHA_BIND_NEW);
        Captcha::destroy("emailBindNew");
        return $this->json(200, "验证码发送成功");
    }

    /**
     * @throws JSONException
     */
    public function phoneBindNew(): array
    {
        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "phoneBindNew")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['phone']) || !Validation::phone((string)$_POST['phone'])) {
            throw new JSONException("手机号码不正确");
        }

        if (\App\Model\User::query()->where("phone", $_POST['phone'])->first()) {
            throw new JSONException("该手机已被他人绑定");
        }

        $this->sms->sendCaptcha((string)$_POST['phone'], Sms::CAPTCHA_BIND_NEW);
        Captcha::destroy("phoneBindNew");
        return $this->json(200, "验证码发送成功");
    }


}
