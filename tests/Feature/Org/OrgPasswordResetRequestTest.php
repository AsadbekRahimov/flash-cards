<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Pages\OrgRequestPasswordReset;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Filament::setCurrentPanel(Filament::getPanel('org'));
});

it('is the request page of the org panel', function (): void {
    expect(Filament::getPanel('org')->getRequestPasswordResetRouteAction())->toBe(OrgRequestPasswordReset::class);
    $this->get('/org/password-reset/request')->assertOk();
});

it('answers the same "link sent" for a known and an unknown e-mail, and mails only the known one', function (): void {
    Notification::fake();
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['is_active' => true, 'email' => 'owner@example.com']);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => OrganizationRole::Owner->value]);

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'owner@example.com'])
        ->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified(__('passwords.sent'));

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'nobody@example.com'])
        ->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified(__('passwords.sent'));

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('keeps the rate limit: the third request in a row is throttled', function (): void {
    Notification::fake();

    $page = Livewire::test(OrgRequestPasswordReset::class);
    foreach (['a@example.com', 'b@example.com'] as $email) {
        $page->fillForm(['email' => $email])->call('request')->assertNotified(__('passwords.sent'));
    }

    $page->fillForm(['email' => 'c@example.com'])->call('request')
        ->assertNotNotified(__('passwords.sent'));
});
