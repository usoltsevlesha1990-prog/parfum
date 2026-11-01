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
    $path = $brand !== '' ? '/' . rawurlencode($brand) . '/' : '/catalog.html';
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
function homepageProductIds(): array { return __FEATURED_IDS__; }
function aromaCount(int $count): string
{
    $tail=$count%100;
    $word=($tail>=11 && $tail<=14) ? 'ароматов' : ($count%10===1 ? 'аромат' : (in_array($count%10,[2,3,4],true) ? 'аромата' : 'ароматов'));
    return $count.' '.$word;
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

    private function cardsQuery(): string
    {
        return 'SELECT p.*,b.name AS brand_name,b.slug AS brand_slug,prices.min_price,prices.volumes FROM products p JOIN brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,MIN(' . self::PRICE . ') AS min_price,GROUP_CONCAT(volume_ml ORDER BY volume_ml) AS volumes FROM product_prices WHERE price>0 AND volume_ml IN (' . $this->volumes . ') GROUP BY product_id) prices ON prices.product_id=p.id';
    }

    public function featured(array $ids): array
    {
        if (!$ids || count($ids)>20 || array_filter($ids,static fn($id)=>!is_int($id)||$id<=0)) { throw new RuntimeException('Invalid homepage product IDs'); }
        $statement=$this->db->prepare($this->cardsQuery().' WHERE '.$this->eligible().' AND p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $statement->execute($ids);
        $rows=[];
        foreach ($statement->fetchAll() as $row) { $rows[(int)$row['id']]=$row; }
        $ordered=[];
        foreach ($ids as $id) { if (isset($rows[$id])) { $ordered[]=$rows[$id]; } }
        return $ordered;
    }

    public function countProducts(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM products p JOIN brands b ON b.id=p.brand_id WHERE '.$this->eligible())->fetchColumn();
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
        $sql = $this->cardsQuery() . $filter . ' ORDER BY b.sort_order,p.sort_order,p.id LIMIT 12 OFFSET ' . (($page - 1) * 12);
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
$staticPage=null; $isHome=false;
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
        if (!is_string($path) || (!in_array($path, ['/', '/index.php', '/catalog.html'], true) && !isset($staticPages[$path]) && !preg_match('~^/([a-z0-9-]+)(?:/([a-z0-9-]+)(?:\.html)?)?/?$~D', $path, $route))) {
            http_response_code(404); $message='Страница не найдена';
        }
        $isHome=in_array($path,['/','/index.php'],true) && $brand==='' && $sku==='' && !isset($_GET['page']) && $query==='';
        if (in_array($path,['/','/index.php'],true) && $brand==='' && $sku==='' && !$isHome) {
            http_response_code(301); header('Location: '.linkTo(['page'=>(int)$rawPage,'q'=>$query])); exit;
        }
        if ($isHome && $path==='/index.php') { http_response_code(301); header('Location: /'); exit; }
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
            if ($isHome) {
                $items=$repo->featured(homepageProductIds()); $total=$repo->countProducts();
            } else {
                $catalog=$repo->search($query,$brand,(int)$rawPage);
                $items=$catalog['items']; $total=$catalog['total']; $page=$catalog['page']; $pages=$catalog['pages'];
            }
        }
        }
    }
} catch (InvalidArgumentException $error) { http_response_code(400); $message='Некорректные параметры'; }
catch (Throwable $error) { http_response_code(503); header('X-Catalog-Error: ' . ($error instanceof PDOException ? 'MYSQL-' . (int) ($error->errorInfo[1] ?? 0) : (str_contains($error->getMessage(), 'Missing database config') ? 'CONFIG-MISSING' : 'CONFIG-ERROR'))); error_log('PARFUM PLACE MySQL request failed: '.get_class($error)); $message='Каталог временно недоступен. Проверьте подключение к MySQL.'; }
$title=$staticPage ?? ($product ? $brands[$product['brand_id']]['name'].' — '.$product['name'] : ($currentBrand['name'] ?? ($isHome ? 'Парфюмерия мировых брендов' : 'Каталог ароматов')));
if ($_SERVER['REQUEST_METHOD']==='HEAD') { exit; }
?>
