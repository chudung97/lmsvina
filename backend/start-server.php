<?php
// Server script for Laravel on PHP built-in server
// Usage: php server.php
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

$documentRoot = __DIR__ . '/public';
$router = __DIR__ . '/server.php';

$host = '127.0.0.1';
$port = 8000;

$cmd = sprintf(
    'php -S %s:%d -t %s %s 2>&1',
    escapeshellarg($host),
    $port,
    escapeshellarg($documentRoot),
    escapeshellarg($router)
);

echo "Starting: $cmd\n";
passthru($cmd);