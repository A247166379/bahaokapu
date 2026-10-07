<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Pure option validation; no bootstrap, file write, database or network. */
function usdtScannerOptions(array $options, array $connection): array
{
    $database = $options['database'] ?? null;
    $configured = $connection['database'] ?? null;
    if (!is_string($database) || !is_string($configured)
        || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)
        || !hash_equals($configured, $database)) {
        throw new InvalidArgumentException('The exact configured --database name is required.');
    }
    $origin = $options['site-url'] ?? null;
    if (!is_string($origin) || strlen($origin) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $origin)) {
        throw new InvalidArgumentException('A valid --site-url origin is required.');
    }
    try { $url = parse_url($origin); }
    catch (ValueError) { throw new InvalidArgumentException('The --site-url port is invalid.'); }
    if (!is_array($url) || !in_array($url['scheme'] ?? '', ['https', 'http'], true)
        || empty($url['host']) || isset($url['user']) || isset($url['pass'])
        || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
        throw new InvalidArgumentException('The --site-url must be an HTTP(S) origin without credentials, path or query.');
    }
    $host = $url['host'];
    $plainHost = trim($host, '[]');
    $validIp = filter_var($plainHost, FILTER_VALIDATE_IP) !== false;
    if (!$validIp && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host)) {
        throw new InvalidArgumentException('The --site-url host is invalid; use an ASCII hostname or IP.');
    }
    $port = $url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80);
    if ($port < 1 || $port > 65535) throw new InvalidArgumentException('The --site-url port is invalid.');
    $authority = $host . (isset($url['port']) ? ':' . $port : '');
    $limit = $options['limit'] ?? '10';
    if (!is_string($limit) || !preg_match('/^[1-9][0-9]?$/D', $limit) || (int)$limit > 20) {
        throw new InvalidArgumentException('The --limit must be an integer from 1 to 20.');
    }
    return ['database' => $database, 'limit' => (int)$limit,
        'origin' => $url['scheme'] . '://' . $authority,
        'server' => ['HTTP_HOST' => $authority, 'HTTPS' => $url['scheme'] === 'https' ? 'on' : 'off',
            'REQUEST_SCHEME' => $url['scheme'], 'SERVER_PORT' => (string)$port]];
}
