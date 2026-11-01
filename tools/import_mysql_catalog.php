<?php
declare(strict_types=1);
// CLI-only initial import into an EMPTY, newly created catalog database.
if (PHP_SAPI !== 'cli') { exit(1); }
$configPath = $argv[1] ?? '';
$c = require $configPath;
if (!is_array($c) || !preg_match('/^[a-zA-Z0-9_]+$/D', $c['name'])) { throw new RuntimeException('Invalid config'); }
$db = new PDO('mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if ($db->query('SHOW TABLES')->fetchColumn() !== false) { throw new RuntimeException('Database is not empty; import refused'); }
$data = json_decode(file_get_contents(dirname(__DIR__) . '/tests/fixtures/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
if (array_keys($data) !== ['brands','products','product_prices']) { throw new RuntimeException('Only catalog tables are permitted'); }
foreach (require dirname(__DIR__) . '/bootstrap/catalog_schema.php' as $ddl) { $db->exec($ddl); }
$db->beginTransaction();
try {
    foreach ($data as $table=>$rows) {
        $columns = $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        $stmt=$db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        foreach($rows as $row) {
            if(array_keys($row)!==$columns) { throw new RuntimeException('Unexpected columns'); }
            $stmt->execute(array_values($row));
        }
    }
    $db->commit();
} catch(Throwable $error) { $db->rollBack();throw $error; }
echo "Imported brands, products and product_prices only.\n";
