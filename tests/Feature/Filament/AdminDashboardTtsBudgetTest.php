<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AttentionList;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Domain\Content\Services\TtsUsageStats;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\DashboardKpiWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * «Требуют внимания» of /admin and the monthly character limit of Google TTS (docs/26 §5.9, §9.3): a warning from 90 % of
 * `TTS_MONTHLY_CHAR_LIMIT`, an urgent line once it is spent; only with `TTS_DRIVER=google`; from the cached figure.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'));
    config([
        'learning.reminders.quiz.experiment.enabled' => false,
        'twa.tts.driver' => 'google',
        'twa.tts.monthly_char_limit' => 900_000,
    ]);

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

function ttsAttUsage(int $characters, ?string $month = null): void
{
    DB::table(TtsCharacterBudget::TABLE)->updateOrInsert(
        ['month' => $month ?? app(TtsCharacterBudget::class)->month()],
        ['characters' => $characters, 'requests' => 1, 'created_at' => now(), 'updated_at' => now()],
    );
}

it('shows nothing below 90 % of the limit', function (): void {
    ttsAttUsage(809_999);

    expect(app(AttentionList::class)->items())->toBe([]);
});

it('warns from 90 % of the limit with the characters spent', function (): void {
    ttsAttUsage(810_000);

    $items = app(AttentionList::class)->items();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray([
            'kind' => AttentionList::KIND_TTS_BUDGET, 'level' => 'warning', 'name' => '2026-11',
            'count' => 810_000, 'total' => 900_000, 'value' => 90.0,
        ]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Google TTS · расход за месяц')
        ->assertSee("Израсходовано 90\u{00A0}% месячного предела: 810\u{00A0}000 из 900\u{00A0}000 символов")
        ->assertSee('месяц 2026-11');

    Livewire::test(DashboardKpiWidget::class)->assertSee('срочных нет')->assertSee('озвучка');
});

it('puts a spent limit on top as urgent, as a line of its own', function (): void {
    ttsAttUsage(900_000);

    $items = app(AttentionList::class)->items();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_TTS_EXHAUSTED, 'level' => 'danger', 'count' => 900_000, 'value' => 100.0])
        ->and(AttentionList::summary($items))->toBe(['count' => 1, 'urgent' => 1, 'kinds' => ['audio']]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Предел исчерпан: 900')
        ->assertSee('ночная сверка доозвучит слова')
        ->assertDontSee('Израсходовано');

    Livewire::test(DashboardKpiWidget::class)->assertSee('1 срочное');
});

it('takes a limit of 0 for a spent one', function (): void {
    config(['twa.tts.monthly_char_limit' => 0]);

    expect(app(AttentionList::class)->items()[0] ?? null)->toMatchArray(['kind' => AttentionList::KIND_TTS_EXHAUSTED, 'total' => 0]);
});

it('counts the billing month only (Pacific time), not the last one', function (): void {
    ttsAttUsage(900_000, '2026-10');

    expect(app(AttentionList::class)->items())->toBe([]);
});

it('shows nothing while the driver is not google', function (?string $driver): void {
    config(['twa.tts.driver' => $driver]);
    ttsAttUsage(900_000);

    expect(app(TtsUsageStats::class)->current())->toBeNull()
        ->and(app(AttentionList::class)->items())->toBe([])
        ->and(QueryLog::during(fn () => app(AttentionList::class)->items()))->toBe([]);
})->with(['http', 'null', null]);

it('reads the usage once and then from the cache, keyed by the month', function (): void {
    ttsAttUsage(850_000);

    $cold = QueryLog::during(fn () => app(TtsUsageStats::class)->current());
    $warm = QueryLog::during(fn () => app(TtsUsageStats::class)->current());

    expect($cold)->toHaveCount(1)
        ->and($warm)->toBe([])
        ->and(PureCache::store()->has('admin:widget:tts_usage:v1:all:2026-11'))->toBeTrue();

    Livewire::test(AttentionWidget::class)->assertOk();
    expect(QueryLog::during(fn () => Livewire::test(AttentionWidget::class)->assertOk()))->toBe([]);
});

it('catches up with the spending within the K1 window', function (): void {
    config(['cache.pure_store' => 'array']); // the clock of the test moves the clock of an array store only
    ttsAttUsage(100);
    $stats = app(TtsUsageStats::class);

    expect($stats->current())->toMatchArray(['characters' => 100, 'near' => false, 'exhausted' => false]);

    ttsAttUsage(900_000);
    expect($stats->current()['characters'] ?? null)->toBe(100);

    $this->travel(TtsUsageStats::FRESH_SECONDS + 1)->seconds();
    $stats->current();
    app(DeferredCallbackCollection::class)->invoke();
    expect($stats->current())->toMatchArray(['characters' => 900_000, 'exhausted' => true]);
});
