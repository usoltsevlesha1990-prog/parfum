<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use ParfumPlace\Infrastructure\Database;
use ParfumPlace\Infrastructure\CatalogRepository;

function check(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
$db = Database::connect();
$repo = new CatalogRepository($db);
check(count($repo->brands()) === 9, '9 imported brands');
check($repo->search('', '', 1)['total'] === 63, '63 imported fragrances');
check($repo->search("' OR 1=1 --", '', 1)['total'] === 0, 'SQL injection treated as search text');
check($repo->search('%', '', 1)['total'] === 0, 'LIKE wildcard escaped');
check($repo->search('', '', 999)['page'] === 6, 'pagination clamped');
$product = $repo->product('louis-vuitton-ombre-nomade');
check($product !== null && count($product['variants']) === 1, 'only full-size bottle in product variants');
check($repo->product('does-not-exist') === null, 'missing product');
check(rubles('12345.67') === '12 345,67 ₽' && rubles('1990.00') === '1 990 ₽', 'exact decimal formatting without float');
check(e('<script>') === '&lt;script&gt;', 'HTML escaping');
$denied = false;
try { $db->exec('UPDATE products SET name=name WHERE 1=0'); } catch (PDOException $exception) { $denied = (int) ($exception->errorInfo[1] ?? 0) === 1142; }
check($denied, 'reader cannot UPDATE catalog');
check($db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() == 3, 'only 3 catalog tables imported');

$all = $db->query('SELECT p.sku FROM products p ORDER BY p.id')->fetchAll(PDO::FETCH_COLUMN);
foreach ($all as $sku) {
    $item = $repo->product($sku);
    check($item !== null && count($item['variants']) === 1 && (int) $item['variants'][0]['volume_ml'] > 10, 'factory bottle only: ' . $sku);
}
$firstPage = $repo->search('', '', 1);
foreach ($firstPage['items'] as $item) {
    $details = $repo->product($item['sku']);
    check($item['min_price'] === $details['variants'][0]['effective_price'], 'card price excludes small volumes: ' . $item['sku']);
}
