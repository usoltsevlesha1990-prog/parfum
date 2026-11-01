<?php
declare(strict_types=1);
// Environment variables are read from the process, not from a .env parser.
return [
    'socket' => getenv('DB_SOCKET') ?: '',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_NAME') ?: 'parfum_place_dev',
    'user' => getenv('DB_USER') ?: 'parfum_reader',
    'password' => getenv('DB_PASSWORD') ?: '',
];
