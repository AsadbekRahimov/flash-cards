<?php

declare(strict_types=1);

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Game\Enums\ItemAvailability;
use App\Domain\Game\Enums\ItemStatus;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Services\GameShopCache;
use App\Domain\Game\Services\TicketService;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Resources\GameItemResource\Pages\CreateGameItem;
use App\Filament\Resources\GameItemResource\Pages\EditGameItem;
use App\Filament\Resources\GameItemResource\Pages\ListGameItems;
use App\Filament\Resources\GameRunResource\Pages\ListGameRuns;
use App\Filament\Resources\GameRunResource\Pages\ViewGameRun;
use App\Filament\Resources\GameRunResource\RelationManagers\AttemptsRelationManager;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\CaravanRelationManager;
use App\Filament\Widgets\GameActivityWidget;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

use Tests\Support\FilamentQueryBudget;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/*
 * docs/18 §12.10 (G2.11, part 1): the admin screens of the game "Караван" - the items of the shop, the runs (read-only), the wallet of a
 * learner with manual moves under audit, the activity widget. Admin only: a teacher gets 403. The query budgets of the two lists are here too.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->admin = gat_user('admin');
    $this->actingAs($this->admin);
});

function gat_user(string $role, bool $active = true): User
{
    $user = User::factory()->create([
        'is_active' => $active,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, mixed> a valid form of a cosmetic */
function gat_form(array $override = []): array
{
    return [
        'key' => 'camel_desert',
        'kind' => 'cosmetic',
        'slot' => 'camel',
        'price_coins' => 120,
        'availability' => 'base',
        'sort' => 5,
        'title' => ['ru' => 'Пустынный верблюд', 'uz' => 'Sahro tuyasi'],
        'description' => ['ru' => 'Описание', 'uz' => 'Tavsif'],
        'asset' => 'camel_dromedary',
        ...$override,
    ];
}

function gat_wallet(Learner $learner, int $coins): void
{
    DB::transaction(fn () => app(WalletService::class)->credit($learner->id, $coins, LedgerKind::AdminGrant, "admin:seed:{$learner->id}"));
}

// --- access ---------------------------------------------------------------------------------------

it('lets only an active admin open the game screens', function (): void {
    $run = GameRun::factory()->create();
    $item = GameItem::factory()->create();
    $urls = ['/admin/game-items', '/admin/game-items/create', "/admin/game-items/{$item->id}/edit", '/admin/game-runs', "/admin/game-runs/{$run->id}"];

    foreach ($urls as $url) {
        $this->actingAs($this->admin)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $this->admin->id])->get($url)->assertOk();
    }

    foreach (['teacher' => gat_user('teacher'), 'inactive admin' => gat_user('admin', active: false)] as $who => $user) {
        foreach ($urls as $url) {
            $this->actingAs($user)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])->get($url)->assertForbidden();
        }
    }
});

// --- items ----------------------------------------------------------------------------------------

it('creates an item as a draft and moves the version of the cached catalogue on', function (): void {
    $before = (int) Cache::get(GameShopCache::VERSION_KEY, 0);

    livewire(CreateGameItem::class)->fillForm(gat_form())->call('create')->assertHasNoFormErrors();

    $item = GameItem::query()->where('key', 'camel_desert')->firstOrFail();
    expect($item->status)->toBe(ItemStatus::Draft)
        ->and($item->price_coins)->toBe(120)
        ->and($item->title)->toBe(['ru' => 'Пустынный верблюд', 'uz' => 'Sahro tuyasi'])
        ->and($item->effect)->toBeNull()
        ->and((int) Cache::get(GameShopCache::VERSION_KEY, 0))->toBeGreaterThan($before);
});

it('refuses a bad key, a taken key and an asset outside the whitelist', function (string $key): void {
    livewire(CreateGameItem::class)->fillForm(gat_form(['key' => $key]))->call('create')->assertHasFormErrors(['key']);
    expect(GameItem::query()->count())->toBe(0);
})->with(['capital' => 'Camel', 'dash' => 'camel-1', 'leading underscore' => '_camel', 'cyrillic' => 'верблюд', 'too long' => str_repeat('a', 49), 'space' => 'a b']);

it('refuses a key that is taken', function (): void {
    GameItem::factory()->create(['key' => 'camel_desert']);

    livewire(CreateGameItem::class)->fillForm(gat_form())->call('create')->assertHasFormErrors(['key']);
    expect(GameItem::query()->count())->toBe(1);
});

it('refuses an asset that is not on the list', function (): void {
    livewire(CreateGameItem::class)->fillForm(gat_form(['asset' => 'https://evil.example/x.png']))->call('create')->assertHasFormErrors(['asset']);
    livewire(CreateGameItem::class)->fillForm(gat_form(['asset' => 'camel_golden']))->call('create')->assertHasNoFormErrors();
});

