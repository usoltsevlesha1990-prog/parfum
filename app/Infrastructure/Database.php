<?php
declare(strict_types=1);
namespace ParfumPlace\Infrastructure;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(): PDO
    {
        $c = require dirname(__DIR__, 2) . '/config/database.php';
        foreach (['name', 'port'] as $key) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $c[$key])) {
                throw new RuntimeException('Invalid database configuration');
            }
        }
        if (str_contains($c['host'], ';') || str_contains($c['socket'], ';')) {
            throw new RuntimeException('Invalid database endpoint');
        }
        $endpoint = $c['socket'] !== '' ? 'unix_socket=' . $c['socket'] : 'host=' . $c['host'] . ';port=' . $c['port'];
        $pdo = new PDO('mysql:' . $endpoint . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+03:00'");
        return $pdo;
    }
}
