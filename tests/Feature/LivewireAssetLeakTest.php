<?php

declare(strict_types=1);

use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;

/*
 * Livewire adds its <script> to every full HTML response once "a component was rendered this request". That flag
 * is a static, so in a long-lived test process a Filament page rendered by one test used to leak into the tests
 * after it and put a Livewire script into the script-free public pages (PublicSiteTest failed or passed depending
 * on which tests shared its worker). Tests\TestCase now flushes Livewire's state before every test.
 *
 * The two tests below run one after the other in one process: the first plays the Filament test that leaves
 * the flag behind, the second must not see it.
 */

it('may leave the "a component was rendered" flag set, like a test that renders a Filament page', function (): void {
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;

    expect(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest)->toBeTrue();
});

it('starts the next test with a clean flag, so the public pages carry no injected script', function (): void {
    expect(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest)->toBeFalse();

    $this->get('/ru')
        ->assertOk()
        ->assertDontSee('<script', false)
        ->assertDontSee('livewire');
});
