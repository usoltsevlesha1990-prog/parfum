<?php
$static = $static ?? false;
$catalogUrl = static function (string $brandSlug = '', int $pageNumber = 1, string $search = '') use ($static): string {
    if ($static) {
        return $brandSlug !== '' ? 'brand-' . rawurlencode($brandSlug) . '-' . $pageNumber . '.html' : ($pageNumber === 1 ? 'index.html' : 'catalog-' . $pageNumber . '.html');
    }
    return '/?' . http_build_query(['brand' => $brandSlug, 'page' => $pageNumber, 'q' => $search]);
};
$productUrl = static fn (string $sku): string => $static ? 'product-' . rawurlencode($sku) . '.html' : '/product/' . rawurlencode($sku);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — PARFUM PLACE</title>
</head>
<body>
<header>
<p><a href="<?= e($catalogUrl()) ?>">PARFUM PLACE — каталог</a></p>
<p>Только целые заводские флаконы. Без пробников и отливантов.</p>
</header>
<main>
<?php if ($message !== null): ?>
<h1><?= e($message) ?></h1>
<p><a href="<?= e($catalogUrl()) ?>">К списку ароматов</a></p>
<?php elseif ($product !== null): ?>
<p><a href="<?= e($catalogUrl($product['brand_slug'])) ?>">← Все ароматы <?= e($product['brand_name']) ?></a></p>
<h1><?= e($product['brand_name']) ?> — <?= e($product['name']) ?></h1>
<dl>
<dt>Артикул</dt><dd><?= e($product['sku']) ?></dd>
<?php foreach (['gender' => 'Для кого', 'fragrance_group' => 'Группа ароматов', 'release_year' => 'Год выпуска'] as $field => $label): ?>
<?php if ($product[$field]): ?><dt><?= e($label) ?></dt><dd><?= e($product[$field]) ?></dd><?php endif; ?>
<?php endforeach; ?>
</dl>
<h2>Описание</h2>
<?php if ($product['description']): ?><p><?= nl2br(e($product['description'])) ?></p><?php else: ?><p>Описание в базе отсутствует.</p><?php endif; ?>
<h2>Ноты аромата</h2>
<dl>
<?php foreach (['top_notes' => 'Верхние ноты', 'heart_notes' => 'Ноты сердца', 'base_notes' => 'Базовые ноты'] as $field => $label): ?>
<dt><?= e($label) ?></dt><dd><?= e($product[$field] ?: 'Не указаны') ?></dd>
<?php endforeach; ?>
</dl>
<h2>Объёмы и цены</h2>
<table>
<caption>Заводские флаконы</caption>
<thead><tr><th scope="col">Объём</th><th scope="col">Цена</th></tr></thead>
<tbody>
<?php foreach ($product['variants'] as $variant): ?>
<tr data-variant><td><?= (int) $variant['volume_ml'] ?> мл</td><td><?= e(rubles($variant['effective_price'])) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p>Цены из предоставленной копии каталога. Наличие требует подтверждения.</p>
<p><a href="<?= e($catalogUrl()) ?>">Все ароматы</a> | <a href="<?= e($catalogUrl($product['brand_slug'])) ?>">Ароматы этого бренда</a></p>
<?php else: ?>
<h1>Каталог ароматов</h1>
<h2>Бренды</h2>
<ul>
<li><a href="<?= e($catalogUrl()) ?>" <?= $brand === '' ? 'aria-current="page"' : '' ?>>Все бренды</a></li>
<?php foreach ($brands as $item): ?>
<li><a href="<?= e($catalogUrl($item['slug'])) ?>" <?= $brand === $item['slug'] ? 'aria-current="page"' : '' ?>><?= e($item['name']) ?></a> (<?= (int) $item['product_count'] ?>)</li>
<?php endforeach; ?>
</ul>
<?php if (!$static): ?>
<form method="get" action="/">
<label for="search">Поиск по названию аромата или бренду</label>
<input id="search" type="search" name="q" maxlength="120" value="<?= e($query) ?>">
<input type="hidden" name="brand" value="<?= e($brand) ?>">
<button type="submit">Найти</button>
</form>
<?php endif; ?>
<h2>Ароматы</h2>
<p>Найдено: <?= (int) $catalog['total'] ?>. Страница <?= (int) $catalog['page'] ?> из <?= (int) $catalog['pages'] ?>.</p>
<?php if (!$catalog['items']): ?><p>Ароматы не найдены.</p><?php endif; ?>
<ul>
<?php foreach ($catalog['items'] as $item): ?>
<li><a data-product-link href="<?= e($productUrl($item['sku'])) ?>"><?= e($item['brand_name']) ?> — <?= e($item['name']) ?></a> — <?= e(rubles($item['min_price'])) ?></li>
<?php endforeach; ?>
</ul>
<nav aria-label="Страницы каталога">
<?php if ($catalog['page'] > 1): ?><a href="<?= e($catalogUrl($brand, $catalog['page'] - 1, $query)) ?>">← Предыдущая</a><?php endif; ?>
<?php for ($i = 1; $i <= $catalog['pages']; $i++): ?>
<a href="<?= e($catalogUrl($brand, $i, $query)) ?>" <?= $i === $catalog['page'] ? 'aria-current="page"' : '' ?>><?= $i ?></a>
<?php endfor; ?>
<?php if ($catalog['page'] < $catalog['pages']): ?><a href="<?= e($catalogUrl($brand, $catalog['page'] + 1, $query)) ?>">Следующая →</a><?php endif; ?>
</nav>
<?php endif; ?>
</main>
</body>
</html>
