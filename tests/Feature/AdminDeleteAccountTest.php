<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\PaymentOrderResource\Pages\ViewPaymentOrder;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * "Удалить аккаунт" in the admin (support, owner's decision of 03.11.2026): admin only (`LearnerPolicy::update`, like the
 * block), with a confirmation, audited with the admin's id; and the payer snapshot as a line of its own on the payment
 * and the order.
 */

uses(RefreshDatabase::class);

function delPanelUser(string $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('deletes a learner from the list for an admin, with an audit record naming the admin, and ends their TWA session', function (): void {
    $admin = delPanelUser('admin');
    $learner = Learner::factory()->create();
    $token = app(JwtService::class)->issue($learner->id)['token'];
    $this->actingAs($admin);

    Livewire::test(ListLearners::class)->callTableAction('deleteAccount', $learner)->assertHasNoTableActionErrors();

    $audit = AuditLog::query()->where('action', 'learner.deleted')->sole();
    expect(Learner::query()->find($learner->id))->toBeNull()
        ->and(Learner::withTrashed()->find($learner->id))->not->toBeNull()
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->meta)->toBe(['by' => 'admin']);
    $this->withToken($token)->getJson('/api/twa/me')->assertUnauthorized()->assertJsonPath('error.code', 'learner_not_found');
});

it('deletes a learner from their page and goes back to the list', function (): void {
    $learner = Learner::factory()->create();
    $this->actingAs(delPanelUser('admin'));

    Livewire::test(ViewLearner::class, ['record' => $learner->getRouteKey()])
        ->callAction('deleteAccount')
        ->assertHasNoActionErrors()
        ->assertRedirect(LearnerResource::getUrl('index'));

    expect(Learner::query()->find($learner->id))->toBeNull();
});

it('hides the action from a teacher and refuses a forged call', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = delPanelUser('teacher');
    $teacher->telegramGroups()->attach($group->id);
    $learner = Learner::factory()->memberOf($group)->create();
    $this->actingAs($teacher);

    expect($teacher->can('update', $learner))->toBeFalse();
    Livewire::test(ListLearners::class)->assertTableActionHidden('deleteAccount', $learner);

    Livewire::test(ListLearners::class)
        ->call('mountTableAction', 'deleteAccount', $learner->getKey())
        ->call('callMountedTableAction');

    expect(Learner::query()->find($learner->id))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.deleted')->count())->toBe(0);
});

it('asks for a confirmation: nothing is deleted before it', function (): void {
    $learner = Learner::factory()->create();
    $this->actingAs(delPanelUser('admin'));

    Livewire::test(ListLearners::class)
        ->assertTableActionExists('deleteAccount', fn (Action $action): bool => $action->isConfirmationRequired()
            && $action->getModalHeading() === 'Удалить аккаунт ученика?'
            && str_contains((string) $action->getModalDescription(), 'запустив бота'), $learner)
        ->mountTableAction('deleteAccount', $learner);

    expect(Learner::query()->find($learner->id))->not->toBeNull();
});

it('shows the payer snapshot as a line of its own on the payment and the order', function (): void {
    $this->actingAs(delPanelUser('admin'));
    $snapshot = ['payer_telegram_user_id' => 555_002, 'payer_name' => 'Zinaida K', 'payer_username' => 'zina'];
    $payment = Payment::factory()->create($snapshot);
    $order = PaymentOrder::factory()->create($snapshot);
    $bare = Payment::factory()->create(['payer_telegram_user_id' => null, 'payer_name' => null, 'payer_username' => null]);

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->assertSee('Плательщик (снимок)')
        ->assertSee('Zinaida K @zina · tg:555002');
    Livewire::test(ViewPaymentOrder::class, ['record' => $order->id])
        ->assertSee('Плательщик (снимок)')
        ->assertSee('Zinaida K @zina · tg:555002');
    Livewire::test(ViewPayment::class, ['record' => $bare->id])
        ->assertSee('Плательщик (снимок)');
});
