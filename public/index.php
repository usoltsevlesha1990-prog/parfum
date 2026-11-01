<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

use ParfumPlace\Infrastructure\CatalogRepository;
use ParfumPlace\Infrastructure\Database;

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');

$status = 200; $product = null; $catalog = null; $brands = []; $message = null;
$query = ''; $brand = '';
try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD'); $status = 405; $message = 'Метод не поддерживается';
    } else {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($path) || ($path !== '/' && !preg_match('~^/product/([a-zA-Z0-9_-]+)$~D', $path, $matches))) {
            $status = 404; $message = 'Страница не найдена';
        } else {
            foreach (['q', 'brand', 'page'] as $key) {
                if (isset($_GET[$key]) && !is_string($_GET[$key])) { throw new InvalidArgumentException(); }
            }
            $query = trim($_GET['q'] ?? ''); $brand = $_GET['brand'] ?? '';
            $page = $_GET['page'] ?? '1';
            if (mb_strlen($query) > 120 || !mb_check_encoding($query, 'UTF-8') || strlen($brand) > 60 || !preg_match('/^[a-zA-Z0-9_-]*$/D', $brand) || !preg_match('/^[1-9][0-9]{0,5}$/D', $page)) { throw new InvalidArgumentException(); }
            $repository = new CatalogRepository(Database::connect());
            $brands = $repository->brands();
            if ($path === '/') { $catalog = $repository->search($query, $brand, (int) $page); }
            else {
                $product = $repository->product($matches[1]);
                if ($product === null) { $status = 404; $message = 'Аромат не найден'; }
            }
        }
    }
} catch (InvalidArgumentException $exception) {
    $status = 400; $message = 'Проверьте параметры поиска';
} catch (Throwable $exception) {
    // Never return exception messages or database credentials to visitors.
    error_log('PARFUM PLACE: catalog request failed (' . get_class($exception) . ')');
    $status = 503; $message = 'Каталог временно недоступен. Попробуйте позже.';
}
http_response_code($status);
$title = $product ? $product['brand_name'] . ' ' . $product['name'] : 'Каталог ароматов';
if ($message !== null) { $title = $message; }
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') { require dirname(__DIR__) . '/resources/views/store.php'; }
