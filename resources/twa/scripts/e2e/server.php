<?php

declare(strict_types=1);

/**
 * Router of the e2e stand for PHP's built-in server (docs/16 §14.4):
 *
 *   php -S 127.0.0.1:8010 -t public resources/twa/scripts/e2e/server.php
 *
 * `php artisan serve` serves the files of `public/` as they are and sends the rest to `index.php`, but it has no `try_files` of
 * docker/nginx/default.conf: after a reload `/twa/battles/15` would be a 404 of Laravel. Here a path under `/twa/` that is not a file
 * falls back to `/twa/index.html`, as nginx does; the API (`/api/...`) and the static files are untouched. (`artisan serve` also
 * drops every variable of the environment except a short list when a `.env` exists, so the stand would silently read `.env`.)
 */
$root = (string) $_SERVER['DOCUMENT_ROOT'];
$uri = urldecode(parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// A file of public/ (the built TWA, its assets): the built-in server sends it itself.
if ($uri !== '/' && is_file($root.$uri)) {
    return false;
}

if (str_starts_with($uri, '/twa/')) {
    if (str_starts_with($uri, '/twa/assets/')) {
        http_response_code(404);

        return true;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($root.'/twa/index.html');

    return true;
}

require $root.'/index.php';

return true;
