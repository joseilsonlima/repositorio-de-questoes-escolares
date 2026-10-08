<?php
declare(strict_types=1);

foreach (is_file(dirname(__DIR__) . '/.env') ? file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES) : [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$name, $value] = explode('=', $line, 2);
    if (getenv($name) === false) putenv($name . '=' . trim($value, " \t\n\r\0\x0B\"'"));
}
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'School\\')) {
        $path = __DIR__ . '/' . substr($class, 7) . '.php';
        if (is_file($path)) require $path;
    }
});
date_default_timezone_set('UTC');
