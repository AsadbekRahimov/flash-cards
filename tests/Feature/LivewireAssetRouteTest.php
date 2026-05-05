<?php

declare(strict_types=1);

use Livewire\Mechanisms\FrontendAssets\FrontendAssets;

it('serves the hash-prefixed Livewire script route used by Filament', function (): void {
    $route = app(FrontendAssets::class)->javaScriptRoute;
    $uri = ltrim($route->uri(), '/');

    expect($uri)->toStartWith('livewire-');

    $response = $this->get('/'.$uri);

    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toContain('javascript');
});
