<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\BotMenuBuilder;
use App\Mail\OrgStaffInvitationMail;
use App\Models\PaymentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

/*
 * The product name people see comes from config (docs/36 §5б): the bot menu button, the panels, the public site, the
 * payment return page, the letters and the 2FA pages read `app.brand` / `app.brand_pro`. The texts of lang/ and of the
 * Mini App carry the word themselves, so a guard keeps the old name out of them.
 */

uses(RefreshDatabase::class);

const OLD_BRAND_PATTERN = '/lexiflow/i';

// --- the guard: no old name in the texts ---------------------------------------------------------------------------

it('has no old brand name in any lang file', function (): void {
    $offenders = [];

    foreach (glob(lang_path('*/*.php')) ?: [] as $file) {
        foreach (Arr::dot(require $file) as $key => $text) {
            if (is_string($text) && preg_match(OLD_BRAND_PATTERN, $text) === 1) {
                $offenders[] = basename(dirname($file)).'/'.basename($file, '.php').'.'.$key;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has no old brand name in the Mini App dictionaries', function (string $file): void {
    $source = (string) file_get_contents(base_path("resources/twa/src/i18n/{$file}"));

    expect(preg_match(OLD_BRAND_PATTERN, $source))->toBe(0);
})->with(['ru.ts', 'uz.ts']);

it('names the product in the lang files exactly as config does', function (string $locale): void {
    // The share card takes the brand from config itself (docs/36 §2б): no brand line of its own in lang.
    expect(trans()->has('achievements.card.brand', $locale, false))->toBeFalse()
        ->and(__('achievements.items.first_friend.description', [], $locale))->toContain((string) config('app.brand'))
        ->and(__('billing.payme.receipt_item', ['period' => 'x'], $locale))->toStartWith(config('app.brand_pro').' — ');
})->with(['ru', 'uz']);

// --- the bot menu button -------------------------------------------------------------------------------------------

it('captions the bot menu button with the brand from config', function (): void {
    config(['twa.base_url' => 'https://twa.test', 'app.brand' => 'Brandy']);

    expect(app(BotMenuBuilder::class)->menuButton())
        ->toBe(['type' => 'web_app', 'text' => 'Brandy', 'web_app' => ['url' => 'https://twa.test/twa/']]);
});

// --- the public site and the payment return page -------------------------------------------------------------------

it('shows the brand from config on the landing and the legal pages', function (string $url): void {
    config(['app.brand' => 'Brandy', 'telegram.bot_username' => 'brand_test_bot']);

    $html = (string) $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:site_name" content="Brandy">')
        ->and($html)->toMatch('#<a class="brand" href="[^"]*">Brandy</a>#')
        ->and(preg_match(OLD_BRAND_PATTERN, $html))->toBe(0);
})->with(['/', '/uz', '/offer', '/uz/privacy']);

it('puts the brand into the <title> of the legal pages', function (): void {
    config(['app.brand' => 'Brandy']);

    $this->get('/offer')->assertOk()->assertSee('— Brandy</title>', false);
});

it('shows the brand from config on the payment return page', function (): void {
    config(['app.brand' => 'Brandy', 'telegram.bot_username' => 'brand_test_bot']);
    $order = PaymentOrder::factory()->payme()->paid()->create();

    $html = (string) $this->get('/billing/return?order='.$order->token.'&lang=ru')->assertOk()->getContent();

    expect($html)->toContain('— Brandy</title>')
        ->and($html)->toContain('<span class="brand">Brandy</span>')
        ->and(preg_match(OLD_BRAND_PATTERN, $html))->toBe(0);
});

// --- the panels, the 2FA pages, the letters ------------------------------------------------------------------------

it('names both panels after the paid plan from config', function (string $panel, string $login): void {
    expect(Filament::getPanel($panel)->getBrandName())->toBe(config('app.brand_pro'));

    $this->get($login)->assertOk()->assertSee('Muhrla Pro')->assertDontSee('LexiFlow');
})->with([
    ['admin', '/admin/login'],
    ['org', '/org/login'],
]);

it('shows the brand on the 2FA pages', function (): void {
    config(['app.brand' => 'Brandy']);
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    $this->actingAs($admin)->get(route('2fa.setup'))->assertOk()->assertSee('— Brandy</title>', false);
});

it('signs the letters and their layout with the brand, not the technical APP_NAME', function (): void {
    config(['app.brand' => 'Brandy', 'app.name' => 'TechName']);

    $mail = new OrgStaffInvitationMail('Center', 'Owner', 'https://example.test/x', false, 24);
    $html = $mail->render();

    expect($html)->toContain('Brandy')
        ->and($html)->not->toContain('TechName');

    $mail->assertSeeInText('Brandy');
    $mail->assertDontSeeInText('TechName');
});

it('signs the password-reset letter of the panels with the brand', function (): void {
    config(['app.brand' => 'Brandy', 'app.name' => 'TechName']);
    $notification = new ResetPassword('token');
    $notification->url = 'https://example.test/reset';

    $html = (string) $notification->toMail(User::factory()->make())->render();

    expect($html)->toContain('Brandy')
        ->and($html)->not->toContain('TechName');
});
