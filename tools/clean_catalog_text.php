<?php
declare(strict_types=1);

function plainCatalogText(string $value): string
{
    for ($i = 0; $i < 8; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) { break; }
        $value = $decoded;
    }
    $value = preg_replace('~<!--.*?-->~s', ' ', $value);
    $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', ' ', $value);
    $value = preg_replace('~</?[a-zA-Z][^>]*>~u', ' ', $value);
    $value = strip_tags($value);
    $value = preg_replace('/[\p{Z}\s\x{200B}\x{FEFF}]+/u', ' ', $value);
    if ($value === null) { throw new RuntimeException('Invalid UTF-8 catalog value'); }
    return trim($value);
}

function cleanCatalogText(PDO $db, string $backupDirectory): array
{
    // Identifiers, SKU, URLs, prices, stock and IDs are deliberately excluded.
    $fields = [
        'brands' => ['name','short_note','description','meta_title','meta_description'],
        'products' => ['name','gender','fragrance_group','top_notes','heart_notes','base_notes','release_year','description','meta_title','meta_description'],
    ];
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true)) { throw new RuntimeException('Cannot create backup directory'); }
    $backupDirectory = realpath($backupDirectory);
    if ($backupDirectory === false) { throw new RuntimeException('Invalid backup directory'); }
    $backup = []; $updatedRows = 0; $updatedFields = 0;
    $db->beginTransaction();
    try {
        foreach ($fields as $table => $columns) {
            $backup[$table] = $db->query('SELECT `id`,`' . implode('`,`', $columns) . '` FROM `' . $table . '` ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        }
        $backupFile = $backupDirectory . '/catalog-text-before-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json';
        $encoded = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $handle = fopen($backupFile, 'x');
        if ($handle === false) { throw new RuntimeException('Cannot save backup'); }
        try {
            chmod($backupFile, 0600);
            if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) { throw new RuntimeException('Incomplete backup'); }
            if (function_exists('fsync') && !fsync($handle)) { throw new RuntimeException('Cannot sync backup'); }
        } finally { fclose($handle); }
        foreach ($fields as $table => $columns) {
            foreach ($backup[$table] as $row) {
                $changes = [];
                foreach ($columns as $column) {
                    if ($row[$column] === null) { continue; }
                    $text = plainCatalogText($row[$column]);
                    if ($text !== $row[$column]) { $changes[$column] = $text; }
                }
                if (!$changes) { continue; }
                $sets = array_map(static fn($column) => '`' . $column . '`=?', array_keys($changes));
                $statement = $db->prepare('UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE id=?');
                $statement->execute([...array_values($changes), $row['id']]);
                $updatedRows++; $updatedFields += count($changes);
            }
            // Verify the stored values before committing; rollback on any discrepancy.
            $rows = $db->query('SELECT `' . implode('`,`', $columns) . '` FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                foreach ($row as $value) {
                    if ($value !== null && plainCatalogText($value) !== $value) { throw new RuntimeException('Post-update text verification failed'); }
                }
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
    return ['updated_rows'=>$updatedRows, 'updated_fields'=>$updatedFields, 'backup_saved'=>true, 'verified'=>true];
}
