<?php
declare(strict_types=1);

namespace App\Pay\UsdtTrc20\Impl;

/** This plugin settles only through locally verified chain evidence. */
final class Signature implements \App\Pay\Signature
{
    public function verification(array $data, array $config): bool
    {
        return false;
    }
}
