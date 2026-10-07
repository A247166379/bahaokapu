<?php
declare(strict_types=1);

use App\Util\LocalExtensionPolicy;
use Illuminate\Database\Capsule\Manager;
use Kernel\Consts\Base;
use Kernel\Util\Context;
use Kernel\Util\Plugin;

if (PHP_SAPI !== 'cli') exit;
$options = getopt('', ['database:', 'site-url:', 'limit:', 'bootstrap-only', 'help']);
if (array_key_exists('help', $options)) {
    echo "Usage: php scan-usdt-payments.php --database=<configured database> --site-url=https://example.com [--limit=1..20] [--bootstrap-only]\n";
    exit;
}
require __DIR__ . '/usdt-scanner-options.php';
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Shanghai');
$sourcePath = getenv('ACG_SOURCE_PATH') ?: dirname(__DIR__);
define('BASE_PATH', rtrim(realpath($sourcePath) ?: $sourcePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('DEBUG', false);
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/kernel/Helper.php';

$connection = config('database');
try { $scanner = usdtScannerOptions($options, $connection); }
catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
if (!is_file(BASE_PATH . '/kernel/Install/Lock')) {
    fwrite(STDERR, "The USDT scanner requires an installed site.\n");
    exit(2);
}

define('BASE_APP_SERVER', match ((int)(config('store')['server'] ?? 0)) {
    1 => App\Service\App::STANDBY_SERVER1,
    2 => App\Service\App::STANDBY_SERVER2,
    3 => App\Service\App::GENERAL_SERVER,
    default => App\Service\App::MAIN_SERVER,
});
define('APP_VERSION', (string)(config('app')['version'] ?? ''));

// Fulfilment and mail links use the same origin/timezone as the web kernel.
// A CLI environment must not supply an arbitrary Host or proxy header.
foreach (array_keys($_SERVER) as $key) {
    if (str_starts_with($key, 'HTTP_')) unset($_SERVER[$key]);
}
foreach ($scanner['server'] as $key => $value) $_SERVER[$key] = $value;
$_SERVER['HTTP_USER_AGENT'] = 'USDT payment scanner';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/user/api/usdt/check';
$_GET = ['s' => '/user/api/usdt/check'];
$_POST = $_COOKIE = $_FILES = $_REQUEST = [];
Kernel\Util\Lang::reset('zh-cn');
Context::set(Kernel\Context\Interface\Request::class, new Kernel\Context\Request());
Context::set(Base::ROUTE, '/user/api/usdt/check');
$installLock = BASE_PATH . '/kernel/Install/Lock';
Context::set(Base::LOCK, is_file($installLock) ? (string)file_get_contents($installLock) : '');
Context::set(Base::IS_INSTALL, is_file($installLock));
Context::set(Base::STORE_STATUS, is_file(BASE_PATH . '/kernel/Plugin.php'));
Context::set(Base::OPCACHE, extension_loaded('Zend OPcache') || extension_loaded('opcache'));
Context::set(Base::LANGUAGE, Kernel\Util\Lang::detect());

$db = new Manager();
$db->addConnection($connection);
$db->setAsGlobal();
$db->bootEloquent();

/** Register the public local Hook annotation contract without any store/license request. */
function usdtScannerLoadLocalHooks(): array
{
    Plugin::$container['hook'] = [];
    $summary = ['installed_plugins' => 0, 'enabled_plugins' => 0, 'registered_hooks' => 0];
    if (!Context::get(Base::STORE_STATUS) || !Context::get(Base::IS_INSTALL)) return $summary;
    $root = realpath(BASE_PATH . '/app/Plugin');
    if ($root === false) return $summary;
    foreach (scandir($root) ?: [] as $name) {
        $directory = LocalExtensionPolicy::installedDirectory($name, 0);
        if ($directory === null) continue;
        $summary['installed_plugins']++;
        $configPath = $directory . '/Config/Config.php';
        $configReal = realpath($configPath);
        if ($configReal === false || !is_file($configReal) || is_link($configPath)
            || !str_starts_with($configReal, $directory . DIRECTORY_SEPARATOR)) continue;
        $config = require $configReal;
        if (!is_array($config) || (int)($config[App\Consts\Plugin::STATUS] ?? 0) !== 1) continue;
        $summary['enabled_plugins']++;
        $hookPath = $directory . '/Hook';
        $hookDirectory = realpath($hookPath);
        if ($hookDirectory === false || is_link($hookPath)
            || !str_starts_with($hookDirectory, $directory . DIRECTORY_SEPARATOR)) continue;
        foreach (scandir($hookDirectory) ?: [] as $file) {
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.php$/D', $file, $match)) continue;
            $path = $hookDirectory . DIRECTORY_SEPARATOR . $file;
            $real = realpath($path);
            if ($real === false || !is_file($real) || is_link($path)
                || !str_starts_with($real, $hookDirectory . DIRECTORY_SEPARATOR)) continue;
            $namespace = 'App\\Plugin\\' . $name . '\\Hook\\' . $match[1];
            if (!class_exists($namespace)) throw new RuntimeException('Installed plugin hook class unavailable');
            $class = new ReflectionClass($namespace);
            if (!$class->isInstantiable() || realpath((string)$class->getFileName()) !== $real) {
                throw new RuntimeException('Installed plugin hook class invalid');
            }
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (realpath((string)$method->getFileName()) !== $real) continue;
                foreach ($method->getAttributes(Kernel\Annotation\Hook::class) as $attribute) {
                    $arguments = $attribute->getArguments();
                    $point = $arguments['point'] ?? $arguments[0] ?? null;
                    if (!is_int($point) || $point < 0) throw new RuntimeException('Installed plugin hook point invalid');
                    Plugin::$container['hook'][$point][] = ['namespace' => $namespace,
                        'method' => $method->getName(), 'pluginName' => $name];
                    $summary['registered_hooks']++;
                }
            }
        }
    }
    return $summary;
}

try {
    $bootstrap = usdtScannerLoadLocalHooks();
} catch (Throwable $exception) {
    fwrite(STDERR, 'USDT scanner bootstrap failed (' . get_class($exception) . ").\n");
    exit(1);
}

// This path never connects to the database, dispatches hooks or queries the chain.
if (array_key_exists('bootstrap-only', $options)) {
    echo json_encode($bootstrap + ['timezone' => date_default_timezone_get(), 'origin' => App\Util\Client::getUrl(),
        'installed' => (bool)Context::get(Base::IS_INSTALL), 'store_available' => (bool)Context::get(Base::STORE_STATUS),
        'scanned' => 0, 'confirmed' => 0], JSON_THROW_ON_ERROR), "\n";
    exit;
}

// Maintenance stops new purchases, while existing invoices retain their settlement window.
// Web-only routing, entrance guards and KERNEL_INIT callbacks are not dispatched by this worker.
$path = BASE_PATH . '/runtime/usdt-scanner.lock';
$lock = fopen($path, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
try {
    $rows = Manager::table(App\Util\UsdtPayment::TABLE)->where('status', 'pending')
        ->where('settlement_expires_at', '>=', time())->orderBy('last_check_at')
        ->limit($scanner['limit'])->get();
    $paid = 0;
    foreach ($rows as $row) {
        $result = App\Util\UsdtPayment::scan((array)$row);
        if ($result['status'] === 'paid') $paid++;
    }
    echo json_encode(['scanned' => count($rows), 'confirmed' => $paid], JSON_THROW_ON_ERROR), "\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
