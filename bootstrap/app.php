<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'ParfumPlace\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    }
});
date_default_timezone_set('Europe/Moscow');

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function rubles(?string $value): string {
    if ($value === null) { return 'Цена уточняется'; }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole);
    return $whole . ($fraction !== '00' ? ',' . $fraction : '') . ' ₽';
}