it('needs a slot for a cosmetic, an effect for a booster and an achievement for that availability', function (): void {
    livewire(CreateGameItem::class)->fillForm(gat_form(['slot' => null]))->call('create')->assertHasFormErrors(['slot']);
    livewire(CreateGameItem::class)->fillForm(gat_form(['kind' => 'booster', 'effect' => null]))->call('create')->assertHasFormErrors(['effect']);
    livewire(CreateGameItem::class)->fillForm(gat_form(['availability' => 'achievement', 'achievement_key' => null]))->call('create')->assertHasFormErrors(['achievement_key']);

    livewire(CreateGameItem::class)->fillForm(gat_form(['availability' => 'achievement', 'achievement_key' => 'streak_7']))->call('create')->assertHasNoFormErrors();
    expect(GameItem::query()->where('key', 'camel_desert')->value('achievement_key'))->toBe('streak_7');
});

it('keeps the key as it was when an item is edited, and moves the catalogue version on', function (): void {
    $item = GameItem::factory()->create(['key' => 'camel_old', 'price_coins' => 50]);
    $before = (int) Cache::get(GameShopCache::VERSION_KEY, 0);

    livewire(EditGameItem::class, ['record' => $item->id])
        ->assertFormFieldIsDisabled('key')
        ->fillForm(['price_coins' => 75, 'key' => 'camel_new'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->key)->toBe('camel_old')
        ->and($item->fresh()->price_coins)->toBe(75)
        ->and((int) Cache::get(GameShopCache::VERSION_KEY, 0))->toBeGreaterThan($before);
});

it('warns when the price of an item given to everybody changes, and only then', function (): void {
    $default = GameItem::factory()->create(['price_coins' => 0, 'availability' => ItemAvailability::Base]);
    $other = GameItem::factory()->create(['price_coins' => 30]);

    livewire(EditGameItem::class, ['record' => $other->id])->fillForm(['price_coins' => 40])->call('save')->assertNotNotified(__('admin.game.items.notifications.default_lost'));

    livewire(EditGameItem::class, ['record' => $default->id])
        ->assertSeeText(__('admin.game.items.fields.price_default_warning'))
        ->fillForm(['price_coins' => 10])
        ->call('save')
        ->assertNotified(__('admin.game.items.notifications.default_lost'));
});

it('publishes and archives an item from the list, each through the catalogue version', function (): void {
    $item = GameItem::factory()->draft()->create();

    livewire(ListGameItems::class)
        ->assertTableActionVisible('publish', $item)
        ->callTableAction('publish', $item);
    expect($item->fresh()->status)->toBe(ItemStatus::Published);

    $before = (int) Cache::get(GameShopCache::VERSION_KEY, 0);
    livewire(ListGameItems::class)
        ->assertTableActionHidden('publish', $item)
        ->callTableAction('archive', $item);
    expect($item->fresh()->status)->toBe(ItemStatus::Archived)
        ->and((int) Cache::get(GameShopCache::VERSION_KEY, 0))->toBeGreaterThan($before);
});

it('does not let a teacher call the item actions directly', function (): void {
    $item = GameItem::factory()->draft()->create();
    $this->actingAs(gat_user('teacher'));

    Livewire::test(ListGameItems::class)->assertForbidden();
    expect($item->fresh()->status)->toBe(ItemStatus::Draft);
});

// --- runs -----------------------------------------------------------------------------------------

it('filters the runs by kind, status, course, learner and dates', function (): void {
    $learner = Learner::factory()->create();
    $course = Course::factory()->published()->create();
    $daily = GameRun::factory()->daily()->finished()->create(['learner_id' => $learner->id, 'started_at' => '2026-10-01 10:00:00']);
    $stop = GameRun::factory()->create(['course_id' => $course->id, 'started_at' => '2026-10-10 10:00:00']);
    $boss = GameRun::factory()->boss()->finished(RunFinishReason::Failed)->create(['started_at' => '2026-10-20 10:00:00']);

    livewire(ListGameRuns::class)->assertCanSeeTableRecords([$daily, $stop, $boss])
        ->filterTable('kind', 'daily')->assertCanSeeTableRecords([$daily])->assertCanNotSeeTableRecords([$stop, $boss])
        ->removeTableFilters()
        ->filterTable('status', 'active')->assertCanSeeTableRecords([$stop])->assertCanNotSeeTableRecords([$daily, $boss])
        ->removeTableFilters()
        ->filterTable('course_id', $course->id)->assertCanSeeTableRecords([$stop])->assertCanNotSeeTableRecords([$daily, $boss])
        ->removeTableFilters()
        ->filterTable('learner', ['learner_id' => $learner->id])->assertCanSeeTableRecords([$daily])->assertCanNotSeeTableRecords([$stop, $boss])
        ->removeTableFilters()
        ->filterTable('started', ['from' => '2026-10-05', 'until' => '2026-10-10'])->assertCanSeeTableRecords([$stop])->assertCanNotSeeTableRecords([$daily, $boss]);
});

it('shows a run by the kinds of its elements, its attempts and rewards - and never the snapshot', function (): void {
    $run = GameRun::factory()->finished()->create([
        'coins_awarded' => 14,
        'league_points' => 9,
        'srs_pulled' => 3,
        'snapshot' => ['version' => 1, 'elements' => [
            ['id' => 'e1', 'kind' => 'translate_fwd', 'expected' => 'SECRET-ANSWER-ONE'],
            ['id' => 'e2', 'kind' => 'translate_fwd', 'expected' => 'SECRET-ANSWER-TWO'],
            ['id' => 'e3', 'kind' => 'spell', 'expected' => 'SECRET-ANSWER-THREE'],
        ]],
    ]);
    GameRunAttempt::factory()->create(['run_id' => $run->id, 'learner_id' => $run->learner_id, 'element_id' => 'e1', 'result' => ['expected' => 'SECRET-RESULT']]);

    livewire(ViewGameRun::class, ['record' => $run->id])
        ->assertSeeText('Перевод на английский × 2')
        ->assertSeeText('Написание × 1')
        ->assertSeeText('14')
        ->assertSeeText('9')
        ->assertDontSeeText('SECRET-ANSWER')
        ->assertDontSeeText('SECRET-RESULT');

    livewire(AttemptsRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewGameRun::class])
        ->assertSeeText('e1')
        ->assertDontSeeText('SECRET-RESULT');
});

