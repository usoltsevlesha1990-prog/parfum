<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__,2).'/review-site');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=is_string($path) ? realpath($root.$path) : false;
if($file!==false && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file)) { return false; }
require $root.'/index.php';
