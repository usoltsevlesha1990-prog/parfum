<?php
declare(strict_types=1);
// Exports only public catalog content. No SQL, secrets or personal tables.
require dirname(__DIR__) . '/bootstrap/app.php';

use ParfumPlace\Infrastructure\CatalogRepository;
use ParfumPlace\Infrastructure\Database;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$directory = $argv[1] ?? dirname(__DIR__) . '/storage/exports/catalog-review';
if (file_exists($directory)) { throw new RuntimeException('Export destination already exists; choose a new directory'); }
if (!mkdir($directory, 0700, true)) { throw new RuntimeException('Cannot create export directory'); }
$repo = new CatalogRepository(Database::connect());
$brands = $repo->brands();
$static = true; $message = null; $query = ''; $product = null;
$render = static function (string $file, array $variables) use ($directory): void {
    extract($variables, EXTR_SKIP);
    ob_start();
    try {
        require dirname(__DIR__) . '/resources/views/store.php';
        $html = ob_get_clean();
    } catch (Throwable $error) { ob_end_clean(); throw $error; }
    if (file_put_contents($directory . '/' . $file, $html) === false) { throw new RuntimeException('Export write failed'); }
};
$productSkus = [];
foreach (array_merge([''], array_column($brands, 'slug')) as $brand) {
    $first = $repo->search('', $brand, 1);
    for ($page = 1; $page <= $first['pages']; $page++) {
        $catalog = $page === 1 ? $first : $repo->search('', $brand, $page);
        $file = $brand !== '' ? 'brand-' . $brand . '-' . $page . '.html' : ($page === 1 ? 'index.html' : 'catalog-' . $page . '.html');
        $title = 'Каталог ароматов';
        $render($file, compact('static', 'message', 'query', 'product', 'brands', 'brand', 'catalog', 'title'));
        foreach ($catalog['items'] as $item) { $productSkus[$item['sku']] = true; }
    }
}
foreach (array_keys($productSkus) as $sku) {
    $product = $repo->product($sku); $catalog = null; $brand = '';
    $title = $product['brand_name'] . ' — ' . $product['name'];
    $render('product-' . $sku . '.html', compact('static', 'message', 'query', 'product', 'brands', 'brand', 'catalog', 'title'));
}
echo 'Exported ' . count(glob($directory . '/*.html')) . " HTML pages.\n";
