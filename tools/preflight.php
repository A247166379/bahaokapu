<?php
declare(strict_types=1);
/** CLI-only, read-only checks: no app bootstrap, DB, network, sessions or writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['root:', 'installed']);
$root = realpath((string)($options['root'] ?? dirname(__DIR__)));
if ($root === false || !is_file($root . '/index.php')) {
    fwrite(STDERR, "Usage: php tools/preflight.php --root=/www/wwwroot/your-site [--installed]\n");
    exit(2);
}
$checks = [];
$check = static function (string $name, bool $ok, string $hint = '') use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'hint' => $hint];
};
$check('PHP >= 8.2', PHP_VERSION_ID >= 80200, 'Use the same PHP version/extensions for CLI and PHP-FPM.');
foreach (['bcmath','ctype','curl','dom','fileinfo','filter','gd','hash','iconv','json','mbstring','openssl','PDO','pdo_mysql','session','zip'] as $extension) {
    $check('extension:' . $extension, extension_loaded($extension));
}
$gd = function_exists('gd_info') ? gd_info() : [];
$check('GD WebP support', ($gd['WebP Support'] ?? false) === true,
    'Enable libwebp support in the GD build for both PHP-FPM and CLI; gd.loaded alone is insufficient.');
$check('function:imagecreatefromwebp', function_exists('imagecreatefromwebp'));
$check('function:imagewebp', function_exists('imagewebp'));
$check('vendor/autoload.php', is_file($root . '/vendor/autoload.php'), 'The ZIP must include complete, locked vendor files.');
$check('native install SQL', is_file($root . '/kernel/Install/Install.sql'));
$check('LocalStar theme', is_file($root . '/app/View/User/Theme/LocalStar/Index/Index.html'));
foreach (['config','runtime','assets/cache','app/Pay','app/Plugin','app/View/User/Theme','kernel/Install'] as $directory) {
    $path = $root . '/' . $directory;
    $check('directory:' . $directory, is_dir($path));
    $check('writable:' . $directory, is_dir($path) && is_writable($path), 'Run this check as the PHP-FPM service user; root results are insufficient.');
    $check('no Docker link:' . $directory, !is_link($path));
}
if (!isset($options['installed'])) $check('fresh install has no Lock', !file_exists($root . '/kernel/Install/Lock'));
$result = ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'passed' => !in_array(false, array_column($checks, 'ok'), true),
    'checks' => $checks, 'optional' => ['redis_loaded' => extension_loaded('redis'), 'opcache_loaded' => extension_loaded('Zend OPcache')],
    'read_only' => true, 'no_database_or_network' => true];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($result['passed'] ? 0 : 1);
