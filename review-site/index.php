<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
function e($value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money($value): string {
    if ($value === null) { return 'Цена не указана'; }
    [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '00');
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) . ($fraction !== '00' ? ',' . $fraction : '') . ' ₽';
}
function linkTo(array $parameters = []): string { return '/' . ($parameters ? '?' . http_build_query($parameters) : ''); }
function productUrl(string $sku, string $brandSlug): string
{
    $name = $sku;
    while (str_starts_with($name, $brandSlug . '-') && strlen($name) > strlen($brandSlug) + 1) {
        $name = substr($name, strlen($brandSlug) + 1);
    }
    return '/' . rawurlencode($brandSlug) . '/' . rawurlencode($name) . '/';
}
final class CatalogRepository
{
    // A promotion is shown only while valid, positive and lower than the regular price.
    private const PRICE = "CASE WHEN promo_price > 0 AND promo_price < price AND (promo_until IS NULL OR promo_until >= NOW()) THEN promo_price ELSE price END";

    private string $volumes;

    public function __construct(private PDO $db)
    {
        $config = ['full_bottle_volumes' => [70, 75, 90, 100]];
        $volumes = $config['full_bottle_volumes'];
        if (!$volumes || array_filter($volumes, fn ($v) => !is_int($v) || $v <= 10)) {
            throw new \RuntimeException('Invalid full-bottle volume configuration');
        }
        $this->volumes = implode(',', $volumes);
    }

    private function eligible(string $alias = 'p'): string
    {
        return 'EXISTS (SELECT 1 FROM product_prices eligible WHERE eligible.product_id=' . $alias . '.id AND eligible.volume_ml IN (' . $this->volumes . '))';
    }

    public function brands(): array
    {
        return $this->db->query('SELECT b.id, b.slug, b.name, COUNT(p.id) AS product_count FROM brands b LEFT JOIN products p ON p.brand_id=b.id AND ' . $this->eligible() . ' GROUP BY b.id,b.slug,b.name,b.sort_order ORDER BY b.sort_order,b.name')->fetchAll();
    }

    public function search(string $query, string $brand, int $page): array
    {
        $where = [$this->eligible()]; $args = [];
        if ($query !== '') {
            $where[] = "(p.name LIKE ? ESCAPE '!' OR b.name LIKE ? ESCAPE '!')";
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $args[] = $term; $args[] = $term;
        }
        if ($brand !== '') { $where[] = 'b.slug=?'; $args[] = $brand; }
        $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $this->db->prepare('SELECT COUNT(*) FROM products p JOIN brands b ON b.id=p.brand_id' . $filter);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / 12));
        $page = min($page, $pages);
        $sql = 'SELECT p.*,b.name AS brand_name,b.slug AS brand_slug,prices.min_price FROM products p JOIN brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,MIN(' . self::PRICE . ') AS min_price FROM product_prices WHERE price>0 AND volume_ml IN (' . $this->volumes . ') GROUP BY product_id) prices ON prices.product_id=p.id' . $filter . ' ORDER BY b.sort_order,p.sort_order,p.id LIMIT 12 OFFSET ' . (($page - 1) * 12);
        $stmt = $this->db->prepare($sql); $stmt->execute($args);
        return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function productAtPath(string $brand, string $name): ?array
    {
        $statement = $this->db->prepare('SELECT p.sku FROM products p JOIN brands b ON b.id=p.brand_id WHERE b.slug=? AND ' . $this->eligible());
        $statement->execute([$brand]);
        $matches = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sku) {
            if (productUrl($sku, $brand) === '/' . $brand . '/' . $name . '/') { $matches[] = $sku; }
        }
        if (count($matches) > 1) { throw new RuntimeException('Duplicate canonical product URL'); }
        return $matches ? $this->product($matches[0]) : null;
    }

    public function product(string $sku): ?array
    {
        $stmt = $this->db->prepare('SELECT p.*,b.name AS brand_name,b.slug AS brand_slug FROM products p JOIN brands b ON b.id=p.brand_id WHERE p.sku=? AND ' . $this->eligible());
        $stmt->execute([$sku]);
        $product = $stmt->fetch();
        if (!$product) { return null; }
        $prices = $this->db->prepare('SELECT volume_ml,price,promo_price,promo_until,stock,' . self::PRICE . ' AS effective_price FROM product_prices WHERE product_id=? AND volume_ml IN (' . $this->volumes . ') ORDER BY volume_ml');
        $prices->execute([$product['id']]);
        $product['variants'] = $prices->fetchAll();
        return $product;
    }
}

