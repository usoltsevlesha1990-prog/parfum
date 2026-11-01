<?php
declare(strict_types=1);
namespace ParfumPlace\Infrastructure;

use PDO;

final class CatalogRepository
{
    // A promotion is shown only while valid, positive and lower than the regular price.
    private const PRICE = "CASE WHEN promo_price > 0 AND promo_price < price AND (promo_until IS NULL OR promo_until >= NOW()) THEN promo_price ELSE price END";

    private string $volumes;

    public function __construct(private PDO $db)
    {
        $config = require dirname(__DIR__, 2) . '/config/catalog.php';
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
        $sql = 'SELECT p.*,b.name AS brand_name,prices.min_price FROM products p JOIN brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,MIN(' . self::PRICE . ') AS min_price FROM product_prices WHERE price>0 AND volume_ml IN (' . $this->volumes . ') GROUP BY product_id) prices ON prices.product_id=p.id' . $filter . ' ORDER BY b.sort_order,p.sort_order,p.id LIMIT 12 OFFSET ' . (($page - 1) * 12);
        $stmt = $this->db->prepare($sql); $stmt->execute($args);
        return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
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
