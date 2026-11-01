<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#7a3045">
<title><?= e($title) ?> — PARFUM PLACE</title>
<?php if ($product !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e(productUrl($product['sku'],$product['brand_slug'])) ?>">
<?php elseif ($currentBrand !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e(linkTo(['brand'=>$brand,'page'=>$page,'q'=>$query])) ?>">
<?php elseif ($staticPage !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e($path) ?>"><?php elseif ($isHome): ?><link rel="canonical" href="https://parfumplace.ru/"><?php else: ?><link rel="canonical" href="https://parfumplace.ru<?= e(linkTo(['page'=>$page,'q'=>$query])) ?>"><?php endif; ?>
<style nonce="<?= e($styleNonce) ?>">__STYLE__</style>
</head>
<body>
<a class="skip" href="#main">Перейти к содержимому</a>
<header class="header wrap">
<details class="menu"><summary aria-label="Открыть меню"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></summary>
<nav aria-label="Основное меню"><a href="/">Главная</a><a href="/catalog.html">Каталог</a><?php foreach ($staticPages as $url=>$label): ?><a href="<?= e($url) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></details>
<a class="wordmark" href="/" aria-label="PARFUM PLACE — главная">PARFUM PLACE</a>
<a class="header-contact" href="/contacts.html">Контакты</a>
</header>
<main id="main" class="wrap">
<?php if ($message !== null): ?>
<section class="error"><p class="eyebrow">PARFUM PLACE</p><h1><?= e($message) ?></h1><a class="outline-button" href="/catalog.html">Вернуться в коллекцию</a></section>
<?php elseif ($staticPage !== null): ?>
<section class="info"><a class="breadcrumb" href="/catalog.html">← В коллекцию</a><h1><?= e($staticPage) ?></h1><p class="muted">Информация скоро появится.</p></section>
<?php elseif ($product !== null): $b=$brands[$product['brand_id']]; $images=productImages($product['sku'],(int)$product['id']); ?>
<!-- Актуальный каталог из MySQL. -->
<a class="breadcrumb" href="<?= e(linkTo(['brand'=>$b['slug']])) ?>">← Все ароматы <?= e($b['name']) ?></a>
<article class="detail">
<div class="gallery" aria-label="Фотографии аромата">
<?php if ($images): ?>
<?php foreach ($images as $number=>$url): ?><figure><a href="<?= e($url) ?>"><img src="<?= e($url) ?>" alt="<?= e($b['name'].' '.$product['name'].' — '.productImageLabel((int)$product['id'],$url)) ?>" width="640" height="640" <?= $number===0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>></a><figcaption><?= e(productImageLabel((int)$product['id'],$url)) ?> · <?= $number+1 ?> / <?= count($images) ?><?php if(count($images)>1): ?> · Листайте фотографии<?php endif; ?></figcaption></figure><?php endforeach; ?>
<?php else: ?><figure class="no-photo">Фотографии для этого аромата пока не найдены.</figure><?php endif; ?>
</div>
<div>
<p class="eyebrow"><?= e($b['name']) ?></p>
<h1 data-product-id="<?= (int)$product['id'] ?>"><?= e($product['name']) ?></h1>
<dl class="facts"><?php foreach (['gender'=>'Для кого','fragrance_group'=>'Группа ароматов','release_year'=>'Год выпуска'] as $field=>$label): ?><?php if($product[$field]): ?><div><dt><?= e($label) ?></dt><dd><?= e($product[$field]) ?></dd></div><?php endif; ?><?php endforeach; ?></dl>
<h2>Объём и цена</h2>
<table class="variants"><thead><tr><th scope="col">Заводской флакон</th><th scope="col">Цена</th></tr></thead><tbody>
<?php foreach($prices[$product['id']] as $price): ?><tr data-variant><td><?= (int)$price['volume_ml'] ?> мл</td><td><?= e(money($price['effective_price'])) ?></td></tr><?php endforeach; ?>
</tbody></table><p class="muted">Наличие требует подтверждения.</p>
<h2>Описание</h2><p class="description"><?= nl2br(e($product['description'] ?: 'Описание пока не добавлено.')) ?></p>
<h2>Ноты аромата</h2><dl class="notes"><?php foreach(['top_notes'=>'Верхние ноты','heart_notes'=>'Ноты сердца','base_notes'=>'Базовые ноты'] as $field=>$label): ?><div><dt><?= e($label) ?></dt><dd><?= e($product[$field] ?: 'Не указаны') ?></dd></div><?php endforeach; ?></dl>
</div></article>
<?php else: ?>
<!-- Актуальный каталог из MySQL. -->
<section class="hero <?= $isHome ? 'home-hero' : '' ?>"><p class="eyebrow">Коллекция PARFUM PLACE</p>
<?php if ($currentBrand !== null): ?><h1><?= e($currentBrand['name']) ?></h1><p>Найдите свой аромат в коллекции бренда</p>
<?php elseif ($isHome): ?><h1>Ароматы,<br>которые говорят о вас</h1><p>Знакомьтесь с парфюмерными домами.<br>Выбирайте своё звучание.</p><a class="primary-button" href="/catalog.html">Смотреть коллекцию <span aria-hidden="true">↗</span></a>
<?php else: ?><h1>Коллекция ароматов</h1><p>Парфюмерия мировых брендов</p><?php endif; ?></section>
<?php if ($isHome): ?>
<section class="home-brands" aria-labelledby="brand-title"><div class="section-heading"><div><p class="eyebrow">Знакомые имена. Разные характеры.</p><h2 id="brand-title">Парфюмерные дома</h2></div><a class="text-link" href="/catalog.html">Все бренды ↗</a></div>
<nav class="brand-carousel" aria-label="Лента брендов">
<?php foreach($brands as $b): if($b['slug']==='prochie') { continue; } ?><a class="brand-tile brand-<?= e($b['slug']) ?>" data-brand-link href="<?= e(linkTo(['brand'=>$b['slug']])) ?>"><span class="brand-wordmark"><?= e($b['name']) ?></span><small><?= e(aromaCount((int)$b['product_count'])) ?></small></a><?php endforeach; ?>
</nav><p class="swipe-hint">Листайте, чтобы увидеть все бренды <span aria-hidden="true">→</span></p>
</section>
<?php else: ?>
<nav class="brand-tabs" aria-label="Бренды"><a href="/catalog.html" <?= $brand==='' ? 'aria-current="page"' : '' ?>>Все бренды</a><?php foreach($brands as $b): ?><a data-brand-link href="<?= e(linkTo(['brand'=>$b['slug']])) ?>" <?= $brand===$b['slug'] ? 'aria-current="page"' : '' ?>><?= e($b['name']) ?></a><?php endforeach; ?></nav>
<?php endif; ?>
<section class="<?= $isHome ? 'home-featured' : 'catalog-section' ?>" aria-labelledby="collection-title"><div class="section-heading"><div><?php if($isHome): ?><p class="eyebrow">Десять ароматов из нашей коллекции</p><?php endif; ?><h2 id="collection-title"><?= $isHome ? 'В центре внимания' : 'Коллекция ароматов' ?></h2></div><?php if(!$isHome): ?><span class="muted"><?= e(aromaCount((int)$total)) ?></span><?php endif; ?></div>
<?php if (!$items): ?><div class="empty"><p>Ароматы не найдены.</p><a href="/catalog.html">Вся коллекция</a></div><?php endif; ?>
<div class="<?= $isHome ? 'feature-track' : 'grid' ?>"<?= $isHome ? ' tabindex="0" role="region" aria-label="Подборка ароматов — листайте горизонтально"' : '' ?>>
<?php foreach($items as $cardIndex=>$p): $photos=productImages($p['sku'],(int)$p['id']); ?>
<a class="card" data-product-link href="<?= e(productUrl($p['sku'],$p['brand_slug'])) ?>">
<div class="card-photo"><?php if($photos): ?><img src="<?= e($photos[0]) ?>" alt="<?= e($brands[$p['brand_id']]['name'].' '.$p['name']) ?>" width="320" height="320" loading="<?= $cardIndex<2 ? 'eager' : 'lazy' ?>"><?php else: ?><span class="no-photo">Фото скоро появится</span><?php endif; ?></div>
<p class="card-brand"><?= e($brands[$p['brand_id']]['name']) ?></p><h3 class="card-name"><?= e($p['name']) ?></h3>
<p class="card-volume"><?= e(str_replace(',',', ',$p['volumes'] ?? '')) ?> мл</p><p class="card-price"><?= e(money($p['min_price'])) ?></p><span class="outline-button">Подробнее</span>
</a><?php endforeach; ?>
</div>
<?php if($isHome): ?><p class="swipe-hint">Листайте подборку <span aria-hidden="true">→</span></p><div class="all-catalog"><a class="outline-button" href="/catalog.html">Весь каталог · <?= e(aromaCount((int)$total)) ?> <span aria-hidden="true">↗</span></a></div>
<?php else: ?>
<nav class="pagination" aria-label="Страницы каталога"><?php for($i=1;$i<=$pages;$i++): ?><a href="<?= e(linkTo(['brand'=>$brand,'q'=>$query,'page'=>$i])) ?>" <?= $page===$i?'aria-current="page"':'' ?> aria-label="Страница <?= $i ?>"><?= $i ?></a><?php endfor; ?></nav>
<p class="page-caption">Найдено: <?= (int)$total ?>. Страница <?= (int)$page ?> из <?= (int)$pages ?>.</p>
<?php endif; ?>
</section>
<?php endif; ?>
</main>
<footer class="footer wrap"><nav aria-label="Информация"><?php foreach($staticPages as $url=>$label): ?><a href="<?= e($url) ?>"><?= e($label) ?></a><?php endforeach; ?></nav><small>PARFUM PLACE · <?= date('Y') ?></small></footer>
<a class="manager" href="/contacts.html" aria-label="Связаться с менеджером"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a8 8 0 0 1-8 8 9 9 0 0 1-3.5-.7L4 20l1.2-4.5a8 8 0 1 1 14.8-4z"/><path d="M8 11h.01M12 11h.01M16 11h.01" stroke-linecap="round" stroke-width="2"/></svg></a>
</body></html>