function database(): PDO {
    $configFile = getenv('PARFUM_DB_CONFIG') ?: dirname(__DIR__) . '/private/database.php';
    $config = [];
    if (is_file($configFile)) {
        $config = require $configFile;
        if (!is_array($config)) { throw new RuntimeException('Invalid database config'); }
    }
    foreach (['host','port','name','user','password','socket'] as $key) {
        $value = getenv('DB_' . strtoupper($key));
        if ($value !== false) { $config[$key] = $value; }
    }
    if (empty($config['name']) || empty($config['user'])) { throw new RuntimeException('Missing database config'); }
    $config += ['host'=>'127.0.0.1','port'=>'3306','socket'=>'','password'=>''];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $config['name']) || !preg_match('/^[0-9]+$/D', (string) $config['port']) || str_contains($config['host'], ';') || str_contains($config['socket'], ';')) { throw new RuntimeException('Invalid database config'); }
    $endpoint = $config['socket'] !== '' ? 'unix_socket='.$config['socket'] : 'host='.$config['host'].';port='.$config['port'];
    $pdo = new PDO('mysql:'.$endpoint.';dbname='.$config['name'].';charset=utf8mb4', $config['user'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone = '+03:00'");
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    return $pdo;
}
$message=null; $product=null; $query=''; $brand=''; $page=1; $brands=[]; $prices=[]; $items=[]; $total=0; $pages=1;
try {
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) { header('Allow: GET, HEAD'); http_response_code(405); $message='Метод не поддерживается'; }
    else {
        foreach (['sku','brand','page','q'] as $key) {
            if (isset($_GET[$key]) && !is_string($_GET[$key])) { throw new InvalidArgumentException(); }
        }
        $query=trim($_GET['q'] ?? ''); $brand=$_GET['brand'] ?? ''; $rawPage=$_GET['page'] ?? '1'; $sku=$_GET['sku'] ?? '';
        if (strlen($query)>480 || preg_match('//u',$query)!==1 || strlen($brand)>60 || !preg_match('/^[a-zA-Z0-9_-]*$/D',$brand) || strlen($sku)>150 || !preg_match('/^[a-zA-Z0-9_-]*$/D',$sku) || !preg_match('/^[1-9][0-9]{0,5}$/D',$rawPage)) { throw new InvalidArgumentException(); }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $route = [];
        if (!is_string($path) || (!in_array($path, ['/', '/index.php'], true) && !preg_match('~^/([a-z0-9-]+)/([a-z0-9-]+)/?$~D', $path, $route))) {
            http_response_code(404); $message='Страница не найдена';
        }
        $repo=new CatalogRepository(database());
        foreach ($repo->brands() as $b) { $brands[$b['id']]=$b; }
        if ($message === null && ($sku!=='' || $route)) {
            $product=$route ? $repo->productAtPath($route[1],$route[2]) : $repo->product($sku);
            if ($product===null) { http_response_code(404); $message='Аромат не найден'; }
            else {
                $canonical = productUrl($product['sku'], $product['brand_slug']);
                if ($path !== $canonical || $sku !== '') {
                    http_response_code(301); header('Location: ' . $canonical); exit;
                }
                $prices[$product['id']]=$product['variants'];
            }
        } elseif ($message === null) {
            $catalog=$repo->search($query,$brand,(int)$rawPage);
            $items=$catalog['items']; $total=$catalog['total']; $page=$catalog['page']; $pages=$catalog['pages'];
        }
    }
} catch (InvalidArgumentException $error) { http_response_code(400); $message='Некорректные параметры'; }
catch (Throwable $error) { http_response_code(503); header('X-Catalog-Error: ' . ($error instanceof PDOException ? 'MYSQL-' . (int) ($error->errorInfo[1] ?? 0) : (str_contains($error->getMessage(), 'Missing database config') ? 'CONFIG-MISSING' : 'CONFIG-ERROR'))); error_log('PARFUM PLACE MySQL request failed: '.get_class($error)); $message='Каталог временно недоступен. Проверьте подключение к MySQL.'; }
$title=$product ? $brands[$product['brand_id']]['name'].' — '.$product['name'] : 'Каталог ароматов';
if ($_SERVER['REQUEST_METHOD']==='HEAD') { exit; }


function imageKey(string $value): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
}

