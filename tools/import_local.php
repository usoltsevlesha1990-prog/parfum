<?php
declare(strict_types=1);
// Creates a NEW development-only database via a local Unix socket; no remote DSN.
$socket = getenv('PARFUM_LOCAL_SOCKET') ?: '';
if (PHP_SAPI !== 'cli' || $socket === '' || $socket[0] !== '/' || str_contains($socket, ';') || !file_exists($socket)) {
    fwrite(STDERR, "Set PARFUM_LOCAL_SOCKET to an existing isolated development socket.\n"); exit(1);
}
$data = json_decode(file_get_contents($argv[1] ?? dirname(__DIR__) . '/storage/imports/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$tables = ['brands', 'products', 'product_prices'];
if (array_keys($data) !== $tables) { throw new RuntimeException('Only catalog data is permitted'); }
$db = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
// No IF NOT EXISTS: refuse to overwrite or merge an existing database.
$db->exec('CREATE DATABASE parfum_place_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db->exec('USE parfum_place_dev');
foreach (require dirname(__DIR__) . '/bootstrap/catalog_schema.php' as $ddl) { $db->exec($ddl); }
$db->beginTransaction();
try {
    foreach ($tables as $table) {
        $allowed = $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        $statement = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $allowed) . '`) VALUES (' . implode(',', array_fill(0, count($allowed), '?')) . ')');
        foreach ($data[$table] as $row) {
            if (array_keys($row) !== $allowed) { throw new RuntimeException('Unexpected columns'); }
            $statement->execute(array_values($row));
        }
    }
    $db->commit();
} catch (Throwable $exception) { $db->rollBack(); throw $exception; }
// No password is needed for this isolated socket-only development instance.
$db->exec("CREATE USER 'parfum_reader'@'localhost'");
foreach ($tables as $table) { $db->exec('GRANT SELECT ON parfum_place_dev.`' . $table . "` TO 'parfum_reader'@'localhost'"); }
echo "Local catalog imported; reader has SELECT access to 3 tables only.\n";
