<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$styleNonce=base64_encode(random_bytes(16));
header("Content-Security-Policy: style-src 'nonce-".$styleNonce."'; default-src 'none'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
function e($value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money($value): string {
    if ($value === null) { return 'Цена не указана'; }
    [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '00');
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) . ($fraction !== '00' ? ',' . $fraction : '') . ' ₽';
}
function linkTo(array $parameters = []): string
{
    $brand = $parameters['brand'] ?? '';
    unset($parameters['brand']);
    if (($parameters['page'] ?? null) == 1) { unset($parameters['page']); }
    if (($parameters['q'] ?? null) === '') { unset($parameters['q']); }
    $path = $brand !== '' ? '/' . rawurlencode($brand) . '/' : '/';
    return $path . ($parameters ? '?' . http_build_query($parameters) : '');
}
function productUrl(string $sku, string $brandSlug): string
{
    $name = $sku;
    while (str_starts_with($name, $brandSlug . '-') && strlen($name) > strlen($brandSlug) + 1) {
        $name = substr($name, strlen($brandSlug) + 1);
    }
    return '/' . rawurlencode($brandSlug) . '/' . rawurlencode($name) . '.html';
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
        $sql = 'SELECT p.*,b.name AS brand_name,b.slug AS brand_slug,prices.min_price,prices.volumes FROM products p JOIN brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,MIN(' . self::PRICE . ') AS min_price,GROUP_CONCAT(volume_ml ORDER BY volume_ml) AS volumes FROM product_prices WHERE price>0 AND volume_ml IN (' . $this->volumes . ') GROUP BY product_id) prices ON prices.product_id=p.id' . $filter . ' ORDER BY b.sort_order,p.sort_order,p.id LIMIT 12 OFFSET ' . (($page - 1) * 12);
        $stmt = $this->db->prepare($sql); $stmt->execute($args);
        return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function productAtPath(string $brand, string $name): ?array
    {
        $statement = $this->db->prepare('SELECT p.sku FROM products p JOIN brands b ON b.id=p.brand_id WHERE b.slug=? AND ' . $this->eligible());
        $statement->execute([$brand]);
        $matches = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sku) {
            if (productUrl($sku, $brand) === '/' . $brand . '/' . $name . '.html') { $matches[] = $sku; }
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
$staticPages=['/about.html'=>'О нас','/delivery.html'=>'Доставка','/payment.html'=>'Оплата','/contacts.html'=>'Контакты'];
$staticPage=null;
$currentBrand=null; $message=null; $product=null; $query=''; $brand=''; $page=1; $brands=[]; $prices=[]; $items=[]; $total=0; $pages=1;
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
        if (!is_string($path) || (!in_array($path, ['/', '/index.php'], true) && !isset($staticPages[$path]) && !preg_match('~^/([a-z0-9-]+)(?:/([a-z0-9-]+)(?:\.html)?)?/?$~D', $path, $route))) {
            http_response_code(404); $message='Страница не найдена';
        }
        $staticPage=$staticPages[$path] ?? null;
        if ($staticPage === null) {
        $repo=new CatalogRepository(database());
        foreach ($repo->brands() as $b) { $brands[$b['id']]=$b; }
        if ($route && !isset($route[2])) { $brand=$route[1]; }
        if ($message === null && ($sku!=='' || isset($route[2]))) {
            $product=isset($route[2]) ? $repo->productAtPath($route[1],$route[2]) : $repo->product($sku);
            if ($product===null) { http_response_code(404); $message='Аромат не найден'; }
            else {
                $canonical = productUrl($product['sku'], $product['brand_slug']);
                if ($path !== $canonical || $sku !== '') {
                    http_response_code(301); header('Location: ' . $canonical); exit;
                }
                $prices[$product['id']]=$product['variants'];
            }
        } elseif ($message === null) {
            if ($brand !== '') {
                foreach ($brands as $candidate) { if ($candidate['slug'] === $brand) { $currentBrand=$candidate; break; } }
                if ($currentBrand === null) { http_response_code(404); $message='Бренд не найден'; }
                elseif ($path !== '/' . $brand . '/' || isset($_GET['brand'])) {
                    http_response_code(301); header('Location: ' . linkTo(['brand'=>$brand,'q'=>$query,'page'=>(int)$rawPage])); exit;
                }
            }
            $catalog=$repo->search($query,$brand,(int)$rawPage);
            $items=$catalog['items']; $total=$catalog['total']; $page=$catalog['page']; $pages=$catalog['pages'];
        }
        }
    }
} catch (InvalidArgumentException $error) { http_response_code(400); $message='Некорректные параметры'; }
catch (Throwable $error) { http_response_code(503); header('X-Catalog-Error: ' . ($error instanceof PDOException ? 'MYSQL-' . (int) ($error->errorInfo[1] ?? 0) : (str_contains($error->getMessage(), 'Missing database config') ? 'CONFIG-MISSING' : 'CONFIG-ERROR'))); error_log('PARFUM PLACE MySQL request failed: '.get_class($error)); $message='Каталог временно недоступен. Проверьте подключение к MySQL.'; }
$title=$staticPage ?? ($product ? $brands[$product['brand_id']]['name'].' — '.$product['name'] : ($currentBrand['name'] ?? 'Каталог ароматов'));
if ($_SERVER['REQUEST_METHOD']==='HEAD') { exit; }


function catalogImageSelection(): array
{
    static $selection = null;
    if ($selection === null) {
        $override = getenv('PARFUM_IMAGE_SELECTION');
        $json = $override !== false ? $override : <<<'PHOTO_SELECTION'
{"1":{"bottle":"3-02085505f6b178a8.jpg","bottle_box":"2-80840b0a910ee689.jpg"},"2":{"bottle":"3-f79d63c42baf13e0.jpg","bottle_box":"2-6d2544dc6914c36e.jpg"},"3":{"bottle":"3-a3132eb893709384.jpg","bottle_box":"2-e397358f4e3d2fbb.jpg"},"4":{"bottle":"3-f5f9d7f24e729b4f.jpg","bottle_box":"2-bc1021ddea3aca65.jpg"},"5":{"bottle":"3-d3fdcf818ff61a5e.jpg","bottle_box":"2-98122fc904e489e7.jpg"},"7":{"bottle":"3-87fd4603d4f79b27.jpg","bottle_box":"2-3a284f7a32c3a26f.jpg"},"8":{"bottle":"3-3c94307ead36d052.jpg","bottle_box":"2-d27809bce179aac6.jpg"},"9":{"bottle":"3-56f3fa70abf4dbcf.jpg","bottle_box":"2-b80ddf9156547eff.jpg"},"10":{"bottle":"3-d0e5ac5f073e4609.jpg","bottle_box":"2-01ca4e23d3a08912.jpg"},"11":{"bottle":"3-0fd1268cb60a24a7.jpg","bottle_box":"2-2435fd336e094193.jpg"},"12":{"bottle":"3-7dbdd049ee59ffa9.jpg","bottle_box":"2-5aa7abf61a9ce56a.jpg"},"13":{"bottle":"3-6952d1cf858c7660.jpg","bottle_box":"2-0461419626ccde37.jpg"},"14":{"bottle":"3-e94d65b32a2605fa.jpg","bottle_box":"2-27077b398eae33d6.jpg"},"15":{"bottle":"3-18f32f229c78102d.jpg","bottle_box":"2-9467c283a6d92529.jpg"},"16":{"bottle":"3-5a4da1d634aa73ca.jpg","bottle_box":"2-92197d1cbcaf2b0c.jpg"},"17":{"bottle":"3-fd69cf542aac89c2.jpg","bottle_box":"2-093e09a9b662a081.jpg"},"18":{"bottle":"3-199dbdc88846d318.jpg","bottle_box":"2-bc053961b72b0497.jpg"},"21":{"bottle":"3-0c38823843093e47.jpg","bottle_box":"2-5e6eb7ba93cae42b.jpg"},"25":{"bottle":"3-5cde517fe19d1f47.jpg","bottle_box":"2-47e5748beb5ebd13.jpg"},"31":{"bottle":"1-3b5a5479d6357252.jpg","bottle_box":"2-42fc95f222b68f6f.jpg"},"33":{"bottle":"3-377f7faa289c36a2.jpg","bottle_box":"2-4d46cf7cb1a96eb1.jpg"},"34":{"bottle":"3-18ceef93a1206c38.jpg","bottle_box":"2-f2ceb0cf540c4105.jpg"},"35":{"bottle":"3-b4efee821b90ed5c.jpg","bottle_box":"2-de55db5e55d92b23.jpg"},"36":{"bottle":"3-fd15e99187e237c2.jpg","bottle_box":"2-c312c81c16f62bdf.jpg"},"37":{"bottle":"3-bb09ec1dd7d4bea9.jpg","bottle_box":"2-9b4a353d6cdebbc1.jpg"},"38":{"bottle":"3-14e43d6fae761b17.jpg","bottle_box":"2-7f196c0e25b81ec6.jpg"},"39":{"bottle":"3-fa7016385252134e.jpg","bottle_box":"2-352896e51a25089a.jpg"},"40":{"bottle":"3-7375297f9925da4d.jpg","bottle_box":"2-e0fada5fbcff02cb.jpg"},"41":{"bottle":"3-3a499d9a2020f11d.jpg","bottle_box":"2-6fba7161d88cc4a1.jpg"},"42":{"bottle":"3-73f426779f1dad84.jpg","bottle_box":"2-9b50f7eb443efbff.jpg"},"43":{"bottle":"3-75e6bc42cd0e32ab.jpg","bottle_box":"2-c7c9b5d392e5eeb9.jpg"},"44":{"bottle":"3-25fc087f5bf739c1.jpg","bottle_box":"2-18a19548d216e0e8.jpg"},"45":{"bottle":"3-64a35cd03f6728c0.jpg","bottle_box":"2-42f679a9419e1200.jpg"},"46":{"bottle":"3-9402a34f09dcbde2.jpg","bottle_box":"2-c9accb712d2db9d1.jpg"},"47":{"bottle":"3-6c4d71ec00089b52.jpg","bottle_box":"2-bbbce84bcf925db6.jpg"},"48":{"bottle":"3-636a4c702a05e341.jpg","bottle_box":"2-dffe281870c552df.jpg"},"49":{"bottle":"3-2e35d0ff9204eaa3.jpg","bottle_box":"2-bdab3bda814c9a4a.jpg"},"50":{"bottle":"3-1aaebfa38fb53337.jpg","bottle_box":"2-9a2bdd7843c87a45.jpg"},"51":{"bottle":"3-da29f92d3f87f0e3.jpg","bottle_box":"2-07caa22da502387f.jpg"},"52":{"bottle":"3-03abde554410713a.jpg","bottle_box":"2-9f11dc185e204179.jpg"},"53":{"bottle":"3-66747f3d7ee5f39d.jpg","bottle_box":"2-b475e6ed23d69155.jpg"},"54":{"bottle":"3-74672e2555c3689a.jpg","bottle_box":"2-5fe9b46dbb903759.jpg"},"55":{"bottle":"3-373d159ca1489905.jpg","bottle_box":"2-d4f8bb9f5530c588.jpg"},"56":{"bottle":"3-b5ad3d322962e21d.jpg","bottle_box":"2-450a5af48a9fcea9.jpg"},"57":{"bottle":"3-b1674648c1748686.jpg","bottle_box":"2-a2ae8256a887e30d.jpg"},"58":{"bottle":"3-ade5309d06f04abd.jpg","bottle_box":"2-6ffe6de469ffc38e.jpg"},"59":{"bottle":"3-c09ff12d56fcfdb5.jpg","bottle_box":"2-35cd3b46d5e24dbf.jpg"},"60":{"bottle":"3-2a89ffac753aa466.jpg","bottle_box":null},"61":{"bottle":"3-41d83463589a4538.jpg","bottle_box":"2-b5a7850e9f6a1ab8.jpg"},"62":{"bottle":"3-6a2c9240d97f8a18.jpg","bottle_box":null},"63":{"bottle":"3-f02a9cb0b1ee7106.jpg","bottle_box":null},"64":{"bottle":"3-7ae98348f20714a1.jpg","bottle_box":"2-96b935f37985cfa8.jpg"},"65":{"bottle":"3-53825edfee931089.jpg","bottle_box":"2-e4dbbc1ade842610.jpg"},"66":{"bottle":"3-5b365ff0ba161be8.jpg","bottle_box":"2-9153358ffb185504.jpg"},"67":{"bottle":"3-3829c9ff2f7eff2a.jpg","bottle_box":"2-3522d1f54ab775df.jpg"},"68":{"bottle":"3-d0dae333380b5e95.jpg","bottle_box":"2-acccf4065e6dcaec.jpg"},"69":{"bottle":"3-90a18dd09174e24f.jpg","bottle_box":"2-b9ee75cb98e10a87.jpg"},"70":{"bottle":"3-72b3fb0da4bb6523.jpg","bottle_box":"2-28ecb5eb84fd4432.jpg"},"71":{"bottle":"3-a31773d8a2682b89.jpg","bottle_box":"2-de17fc9614768947.jpg"},"72":{"bottle":"3-9c5ca61a2cc3dcfa.jpg","bottle_box":"2-74739c8db2e99d74.jpg"},"73":{"bottle":null,"bottle_box":null},"74":{"bottle":null,"bottle_box":null},"75":{"bottle":null,"bottle_box":null}}
PHOTO_SELECTION;
        $selection = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($selection)) { throw new RuntimeException('Invalid photo selection'); }
    }
    return $selection;
}

