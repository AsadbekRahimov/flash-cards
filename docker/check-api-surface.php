<?php

declare(strict_types=1);

/*
 * Deploy check of the api surface (deploy.sh, docs/DEVOPS_GUIDE.md §0 «Две поверхности приложения»): reads the JSON of
 * `php artisan route:list --json` from STDIN and exits 1 when the route list is not the one of APP_SURFACE=api - no TWA
 * route (the surface variable did not reach PHP) or a panel route (the container runs the full application). Plain PHP
 * without the framework: `json_encode` escapes slashes in that output ("api\/twa\/me"), so a shell pattern on the raw text
 * is not reliable.
 *
 *   docker compose exec -T app-api sh -c 'php artisan route:list --json | php docker/check-api-surface.php'
 */

$input = stream_get_contents(STDIN);
$routes = json_decode($input === false ? '' : $input, true);

if (! is_array($routes)) {
    fwrite(STDERR, "check-api-surface: route:list --json gave no JSON array\n");
    exit(1);
}

$hasTwaMe = false;
$panelRoutes = [];

foreach ($routes as $route) {
    if (! is_array($route)) {
        continue;
    }
    $uri = (string) ($route['uri'] ?? '');
    $name = (string) ($route['name'] ?? '');

    if ($uri === 'api/twa/me') {
        $hasTwaMe = true;
    }
    if (str_starts_with($name, 'filament.') || $uri === 'admin' || str_starts_with($uri, 'admin/') || $uri === 'org' || str_starts_with($uri, 'org/') || str_starts_with($uri, 'livewire')) {
        $panelRoutes[] = $uri;
    }
}

if (! $hasTwaMe) {
    fwrite(STDERR, sprintf("check-api-surface: app-api has no route api/twa/me (%d routes listed) - is APP_SURFACE reaching the process?\n", count($routes)));
    exit(1);
}

if ($panelRoutes !== []) {
    fwrite(STDERR, sprintf("check-api-surface: app-api serves panel routes (%s, ...) - APP_SURFACE of the app-api service is not 'api'\n", implode(', ', array_slice($panelRoutes, 0, 5))));
    exit(1);
}

echo sprintf("app-api: api surface confirmed (%d routes, api/twa/me present, no panel routes)\n", count($routes));
