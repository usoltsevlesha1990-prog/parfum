<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title><?= e($title) ?> — PARFUM PLACE</title><?php if ($product !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e(productUrl($product['sku'],$product['brand_slug'])) ?>"><?php elseif ($currentBrand !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e(linkTo(['brand'=>$brand,'page'=>$page,'q'=>$query])) ?>"><?php endif; ?></head><body>
<p><a href="/">PARFUM PLACE — все ароматы</a></p>
<p>Только целые заводские флаконы. Без пробников и отливантов.</p>
<p>Актуальный каталог из MySQL.</p>
<?php if ($message !== null): ?><h1><?= e($message) ?></h1>
<?php elseif ($product !== null): $b = $brands[$product['brand_id']]; ?>
<p><a href="<?= e(linkTo(['brand'=>$b['slug']])) ?>">← Все ароматы <?= e($b['name']) ?></a></p>
<h1 data-product-id="<?= (int) $product['id'] ?>"><?= e($b['name']) ?> — <?= e($product['name']) ?></h1>
<dl><?php foreach (['gender'=>'Для кого','fragrance_group'=>'Группа ароматов','release_year'=>'Год выпуска'] as $field=>$label): ?><dt><?= e($label) ?></dt><dd><?= e($product[$field] ?: 'Не указано') ?></dd><?php endforeach; ?></dl>
<?php $images = productImages($product['sku'], (int) $product['id']); ?>
<?php if ($images): ?><h2>Фотографии</h2>
<?php foreach ($images as $number => $url): ?>
<p><a href="<?= e($url) ?>"><img src="<?= e($url) ?>" alt="<?= e($b['name'] . ' ' . $product['name'] . ' — фото ' . ($number + 1)) ?>" width="320" loading="lazy"></a></p>
<?php endforeach; ?>
<?php else: ?><p>Фотографии для этого аромата пока не найдены.</p><?php endif; ?>
<h2>Описание</h2><p><?= nl2br(e($product['description'] ?: 'Описание в базе отсутствует.')) ?></p>
<h2>Ноты аромата</h2><dl><?php foreach (['top_notes'=>'Верхние ноты','heart_notes'=>'Ноты сердца','base_notes'=>'Базовые ноты'] as $field=>$label): ?><dt><?= e($label) ?></dt><dd><?= e($product[$field] ?: 'Не указаны') ?></dd><?php endforeach; ?></dl>
<h2>Объёмы и цены</h2><table><thead><tr><th>Объём</th><th>Цена</th></tr></thead><tbody>
<?php foreach ($prices[$product['id']] as $price): ?><tr data-variant><td><?= (int) $price['volume_ml'] ?> мл</td><td><?= e(money($price['effective_price'])) ?></td></tr><?php endforeach; ?>
</tbody></table><p>Наличие требует подтверждения.</p>
<?php else: ?>
<h1><?= e($currentBrand['name'] ?? 'Каталог ароматов') ?></h1><h2>Бренды</h2><ul><li><a href="/">Все бренды</a></li>
<?php foreach ($brands as $b): ?><li><a data-brand-link href="<?= e(linkTo(['brand'=>$b['slug']])) ?>"><?= e($b['name']) ?></a></li><?php endforeach; ?></ul>
<form action="/" method="get"><label>Поиск <input name="q" value="<?= e($query) ?>" maxlength="120"></label><input type="hidden" name="brand" value="<?= e($brand) ?>"><button>Найти</button></form>
<h2>Ароматы</h2><p>Найдено: <?= $total ?>. Страница <?= $page ?> из <?= $pages ?>.</p>
<?php if (!$items): ?><p>Ароматы не найдены.</p><?php endif; ?><ul>
<?php foreach ($items as $p): ?><li><a data-product-link href="<?= e(productUrl($p['sku'],$p['brand_slug'])) ?>"><?= e($brands[$p['brand_id']]['name']) ?> — <?= e($p['name']) ?></a></li><?php endforeach; ?></ul>
<nav aria-label="Страницы каталога"><?php for($i=1;$i<=$pages;$i++): ?><a href="<?= e(linkTo(['brand'=>$brand,'q'=>$query,'page'=>$i])) ?>" <?= $page===$i?'aria-current="page"':'' ?>><?= $i ?></a> <?php endfor; ?></nav>
<?php endif; ?></body></html>