/** Match exact SKU folders or SKU filenames; ambiguous name guesses are excluded. */
function productImages(string $sku, ?int $productId = null): array
{
    $pictDirectory = getenv('PARFUM_PICT_DIRECTORY') ?: 'pict';
    if ($productId !== null && $productId > 0 && preg_match('/^[a-zA-Z0-9_-]+$/D', $pictDirectory)) {
        $base = __DIR__ . '/' . $pictDirectory;
        $folder = $base . '/' . $productId;
        if (!is_link($base) && !is_link($folder) && is_file($folder . '/.complete')) {
            $photos = [];
            foreach (glob($folder . '/*.jpg') ?: [] as $path) {
                if (is_link($path) || !preg_match('/^[1-9][0-9]*-[a-f0-9]{16}\.jpg$/D', basename($path))) { continue; }
                $info = @getimagesize($path);
                if (($info['mime'] ?? '') === 'image/jpeg') { $photos[] = '/' . $pictDirectory . '/' . $productId . '/' . basename($path); }
            }
            natsort($photos);
            if ($photos) { return array_values($photos); }
        }
    }
    $directory = getenv('PARFUM_IMAGE_DIRECTORY') ?: 'products_pictures';
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $directory)) { return []; }
    $root = realpath(__DIR__ . '/' . $directory);
    $public = realpath(__DIR__);
    if ($root === false || !is_dir($root) || is_link(__DIR__ . '/' . $directory) || dirname($root) !== $public) { return []; }
    $found = []; $scanned = 0;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $iterator->setMaxDepth(4);
        foreach ($iterator as $file) {
            if (++$scanned > 20000) { break; }
            if (!$file->isFile() || $file->isLink()) { continue; }
            $real = $file->getRealPath();
            if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) { continue; }
            if (!in_array(strtolower($file->getExtension()), ['jpg','jpeg','png','webp','avif'], true)) { continue; }
            $relative = substr($real, strlen($root) + 1);
            $parts = preg_split('~[\\\\/]~', $relative);
            $stem = imageKey(pathinfo(array_pop($parts), PATHINFO_FILENAME));
            $key = imageKey($sku);
            $match = in_array($key, array_map('imageKey', $parts), true)
                || preg_match('/^' . preg_quote($key, '/') . '(?:-(?:[0-9]+|main|front|back|box|bottle))*$/D', $stem);
            if (!$match) { continue; }
            $info = @getimagesize($real);
            if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/webp','image/avif'], true)) { continue; }
            $url = '/' . rawurlencode($directory) . '/' . implode('/', array_map('rawurlencode', explode(DIRECTORY_SEPARATOR, $relative)));
            $found[] = $url;
        }
    } catch (UnexpectedValueException $error) { return []; }
    natsort($found);
    return array_values(array_unique($found));
}

?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title><?= e($title) ?> — PARFUM PLACE</title><?php if ($product !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e(productUrl($product['sku'],$product['brand_slug'])) ?>"><?php endif; ?></head><body>
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
<h1>Каталог ароматов</h1><h2>Бренды</h2><ul><li><a href="/">Все бренды</a></li>
<?php foreach ($brands as $b): ?><li><a href="<?= e(linkTo(['brand'=>$b['slug']])) ?>"><?= e($b['name']) ?></a></li><?php endforeach; ?></ul>
<form action="/" method="get"><label>Поиск <input name="q" value="<?= e($query) ?>" maxlength="120"></label><input type="hidden" name="brand" value="<?= e($brand) ?>"><button>Найти</button></form>
<h2>Ароматы</h2><p>Найдено: <?= $total ?>. Страница <?= $page ?> из <?= $pages ?>.</p>
<?php if (!$items): ?><p>Ароматы не найдены.</p><?php endif; ?><ul>
<?php foreach ($items as $p): ?><li><a data-product-link href="<?= e(productUrl($p['sku'],$p['brand_slug'])) ?>"><?= e($brands[$p['brand_id']]['name']) ?> — <?= e($p['name']) ?></a></li><?php endforeach; ?></ul>
<nav aria-label="Страницы каталога"><?php for($i=1;$i<=$pages;$i++): ?><a href="<?= e(linkTo(['brand'=>$brand,'q'=>$query,'page'=>$i])) ?>" <?= $page===$i?'aria-current="page"':'' ?>><?= $i ?></a> <?php endfor; ?></nav>
<?php endif; ?></body></html>
