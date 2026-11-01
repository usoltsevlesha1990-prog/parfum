<?php
declare(strict_types=1);
// PHP development server router. Only actual public assets may bypass index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && preg_match('~^/assets/[a-zA-Z0-9/_-]+\.(css|js|svg|png|jpg|webp)$~D', $path) && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
