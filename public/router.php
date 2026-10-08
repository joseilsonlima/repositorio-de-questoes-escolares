<?php
// Servidor local: php -S 127.0.0.1:8080 -t public public/router.php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (is_string($path) && str_starts_with($path,'/assets/') && is_file(__DIR__.$path)) return false;
require __DIR__.'/index.php';
