<?php
declare(strict_types=1);

// Run hourly as the PHP-FPM site user; --force seeds the first snapshot.
// Internal CLI worker only. Refresh needs no database, order or payment bootstrap.
if (PHP_SAPI !== 'cli') exit;
define('BASE_PATH', dirname(__DIR__));
define('DEBUG', false);
require BASE_PATH . '/vendor/autoload.php';
$options = getopt('', ['force']);
try {
    $result = \App\Util\DisplayCurrency::refresh(array_key_exists('force', $options));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    if ($result['result'] === 'error') exit(1);
} catch (\Throwable) {
    fwrite(STDERR, "Reference currency worker unavailable; the last valid cache is retained.\n");
    exit(1);
}
