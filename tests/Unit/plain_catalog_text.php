<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/tools/clean_catalog_text.php';
$cases = [
    ["<p>Первый</p><p><b>Второй</b><br>Третий</p>", 'Первый Второй Третий'],
    [" Аромат\r\n\tс\u{00A0} нотами  розы ", 'Аромат с нотами розы'],
    ['&lt;strong&gt;Rose &amp; Oud&lt;/strong&gt;', 'Rose & Oud'],
    ['&amp;lt;p&amp;gt;Аромат&amp;lt;/p&amp;gt;', 'Аромат'],
    ['<script>alert(1)</script>Роза<style>body{}</style><!-- note -->', 'Роза'],
    ['Tom Ford &quot;Noir&quot; — 100 мл', 'Tom Ford "Noir" — 100 мл'],
    ['Цена < 5000', 'Цена < 5000'],
    ['', ''],
];
foreach ($cases as $number => [$input, $expected]) {
    $actual = plainCatalogText($input);
    if ($actual !== $expected || plainCatalogText($actual) !== $actual) { throw new RuntimeException('Plain-text case failed: ' . $number); }
}
echo 'PASS: ' . count($cases) . " plain-text and idempotency cases\n";
