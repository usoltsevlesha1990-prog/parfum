<?php
declare(strict_types=1);

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