function productImageLabel(?int $productId, string $url): string
{
    $selection = catalogImageSelection()[(string) $productId] ?? [];
    return basename($url) === ($selection['bottle_box'] ?? null) ? 'Флакон и коробка' : 'Флакон';
}

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
            $selection = catalogImageSelection()[(string) $productId] ?? [];
            foreach (['bottle', 'bottle_box'] as $role) {
                $name = $selection[$role] ?? null;
                if (!is_string($name) || !preg_match('/^[1-9][0-9]*-[a-f0-9]{16}\.jpg$/D', $name)) {
                    if ($role === 'bottle') { return []; }
                    continue;
                }
                $path = $folder . '/' . $name;
                if (!is_file($path) || is_link($path)) {
                    if ($role === 'bottle') { return []; }
                    continue;
                }
                $info = @getimagesize($path);
                if (($info['mime'] ?? '') === 'image/jpeg') {
                    $photos[] = '/' . $pictDirectory . '/' . $productId . '/' . $name;
                } elseif ($role === 'bottle') { return []; }
            }
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
    return array_slice(array_values(array_unique($found)), 0, 2);
}

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#7a3045">
<title><?= e($title) ?> — PARFUM PLACE</title>
<?php if ($product !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e(productUrl($product['sku'],$product['brand_slug'])) ?>">
<?php elseif ($currentBrand !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e(linkTo(['brand'=>$brand,'page'=>$page,'q'=>$query])) ?>">
<?php elseif ($staticPage !== null): ?><link rel="canonical" href="https://parfumplace.ru<?= e($path) ?>"><?php endif; ?>
<style nonce="<?= e($styleNonce) ?>">:root{--accent:#7a3045;--ink:#303033;--muted:#786e73;--line:#eadde2;--wash:#fcf9fa;--serif:Georgia,'Times New Roman',serif}*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:24px}body{margin:0;background:#fff;color:var(--ink);font:15px/1.65 Arial,Helvetica,sans-serif}a{color:inherit;text-decoration:none}a:focus-visible,summary:focus-visible{outline:2px solid var(--accent);outline-offset:4px}.wrap{width:min(1160px,calc(100% - 48px));margin:auto}.skip{position:absolute;top:-90px;background:white;padding:10px;z-index:10}.skip:focus{top:5px}.header{height:96px;display:grid;grid-template-columns:70px 1fr 70px;align-items:center;border-bottom:1px solid var(--line)}.wordmark{text-align:center;font:27px var(--serif);letter-spacing:2px;white-space:nowrap}.icon{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.5}.menu{position:relative;z-index:5}.menu summary{cursor:pointer;display:grid;place-items:center;width:44px;height:44px;list-style:none;color:var(--accent)}summary::-webkit-details-marker{display:none}.menu nav{position:absolute;left:0;top:48px;width:225px;padding:12px 20px;background:white;border:1px solid var(--line);box-shadow:0 8px 28px #38202b12}.menu nav a{display:block;padding:12px 0;border-bottom:1px solid var(--line)}.menu nav a:last-child{border:0}.header-contact{justify-self:end;color:var(--accent);font:13px var(--serif);padding:12px 0}.hero{text-align:center;padding:44px 0 30px}.eyebrow{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--accent);margin:0 0 12px}.hero h1{font:42px/1.2 var(--serif);font-weight:400;margin:0}.hero p:last-child{font:14px var(--serif);color:var(--muted);margin:16px 0 0}.brand-tabs{display:flex;gap:30px;overflow-x:auto;scrollbar-width:thin;border-bottom:1px solid var(--line);padding:0 0 1px;margin:0 0 24px}.brand-tabs a{flex:none;font:15px var(--serif);padding:13px 2px;border-bottom:2px solid transparent;min-height:44px}.brand-tabs [aria-current]{color:var(--accent);border-bottom-color:var(--accent)}.catalog-title{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}.catalog-title h2{font:24px var(--serif);font-weight:400;margin:0}.muted{color:var(--muted);font-size:12px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.card{display:flex;min-width:0;border:1px solid #caa7b2;border-radius:2px;padding:12px;flex-direction:column;text-align:center;background:white}.card:hover{border-color:var(--accent)}.card-photo{height:265px;display:grid;place-items:center;margin-bottom:14px;overflow:hidden}.card-photo img{width:100%;height:100%;object-fit:contain}.no-photo{font:13px var(--serif);color:var(--muted)}.card-brand{font-weight:bold;font-size:13px;margin:0 0 1px}.card-name{font:17px/1.4 var(--serif);margin:0;min-height:48px}.card-volume{font-size:12px;color:var(--muted);margin:7px 0 3px}.card-price{font:19px var(--serif);margin:0 0 14px}.outline-button{display:block;border:1px solid var(--accent);color:var(--accent);min-height:44px;padding:10px 8px;font:14px/22px var(--serif);margin-top:auto}.card:hover .outline-button{background:var(--accent);color:white}.pagination{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;padding:28px 0 6px}.pagination a{min-width:44px;min-height:44px;display:grid;place-items:center;border:1px solid var(--line)}.pagination [aria-current]{border-color:var(--accent);background:var(--accent);color:white}.page-caption{text-align:center;color:var(--muted);font-size:12px;margin:8px 0 32px}.footer{border-top:1px solid var(--line);margin-top:36px;padding:26px 0 60px;text-align:center}.footer nav{display:flex;justify-content:center;gap:26px;flex-wrap:wrap;font:14px var(--serif)}.footer nav a{padding:9px 0}.footer small{display:block;margin-top:20px;color:var(--muted);font-size:10px;letter-spacing:1px}.manager{position:fixed;right:20px;bottom:max(20px,env(safe-area-inset-bottom));width:52px;height:52px;border-radius:50%;background:var(--accent);color:white;display:grid;place-items:center;box-shadow:0 3px 13px #7a30452b;z-index:4}.manager .icon{width:27px;height:27px}.breadcrumb{display:inline-block;margin:25px 0;color:var(--accent);font-size:12px;min-height:32px}.detail{display:grid;grid-template-columns:1.1fr 1fr;gap:48px;margin:10px 0 46px}.gallery{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;align-self:start;border:1px solid var(--line);scrollbar-width:thin}.gallery figure{flex:0 0 100%;min-width:0;margin:0;padding:20px;scroll-snap-align:start}.gallery img{width:100%;height:450px;object-fit:contain;display:block}.gallery figcaption{text-align:center;color:var(--muted);font-size:11px;padding-top:12px}.detail h1{font:38px/1.2 var(--serif);font-weight:400;margin:6px 0 18px}.detail .eyebrow{margin-bottom:7px}.facts{display:flex;gap:7px 20px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:20px;margin-bottom:24px}.facts div{font-size:12px}.facts dt{color:var(--muted);display:inline}.facts dt:after{content:': '}.facts dd{display:inline;margin:0}.variants{width:100%;border-collapse:collapse;font-size:14px;margin:12px 0}.variants th{text-align:left;font-weight:400;color:var(--muted);font-size:12px}.variants th,.variants td{padding:13px 0;border-bottom:1px solid var(--line)}.variants th:last-child,.variants td:last-child{text-align:right}.variants td:last-child{font:24px var(--serif);color:var(--accent)}.description{line-height:1.85;font-size:14px}.detail h2{font:24px var(--serif);font-weight:400;margin:28px 0 12px}.notes div{margin-bottom:16px}.notes dt{font-size:11px;letter-spacing:.5px;color:var(--muted)}.notes dd{margin:3px 0 0;font-size:14px}.info,.error{max-width:650px;margin:55px auto 100px;min-height:40vh}.info h1,.error h1{font:38px var(--serif);font-weight:400}.empty{padding:50px 20px;background:var(--wash);text-align:center}.empty a{text-decoration:underline;color:var(--accent)}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:900px){.grid{grid-template-columns:repeat(3,minmax(0,1fr))}.card-photo{height:240px}.detail{gap:28px}.gallery img{height:350px}}@media(max-width:600px){.wrap{width:calc(100% - 28px)}.header{height:76px;grid-template-columns:54px 1fr 54px}.wordmark{font-size:20px;letter-spacing:1px}.header-contact{font-size:11px}.hero{padding:28px 0 22px}.hero h1{font-size:30px;line-height:1.2}.hero p:last-child{font-size:12px;margin-top:12px}.brand-tabs{gap:25px;margin-bottom:20px}.brand-tabs a{font-size:14px}.catalog-title h2{font-size:20px}.catalog-title .muted{font-size:11px}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.card{padding:9px 8px}.card-photo{height:200px;margin-bottom:10px}.card-brand{font-size:12px}.card-name{font-size:15px;min-height:44px}.card-price{font-size:18px;margin-bottom:10px}.card-volume{font-size:11px}.outline-button{font-size:13px}.footer nav{gap:8px 22px;font-size:13px}.footer{padding-bottom:90px}.manager{right:16px;bottom:20px}.detail{grid-template-columns:1fr;gap:26px;margin-top:0}.gallery img{height:330px}.gallery figure{padding:16px}.detail h1{font-size:32px}.breadcrumb{margin:18px 0}.facts{margin-bottom:15px}.detail h2{font-size:23px}.info{margin-top:35px}}@media(max-width:360px){.card-photo{height:165px}.card-name{font-size:14px}.wordmark{font-size:18px}.hero h1{font-size:27px}}@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
</style>
</head>
<body>
<a class="skip" href="#main">Перейти к содержимому</a>
<header class="header wrap">
<details class="menu"><summary aria-label="Открыть меню"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></summary>
<nav aria-label="Основное меню"><a href="/">Коллекция</a><?php foreach ($staticPages as $url=>$label): ?><a href="<?= e($url) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></details>
<a class="wordmark" href="/" aria-label="PARFUM PLACE — главная">PARFUM PLACE</a>
<a class="header-contact" href="/contacts.html">Контакты</a>
</header>
<main id="main" class="wrap">
<?php if ($message !== null): ?>
<section class="error"><p class="eyebrow">PARFUM PLACE</p><h1><?= e($message) ?></h1><a class="outline-button" href="/">Вернуться в коллекцию</a></section>
<?php elseif ($staticPage !== null): ?>
<section class="info"><a class="breadcrumb" href="/">← В коллекцию</a><h1><?= e($staticPage) ?></h1><p class="muted">Информация скоро появится.</p></section>
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
<section class="hero"><p class="eyebrow">Коллекция PARFUM PLACE</p>
<?php if ($currentBrand !== null): ?><h1><?= e($currentBrand['name']) ?></h1><p>Найдите свой аромат в коллекции бренда</p>
<?php else: ?><h1>Ароматы,<br>которые говорят о вас</h1><p>Парфюмерия мировых брендов</p><?php endif; ?></section>
<nav class="brand-tabs" aria-label="Бренды"><a href="/" <?= $brand==='' ? 'aria-current="page"' : '' ?>>Все бренды</a><?php foreach($brands as $b): ?><a data-brand-link href="<?= e(linkTo(['brand'=>$b['slug']])) ?>" <?= $brand===$b['slug'] ? 'aria-current="page"' : '' ?>><?= e($b['name']) ?></a><?php endforeach; ?></nav>
<section aria-labelledby="collection-title"><div class="catalog-title"><h2 id="collection-title">Коллекция ароматов</h2><span class="muted"><?= (int)$total ?> ароматов</span></div>
<?php if (!$items): ?><div class="empty"><p>Ароматы не найдены.</p><a href="/">Вся коллекция</a></div><?php endif; ?>
<div class="grid">
<?php foreach($items as $cardIndex=>$p): $photos=productImages($p['sku'],(int)$p['id']); ?>
<a class="card" data-product-link href="<?= e(productUrl($p['sku'],$p['brand_slug'])) ?>">
<div class="card-photo"><?php if($photos): ?><img src="<?= e($photos[0]) ?>" alt="<?= e($brands[$p['brand_id']]['name'].' '.$p['name']) ?>" width="320" height="320" loading="<?= $cardIndex<2 ? 'eager' : 'lazy' ?>"><?php else: ?><span class="no-photo">Фото скоро появится</span><?php endif; ?></div>
<p class="card-brand"><?= e($brands[$p['brand_id']]['name']) ?></p><h3 class="card-name"><?= e($p['name']) ?></h3>
<p class="card-volume"><?= e(str_replace(',',', ',$p['volumes'] ?? '')) ?> мл</p><p class="card-price"><?= e(money($p['min_price'])) ?></p><span class="outline-button">Подробнее</span>
</a><?php endforeach; ?>
</div>
<nav class="pagination" aria-label="Страницы каталога"><?php for($i=1;$i<=$pages;$i++): ?><a href="<?= e(linkTo(['brand'=>$brand,'q'=>$query,'page'=>$i])) ?>" <?= $page===$i?'aria-current="page"':'' ?> aria-label="Страница <?= $i ?>"><?= $i ?></a><?php endfor; ?></nav>
<p class="page-caption">Найдено: <?= (int)$total ?>. Страница <?= (int)$page ?> из <?= (int)$pages ?>.</p>
</section>
<?php endif; ?>
</main>
<footer class="footer wrap"><nav aria-label="Информация"><?php foreach($staticPages as $url=>$label): ?><a href="<?= e($url) ?>"><?= e($label) ?></a><?php endforeach; ?></nav><small>PARFUM PLACE · <?= date('Y') ?></small></footer>
<a class="manager" href="/contacts.html" aria-label="Связаться с менеджером"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a8 8 0 0 1-8 8 9 9 0 0 1-3.5-.7L4 20l1.2-4.5a8 8 0 1 1 14.8-4z"/><path d="M8 11h.01M12 11h.01M16 11h.01" stroke-linecap="round" stroke-width="2"/></svg></a>
</body></html>
