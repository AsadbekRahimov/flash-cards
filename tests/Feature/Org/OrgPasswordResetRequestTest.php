<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Pages\OrgRequestPasswordReset;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Timebox;
use Livewire\Livewire;
use Tests\Support\RecordingTimebox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    // Both timeboxes (the broker's and the page's) sleep through `Sleep`; do not wait for them here.
    Sleep::fake();
    Filament::setCurrentPanel(Filament::getPanel('org'));
});

function createOrgOwner(string $email): User
{
    $user = User::factory()->create(['is_active' => true, 'email' => $email]);
    OrganizationUser::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner->value,
    ]);

    return $user;
}

it('is the request page of the org panel', function (): void {
    expect(Filament::getPanel('org')->getRequestPasswordResetRouteAction())->toBe(OrgRequestPasswordReset::class);
    $this->get('/org/password-reset/request')->assertOk();
});

it('answers the same "link sent" for a known and an unknown e-mail, and mails only the known one', function (): void {
    Notification::fake();
    $user = createOrgOwner('owner@example.com');

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

it('clears the form on every outcome: known, unknown and throttled-by-broker', function (): void {
    Notification::fake();
    createOrgOwner('owner@example.com');

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'owner@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'))->assertSet('data.email', null);

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'nobody@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'))->assertSet('data.email', null);

    // The broker's own throttle (a token was just issued) for a known address; the page limiter is reset.
    Cache::flush();
    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'owner@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'))->assertSet('data.email', null);

    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('treats an /admin-only user like any other: no mail, form cleared, same notification', function (): void {
    Notification::fake();
    $user = User::factory()->create(['is_active' => true, 'email' => 'admin-only@example.com']);

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'admin-only@example.com'])->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified(__('passwords.sent'))
        ->assertSet('data.email', null);

    Notification::assertNothingSentTo($user);
});

it('boxes every outcome to the same configured time: known, unknown and throttled-by-broker', function (): void {
    Notification::fake();
    config(['auth.password_reset_timebox_duration' => 1_000_000]);
    app()->instance(Timebox::class, $timebox = new RecordingTimebox);
    createOrgOwner('owner@example.com');

    foreach (['owner@example.com', 'nobody@example.com', 'owner@example.com'] as $email) {
        Cache::flush();
        Livewire::test(OrgRequestPasswordReset::class)
            ->fillForm(['email' => $email])->call('request')
            ->assertNotified(__('passwords.sent'));
    }

    // Three outcomes (sent, unknown, broker-throttled), one box each, all of the page's own budget;
    // the broker's 200 ms box is nested inside and is not what bounds the response.
    expect($timebox->durations)->toBe([1_000_000, 1_000_000, 1_000_000]);
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('reads the time budget from auth.password_reset_timebox_duration', function (): void {
    Notification::fake();
    config(['auth.password_reset_timebox_duration' => 345_000]);
    app()->instance(Timebox::class, $timebox = new RecordingTimebox);

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'nobody@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'));

    expect($timebox->durations)->toBe([345_000]);
});

it('does not hash anything for an unknown e-mail: the time comes from the box, not from a dummy bcrypt', function (): void {
    Notification::fake();
    Hash::spy();

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'nobody@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'));

    Hash::shouldNotHaveReceived('make');
});

it('hands the e-mail to the queue, so SMTP latency is not part of the response', function (): void {
    Queue::fake();
    $user = createOrgOwner('owner@example.com');

    expect(app(ResetPassword::class, ['token' => 'token']))->toBeInstanceOf(ShouldQueue::class);

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'owner@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'));

    Queue::assertPushed(
        SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job): bool => $job->notification instanceof ResetPassword
            && $job->notifiables->first()?->is($user) === true,
    );
    Queue::assertPushed(SendQueuedNotifications::class, 1);
});

it('logs a warning without the e-mail when the work takes over 75% of the box', function (): void {
    Notification::fake();
    Log::spy();
    config(['auth.password_reset_timebox_duration' => 1]);
    createOrgOwner('owner@example.com');

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'owner@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'));

    Log::shouldHaveReceived('warning')->once()->with(
        'org.password_reset.timebox_near_limit',
        Mockery::on(fn (array $context): bool => array_keys($context) === ['worked_us', 'budget_us']
            && $context['budget_us'] === 1
            && $context['worked_us'] > 0),
    );
});

it('does not warn when the work fits in the box, even though the box sleeps a bit over the budget', function (): void {
    Sleep::fake(false); // real sleep: the old check measured the box's own sleep too
    Notification::fake();
    Log::spy();
    config(['auth.password_reset_timebox_duration' => 300_000]);
    createOrgOwner('owner@example.com');

    Livewire::test(OrgRequestPasswordReset::class)
        ->fillForm(['email' => 'nobody@example.com'])->call('request')
        ->assertNotified(__('passwords.sent'));

    Log::shouldNotHaveReceived('warning');
});