it('has no way to create, edit or delete a run', function (): void {
    $run = GameRun::factory()->create();

    expect($this->admin->can('create', GameRun::class))->toBeFalse()
        ->and($this->admin->can('update', $run))->toBeFalse()
        ->and($this->admin->can('delete', $run))->toBeFalse();
});

it('never selects the snapshot or the state in the list of runs', function (): void {
    GameRun::factory()->count(2)->create();

    $sql = collect(QueryLog::during(fn () => Livewire::test(ListGameRuns::class)))->filter(fn (string $q): bool => str_contains($q, 'from "game_runs"') || str_contains($q, 'from `game_runs`'));

    expect($sql->isNotEmpty())->toBeTrue()
        ->and($sql->contains(fn (string $q): bool => str_contains($q, 'snapshot') || str_contains($q, '"state"')))->toBeFalse();
});

// --- wallet of a learner -------------------------------------------------------------------------

it('shows the balance, the tickets and the ledger on the tab of a learner', function (): void {
    $learner = Learner::factory()->create();
    gat_wallet($learner, 40);
    GameProfile::factory()->create(['learner_id' => $learner->id, 'tickets' => 2]);

    livewire(CaravanRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])
        ->assertSeeText('Баланс: 40')
        ->assertSeeText('Билеты: 2')
        ->assertCanSeeTableRecords(GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->get());
});

it('shows the last 50 entries only', function (): void {
    $learner = Learner::factory()->create();
    GameWallet::factory()->create(['learner_id' => $learner->id, 'balance' => 0]);
    GameCoinLedgerEntry::factory()->count(55)->create(['learner_id' => $learner->id, 'amount' => 0, 'balance_after' => 0]);

    $component = livewire(CaravanRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class]);

    expect($component->instance()->getTableRecords())->toHaveCount(50);
});

it('grants coins under an audit record written first and the key admin:{audit id}', function (): void {
    $learner = Learner::factory()->create();

    livewire(CaravanRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])
        ->callAction(TestAction::make('grant')->table(), ['amount' => 25, 'reason' => 'Компенсация за сбой'])
        ->assertNotified(__('admin.game.wallet.notifications.granted'));

    $audit = AuditLog::query()->where('action', 'game.coins_adjusted')->sole();
    $entry = GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->sole();

    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->meta)->toMatchArray(['direction' => 'credit', 'amount' => 25, 'reason' => 'Компенсация за сбой'])
        ->and($entry->idempotency_key)->toBe('admin:'.$audit->id)
        ->and($entry->kind)->toBe(LedgerKind::AdminGrant)
        ->and($entry->amount)->toBe(25)
        ->and($entry->meta)->toMatchArray(['audit_log_id' => $audit->id])
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(25)
        ->and((int) GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->sum('amount'))->toBe(25);
});

