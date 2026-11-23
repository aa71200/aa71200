<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];
if ($path && (is_file($root . $path) || (is_dir($root . $path) && is_file($root . $path . '/index.php')))) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';
