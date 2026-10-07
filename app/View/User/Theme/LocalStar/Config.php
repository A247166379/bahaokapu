<?php
declare(strict_types=1);

namespace App\View\User\Theme\LocalStar;

use App\Consts\Render;

/**
 * Current storefront, authentication and account templates.
 */
interface Config
{
    public const INFO = [
    ];

    public const SUBMIT = [];

    public const THEME = [
        "INDEX" => "Index/Index.html",
        "ITEM" => "Index/Item.html",
        "QUERY" => "Index/Query.html",
        "CONTENT" => "Index/Content.html",
        "CLOSED" => "Index/Closed.html",
        "LOGIN" => "Authentication/Login.html",
        "REGISTER" => "Authentication/Register.html",
        "FORGET_EMAIL" => "Authentication/ForgetEmail.html",
        "FORGET_PHONE" => "Authentication/ForgetPhone.html",
        "DASHBOARD" => "Account/Dashboard/Index.html",
        "PERSONAL" => "Account/User/Personal.html",
        "EMAIL" => "Account/User/Email.html",
        "PHONE" => "Account/User/Phone.html",
        "PASSWORD" => "Account/User/Password.html",
        "PURCHASE_RECORD" => "Account/User/PurchaseRecord.html",
    ];
}
