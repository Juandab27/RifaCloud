<?php

// Router for the PHP built-in server used by the local integration test.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file(__DIR__.$path)) {
    return false;
}

require __DIR__.'/index.php';
