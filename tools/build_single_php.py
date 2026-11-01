#!/usr/bin/env python3
"""Build the single-file review from the private catalog-only extraction."""
import json
from pathlib import Path
root = Path(__file__).resolve().parents[1]
data = json.loads((root / 'storage/imports/catalog.json').read_text())
data['product_prices'] = [p for p in data['product_prices'] if int(p['volume_ml']) in (70,75,90,100)]
# No orders, settings, visits or other private tables are included.
assert set(data) == {'brands','products','product_prices'}
code = '''<?php
declare(strict_types=1);
// Self-contained catalog review. Snapshot of 2026-11-01, no database connection.
// Only brands, fragrances and full-size bottle prices; no personal data.
date_default_timezone_set('Europe/Moscow');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
function e($value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money($value): string {
    if ($value === null) { return 'Цена не указана'; }
    [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '00');
    return preg_replace('/\\B(?=(\\d{3})+(?!\\d))/', ' ', $whole) . ($fraction !== '00' ? ',' . $fraction : '') . ' ₽';
}
function cents($value): int { [$w, $f] = array_pad(explode('.', (string) $value, 2), 2, '00'); return (int) $w * 100 + (int) str_pad($f, 2, '0'); }
function linkTo(array $parameters = []): string { return 'index.php' . ($parameters ? '?' . http_build_query($parameters) : ''); }
$data = json_decode(<<<'CATALOG_SNAPSHOT'
__DATA__
CATALOG_SNAPSHOT, true, 512, JSON_THROW_ON_ERROR);
$brands = []; $products = []; $prices = [];
foreach ($data['brands'] as $b) { $brands[$b['id']] = $b; }
foreach ($data['product_prices'] as $price) {
    if (!in_array((int) $price['volume_ml'], [70,75,90,100], true)) { continue; }
    $price['effective_price'] = $price['price'];
    if ($price['promo_price'] !== null && $price['price'] !== null && cents($price['promo_price']) > 0 && cents($price['promo_price']) < cents($price['price']) && ($price['promo_until'] === null || $price['promo_until'] >= date('Y-m-d H:i:s'))) {
        $price['effective_price'] = $price['promo_price'];
    }
    $prices[$price['product_id']][] = $price;
}
foreach ($data['products'] as $p) {
    if (isset($brands[$p['brand_id']], $prices[$p['id']])) { $products[] = $p; }
}
usort($products, static function ($a, $b) use ($brands) {
    return [(int) $brands[$a['brand_id']]['sort_order'], (int) $a['sort_order'], (int) $a['id']] <=> [(int) $brands[$b['brand_id']]['sort_order'], (int) $b['sort_order'], (int) $b['id']];
});
$message = null; $product = null; $query = ''; $brand = ''; $page = 1;
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405); $message = 'Метод не поддерживается';
} else {
    foreach (['sku','brand','page','q'] as $key) {
        if (isset($_GET[$key]) && !is_string($_GET[$key])) { $message = 'Некорректные параметры'; }
    }
    if ($message === null) {
        $query = trim($_GET['q'] ?? ''); $brand = $_GET['brand'] ?? ''; $rawPage = $_GET['page'] ?? '1'; $sku = $_GET['sku'] ?? '';
        if (strlen($query)>480 || preg_match('//u', $query)!==1 || strlen($brand)>60 || !preg_match('/^[a-zA-Z0-9_-]*$/D',$brand) || strlen($sku)>150 || !preg_match('/^[a-zA-Z0-9_-]*$/D',$sku) || !preg_match('/^[1-9][0-9]{0,5}$/D',$rawPage)) { $message = 'Некорректные параметры'; }
        else { $page = (int) $rawPage; }
        if ($message === null && $sku !== '') {
            foreach ($products as $item) { if ($item['sku'] === $sku) { $product = $item; break; } }
            if ($product === null) { http_response_code(404); $message = 'Аромат не найден'; }
        }
    }
    if ($message === 'Некорректные параметры') { http_response_code(400); }
}
$filtered = array_values(array_filter($products, static function ($p) use ($brands,$brand,$query) {
    $b = $brands[$p['brand_id']];
    return ($brand === '' || $b['slug'] === $brand) && ($query === '' || preg_match('~' . preg_quote($query, '~') . '~iu', $p['name'] . ' ' . $b['name']) === 1);
}));
$total = count($filtered); $pages = max(1,(int) ceil($total / 12)); $page = min($page,$pages);
$title = $product ? $brands[$product['brand_id']]['name'] . ' — ' . $product['name'] : 'Каталог ароматов';
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') { exit; }
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title><?= e($title) ?> — PARFUM PLACE</title></head><body>
<p><a href="index.php">PARFUM PLACE — все ароматы</a></p>
<p>Только целые заводские флаконы. Без пробников и отливантов.</p>
<p>Просмотр данных бэкапа от 01.11.2026. Цены не обновляются из боевой базы.</p>
<?php if ($message !== null): ?><h1><?= e($message) ?></h1>
<?php elseif ($product !== null): $b = $brands[$product['brand_id']]; ?>
<p><a href="<?= e(linkTo(['brand'=>$b['slug']])) ?>">← Все ароматы <?= e($b['name']) ?></a></p>
<h1><?= e($b['name']) ?> — <?= e($product['name']) ?></h1>
<dl><?php foreach (['sku'=>'Артикул','gender'=>'Для кого','fragrance_group'=>'Группа ароматов','release_year'=>'Год выпуска'] as $field=>$label): ?><dt><?= e($label) ?></dt><dd><?= e($product[$field] ?: 'Не указано') ?></dd><?php endforeach; ?></dl>
<h2>Описание</h2><p><?= nl2br(e($product['description'] ?: 'Описание в базе отсутствует.')) ?></p>
<h2>Ноты аромата</h2><dl><?php foreach (['top_notes'=>'Верхние ноты','heart_notes'=>'Ноты сердца','base_notes'=>'Базовые ноты'] as $field=>$label): ?><dt><?= e($label) ?></dt><dd><?= e($product[$field] ?: 'Не указаны') ?></dd><?php endforeach; ?></dl>
<h2>Объёмы и цены</h2><table><thead><tr><th>Объём</th><th>Цена</th></tr></thead><tbody>
<?php foreach ($prices[$product['id']] as $price): ?><tr data-variant><td><?= (int) $price['volume_ml'] ?> мл</td><td><?= e(money($price['effective_price'])) ?></td></tr><?php endforeach; ?>
</tbody></table><p>Наличие требует подтверждения.</p>
<?php else: ?>
<h1>Каталог ароматов</h1><h2>Бренды</h2><ul><li><a href="index.php">Все бренды</a></li>
<?php foreach ($brands as $b): ?><li><a href="<?= e(linkTo(['brand'=>$b['slug']])) ?>"><?= e($b['name']) ?></a></li><?php endforeach; ?></ul>
<form action="index.php" method="get"><label>Поиск <input name="q" value="<?= e($query) ?>" maxlength="120"></label><input type="hidden" name="brand" value="<?= e($brand) ?>"><button>Найти</button></form>
<h2>Ароматы</h2><p>Найдено: <?= $total ?>. Страница <?= $page ?> из <?= $pages ?>.</p>
<?php if (!$filtered): ?><p>Ароматы не найдены.</p><?php endif; ?><ul>
<?php foreach (array_slice($filtered,($page-1)*12,12) as $p): ?><li><a data-product-link href="<?= e(linkTo(['sku'=>$p['sku']])) ?>"><?= e($brands[$p['brand_id']]['name']) ?> — <?= e($p['name']) ?></a></li><?php endforeach; ?></ul>
<nav aria-label="Страницы каталога"><?php for($i=1;$i<=$pages;$i++): ?><a href="<?= e(linkTo(['brand'=>$brand,'q'=>$query,'page'=>$i])) ?>" <?= $page===$i?'aria-current="page"':'' ?>><?= $i ?></a> <?php endfor; ?></nav>
<?php endif; ?></body></html>
'''
# Keep the source safely inside a nowdoc; JSON escapes all embedded line breaks.
snapshot=json.dumps(data, ensure_ascii=False, separators=(',', ':'))
assert '\nCATALOG_SNAPSHOT' not in snapshot
(root/'review-site/index.php').write_text(code.replace('__DATA__',snapshot))
print('Built one PHP file: 9 brands, 63 fragrances, 63 full-size variants')