it('revokes coins, and refuses to go below zero with a notification and the record marked rejected', function (): void {
    $learner = Learner::factory()->create();
    gat_wallet($learner, 30);

    $tab = livewire(CaravanRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class]);
    $tab->callAction(TestAction::make('revoke')->table(), ['amount' => 20, 'reason' => 'Ошибочное начисление'])->assertNotified(__('admin.game.wallet.notifications.revoked'));
    expect(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(10);

    $tab->callAction(TestAction::make('revoke')->table(), ['amount' => 11, 'reason' => 'Слишком много'])->assertNotified(__('admin.game.wallet.notifications.refused'));

    expect(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(10)
        ->and(GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'game.coins_adjusted')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'game.coins_adjusted')->orderByDesc('id')->first()?->meta)->toMatchArray(['rejected' => 'coins_insufficient']);
});

it('validates the amount and the reason of a manual move', function (): void {
    $learner = Learner::factory()->create();
    foreach ([['amount' => 0, 'reason' => 'x', 'field' => 'amount'], ['amount' => 5, 'reason' => '', 'field' => 'reason'], ['amount' => 5, 'reason' => str_repeat('я', 201), 'field' => 'reason']] as $case) {
        livewire(CaravanRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])
            ->callAction(TestAction::make('grant')->table(), ['amount' => $case['amount'], 'reason' => $case['reason']])
            ->assertHasActionErrors([$case['field']]);
    }

    expect(AuditLog::query()->where('action', 'game.coins_adjusted')->count())->toBe(0);
});

it('gives the tab to an admin only', function (): void {
    $learner = Learner::factory()->create();
    $this->actingAs(gat_user('teacher'));

    expect(CaravanRelationManager::canViewForRecord($learner, ViewLearner::class))->toBeFalse();
    Livewire::test(CaravanRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])->assertForbidden();
});

it('does not offer the tickets to grant: the service of the tickets comes with G2.8', function (): void {
    $learner = Learner::factory()->create();

    livewire(CaravanRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])
        ->assertActionDoesNotExist('tickets');
})->skip(fn (): bool => class_exists(TicketService::class), 'TicketService exists: the grant belongs to the tab');

// --- widget ---------------------------------------------------------------------------------------

it('counts the runs of 7 days, the learners who played and their share among the active', function (): void {
    $players = Learner::factory()->count(2)->create(['last_seen_at' => now()->subDay()]);
    Learner::factory()->count(2)->create(['last_seen_at' => now()->subDays(2)]);
    Learner::factory()->create(['last_seen_at' => now()->subDays(30)]);

    GameRun::factory()->create(['learner_id' => $players[0]->id, 'started_at' => now()->subDays(2)]);
    GameRun::factory()->finished()->create(['learner_id' => $players[0]->id, 'started_at' => now()->subDays(3)]);
    GameRun::factory()->finished()->create(['learner_id' => $players[1]->id, 'started_at' => now()->subDays(6)]);
    GameRun::factory()->finished()->create(['learner_id' => $players[1]->id, 'started_at' => now()->subDays(9)]);

    livewire(GameActivityWidget::class)
        ->assertSeeText('Забегов начато')
        ->assertSeeText('3')
        ->assertSeeText('50 %');
});

it('keeps the figures in the cache entry of the widget for 300 seconds, polls nothing and is for admins only', function (): void {
    $learner = Learner::factory()->create(['last_seen_at' => now()]);
    GameRun::factory()->create(['learner_id' => $learner->id]);

    livewire(GameActivityWidget::class)->assertOk();

    $key = WidgetCache::key('game_activity', 'all');
    expect($key)->toBe('admin:widget:game_activity:v1:all')
        ->and(PureCache::store()->get($key))->toBe(['runs' => 1, 'players' => 1, 'active' => 1, 'share' => 100])
        ->and(GameActivityWidget::FRESH_SECONDS)->toBe(300)
        ->and((new ReflectionProperty(GameActivityWidget::class, 'pollingInterval'))->getDefaultValue())->toBeNull();

    // A second render reads the entry: no query of the game tables.
    $queries = QueryLog::during(fn () => Livewire::test(GameActivityWidget::class));
    expect(QueryLog::matching($queries, 'game_runs'))->toBe([]);

    $this->actingAs(gat_user('teacher'));
    expect(GameActivityWidget::canView())->toBeFalse();
    Livewire::test(GameActivityWidget::class)->assertForbidden();
});

// --- query budgets ---------------------------------------------------------------------------------

it('lists the items within the budget of 6 statements', function (): void {
    FilamentQueryBudget::assert(ListGameItems::class, function (int $rows): void {
        GameItem::factory()->count($rows - GameItem::query()->count())->create();
    }, ceiling: 6);
});

it('lists the runs within the budget of 8 statements', function (): void {
    FilamentQueryBudget::assert(ListGameRuns::class, function (int $rows): void {
        GameRun::factory()->count($rows - GameRun::query()->count())->finished()->create();
    }, ceiling: 8);
});
