<?php
declare(strict_types=1);

/** One-off, exact-decimal 4% reduction; durable marker prevents double application. */
function reduceCatalogPrices(PDO $db, string $private): array
{
    if (!is_dir($private) && !mkdir($private, 0700, true)) { throw new RuntimeException('Cannot create private directory'); }
    $marker = $private . '/price-reduction-20261101-4pct.json';
    if (is_file($marker)) {
        $previous = json_decode(file_get_contents($marker), true);
        if (($previous['status'] ?? '') === 'done') { return $previous['result'] + ['already_applied'=>true]; }
        throw new RuntimeException('Previous price migration needs manual verification; repeat refused');
    }
    $lock = fopen($marker, 'x');
    if ($lock === false) { throw new RuntimeException('Price migration already started'); }
    chmod($marker, 0600);
    try {
        $pending = json_encode(['status'=>'started','date'=>gmdate('c')], JSON_THROW_ON_ERROR);
        if (fwrite($lock, $pending) !== strlen($pending) || !fflush($lock) || (function_exists('fsync') && !fsync($lock))) { throw new RuntimeException('Cannot persist migration marker'); }
        $db->beginTransaction();
        $before = $db->query('SELECT *,ROUND(price*0.96,2) AS expected_price,ROUND(promo_price*0.96,2) AS expected_promo_price FROM product_prices ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        if (!$before) { throw new RuntimeException('No catalog prices found'); }
        $backupDirectory = $private . '/backups';
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true)) { throw new RuntimeException('Cannot create backup directory'); }
        $file = $backupDirectory . '/prices-before-4pct-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json';
        $data = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $backup = fopen($file, 'x');
        if ($backup === false) { throw new RuntimeException('Cannot create price backup'); }
        try {
            chmod($file, 0600);
            if (fwrite($backup, $data) !== strlen($data) || !fflush($backup) || (function_exists('fsync') && !fsync($backup))) { throw new RuntimeException('Cannot persist price backup'); }
        } finally { fclose($backup); }
        // Decimal literal and DECIMAL columns avoid binary float rounding errors.
        $db->exec('UPDATE product_prices SET price=ROUND(price*0.96,2),promo_price=ROUND(promo_price*0.96,2)');
        $after = $db->query('SELECT * FROM product_prices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (count($before) !== count($after)) { throw new RuntimeException('Price row count changed'); }
        $regular = 0; $promo = 0;
        foreach ($before as $index=>$old) {
            $new = $after[$index];
            if ($new['price'] !== $old['expected_price'] || $new['promo_price'] !== $old['expected_promo_price']) { throw new RuntimeException('Price calculation verification failed'); }
            foreach ($new as $column=>$value) {
                if (!in_array($column,['price','promo_price'],true) && $value !== $old[$column]) { throw new RuntimeException('Non-price field changed'); }
            }
            $regular += (int) ($old['price'] !== $new['price']);
            $promo += (int) ($old['promo_price'] !== $new['promo_price']);
        }
        $result = ['verified'=>true,'backup_saved'=>true,'variants'=>count($before),'regular_prices_changed'=>$regular,'promo_prices_changed'=>$promo];
        $db->commit();
        $receipt = json_encode(['status'=>'done','result'=>$result], JSON_THROW_ON_ERROR);
        rewind($lock);
        if (!ftruncate($lock,0) || fwrite($lock,$receipt)!==strlen($receipt) || !fflush($lock) || (function_exists('fsync') && !fsync($lock))) { throw new RuntimeException('Prices committed but marker incomplete; manual verification required'); }
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        // Keep the marker on any uncertain outcome. Never reduce prices twice automatically.
        throw $error;
    } finally { fclose($lock); }
}
