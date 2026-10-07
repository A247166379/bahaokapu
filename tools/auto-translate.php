<?php
declare(strict_types=1);

// Internal CLI worker only. No HTTP route and no payment/order bootstrap.
if (PHP_SAPI !== 'cli') exit;
define('BASE_PATH', dirname(__DIR__));
define('DEBUG', false);
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/kernel/Helper.php';
$database = new \Illuminate\Database\Capsule\Manager();
$database->addConnection(config('database'));
$database->setAsGlobal();
$database->bootEloquent();
try {
    $result = (new \App\Util\AutoTranslationService())->run();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (\Throwable) {
    fwrite(STDERR, "Translation worker unavailable; no secret or provider response is logged.\n");
    exit(1);
}
