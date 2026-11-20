<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AttentionList;
use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Content\Services\CourseAudioCoverageStats;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\PaymentOrderResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\DashboardKpiWidget;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * «Требуют внимания» of the dashboard of /admin: one line per kind — payments a webhook could not apply, anomalies of the orders,
 * centers with no completed assignment for a week, courses below the audio threshold, the reminder experiment — and «Всё в порядке»
 * when there is nothing; every line from figures the dashboard keeps anyway, so a warm open runs no query.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'));
    config(['learning.reminders.quiz.experiment.enabled' => false]);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

/** @return list<string> the kinds of the list, in order */
function att_kinds(): array
{
    return array_column(app(AttentionList::class)->items(), 'kind');
}

/** A course of `$status` with `$words` active words, `$voiced` of them with audio recorded. */
function att_course(string $status, int $words, int $voiced, string $slug): Course
{
    $course = Course::factory()->create(['status' => $status, 'slug' => $slug]);
    $lesson = Lesson::factory()->create(['stage_id' => Stage::factory()->create(['course_id' => $course->id])->id]);
    for ($i = 0; $i < $words; $i++) {
        Word::factory()->create(['lesson_id' => $lesson->id, 'is_active' => true, 'audio_path' => $i < $voiced ? "audio/{$slug}-{$i}.mp3" : null]);
    }

    return $course;
}

it('says «Всё в порядке» when nothing needs attention', function (): void {
    expect(att_kinds())->toBe([])
        ->and(AttentionList::summary([]))->toBe(['count' => 0, 'urgent' => 0, 'kinds' => []]);

    Livewire::test(AttentionWidget::class)
        ->assertOk()
        ->assertSee('Требуют внимания')
        ->assertSee('Всё в порядке')
        ->assertSeeHtml('data-testid="dashboard-attention-empty"');

    Livewire::test(DashboardKpiWidget::class)->assertSee('срочных нет')->assertSee('всё в порядке');
});

it('puts a payment the webhook could not apply on top, as urgent, with a link to the failed payments', function (): void {
    Payment::factory()->failed()->create(['provider' => 'payme', 'currency' => 'UZS', 'amount' => 500_000]);

    $items = app(AttentionList::class)->items();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_PAYMENTS_FAILED, 'level' => 'danger', 'count' => 1]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Вебхуки оплаты')
        ->assertSee('Не зачислено платежей за 24 часа: 1')
        ->assertSee('сейчас')
        ->assertSeeHtml(e(PaymentResource::getUrl('index', ['filters' => ['status' => ['value' => Payment::STATUS_FAILED]]])));

    expect(urldecode(PaymentResource::getUrl('index', ['filters' => ['status' => ['value' => Payment::STATUS_FAILED]]])))->toContain('filters[status][value]=failed');

    Livewire::test(DashboardKpiWidget::class)->assertSee('1 срочное')->assertSee('оплаты');
});

it('lists the anomalies of the orders as one urgent line from the cached webhook figures', function (): void {
    $key = WidgetCache::key('webhook_errors', app(WidgetCache::class)->moneyScope());
    PureCache::store()->put($key, [
        'failed_payments' => 0, 'failed_by_provider' => [], 'canceled_transactions' => 4,
        'double_discounts' => 1, 'tier_mismatches' => 2, 'stars_double_subscriptions' => 0,
    ], 300);
    PureCache::store()->put(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key, time(), 300);

    $items = app(AttentionList::class)->items();

    // Canceled provider transactions are a learner's choice, not a fault: no line for them.
    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_PAYMENT_ANOMALIES, 'level' => 'danger', 'count' => 3]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Заказы с расхождениями')
        ->assertSee('Двойная скидка, другой тариф или двойная подписка Stars: 3')
        ->assertSeeHtml(e(PaymentOrderResource::getUrl('index')));
});

it('lists an active center that has waited a week or more for a completed assignment, the oldest first, and not the others', function (): void {
    $old = Organization::factory()->create(['name' => 'Lingua Center', 'status' => 'active', 'created_at' => now()->subDays(9)]);
    $older = Organization::factory()->create(['name' => 'Bilim Academy', 'status' => 'active', 'created_at' => now()->subDays(12)]);
    Organization::factory()->create(['name' => 'Fresh School', 'status' => 'active', 'created_at' => now()->subDays(6)]);
    Organization::factory()->suspended()->create(['name' => 'Closed One', 'created_at' => now()->subDays(30)]);
    $done = Organization::factory()->create(['name' => 'English House', 'status' => 'active', 'created_at' => now()->subDays(20)]);
    $assignment = Assignment::factory()->create(['organization_id' => $done->id]);
    AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'completed_at' => now()->subDays(18)]);

    $items = app(AttentionList::class)->items();

    expect(array_column($items, 'name'))->toBe(['Bilim Academy', 'Lingua Center'])
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_CENTER, 'level' => 'warning', 'id' => $older->id, 'value' => 12]);

    Livewire::test(AttentionWidget::class)
        ->assertSeeInOrder(['Bilim Academy', '12 дней', 'Lingua Center', '9 дней'])
        ->assertSee('Нет выполненного задания с момента создания')
        ->assertSeeHtml(e(OrganizationResource::getUrl('edit', ['record' => $old->id])))
        ->assertDontSee('Fresh School')
        ->assertDontSee('Closed One')
        ->assertDontSee('English House');
});

it('shows three waiting centers one by one and the rest as one line', function (): void {
    foreach (range(1, 5) as $i) {
        Organization::factory()->create(['name' => "Center {$i}", 'status' => 'active', 'created_at' => now()->subDays(10 + $i)]);
    }

    $items = app(AttentionList::class)->items();

    expect(array_column($items, 'kind'))->toBe(['center', 'center', 'center', 'centers_more'])
        ->and(array_column(array_slice($items, 0, 3), 'name'))->toBe(['Center 5', 'Center 4', 'Center 3'])
        ->and($items[3])->toMatchArray(['count' => 2, 'total' => 5]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Ещё 2 без выполненного задания дольше 7 дней')
        ->assertSee('всего 5');
});

it('lists a published or reviewed course below 95 % of voiced words, with the words still missing, and not a draft', function (): void {
    $short = att_course('published', 20, 18, 'english-c2');   // 90 %
    att_course('review', 20, 19, 'english-b2');               // 95 %: at the threshold, fine
    att_course('draft', 10, 0, 'english-draft');               // not in front of learners yet
    att_course('published', 10, 10, 'english-a1');

    $items = app(AttentionList::class)->items();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_AUDIO, 'level' => 'warning', 'id' => $short->id, 'name' => 'english-c2', 'count' => 2, 'value' => 90.0, 'total' => 20]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('english-c2 · озвучка')
        ->assertSee('Покрытие 90 %, порог публикации 95 %')
        ->assertSee('2 слова')
        ->assertSeeHtml(e(CourseResource::getUrl('edit', ['record' => $short->id])));
});

it('reads the coverage of all the courses with one grouped statement, cached', function (): void {
    att_course('published', 5, 1, 'one');
    att_course('published', 5, 2, 'two');

    $cold = QueryLog::during(fn () => app(CourseAudioCoverageStats::class)->belowThreshold());
    $warm = QueryLog::during(fn () => app(CourseAudioCoverageStats::class)->belowThreshold());

    expect($cold)->toHaveCount(1)
        ->and($cold[0])->toContain('group by')
        ->and($warm)->toBe([])
        ->and(PureCache::store()->has('admin:widget:audio_coverage:v1:all'))->toBeTrue();
});

it('shows the reminder experiment while it runs: its day, then that the report can be read', function (string $startsAt, string $state, string $text, string $value): void {
    config([
        'learning.reminders.quiz.experiment.enabled' => true,
        'learning.reminders.quiz.experiment.starts_at' => $startsAt,
    ]);

    $items = app(AttentionList::class)->items();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_REMINDERS, 'level' => 'info', 'state' => $state]);

    $widget = Livewire::test(AttentionWidget::class)->assertSee('Сравнение напоминаний')->assertSee($text);
    if ($value !== '') {
        $widget->assertSee($value);
    }
})->with([
    'day 12' => ['2026-11-09', 'running', 'Идёт 12-й день · отчёт можно читать с 14-го', '2 дня'],
    'day 14' => ['2026-11-07', 'ready', 'Идёт 14-й день · можно читать отчёт', 'готово'],
    'not started yet' => ['2026-11-23', 'scheduled', 'Включено, деление начнётся позже', '3 дня'],
    'no start date' => ['', 'running', 'Идёт; дата начала не задана', ''],
    'unreadable start date' => ['next tuesday-ish', 'invalid', 'Дата начала не читается', ''],
]);

it('shows nothing of the reminder experiment while it is switched off', function (): void {
    config(['learning.reminders.quiz.experiment.starts_at' => '2026-11-09']);

    expect(att_kinds())->toBe([]);
});

it('orders the lines urgent first and sums them up for the KPI card', function (): void {
    config(['learning.reminders.quiz.experiment.enabled' => true, 'learning.reminders.quiz.experiment.starts_at' => '2026-11-09']);
    att_course('published', 10, 5, 'half');
    Organization::factory()->create(['name' => 'Waiting', 'status' => 'active', 'created_at' => now()->subDays(8)]);
    Payment::factory()->failed()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 100_000]);

    $items = app(AttentionList::class)->items();

    expect(array_column($items, 'level'))->toBe(['danger', 'warning', 'warning', 'info'])
        ->and(AttentionList::summary($items))->toBe(['count' => 4, 'urgent' => 1, 'kinds' => ['payments', 'centers', 'audio', 'reminders']]);

    Livewire::test(DashboardKpiWidget::class)
        ->assertSee('Требуют внимания')
        ->assertSee('1 срочное')
        ->assertSee('оплаты, центры, озвучка, напоминания');
});

it('costs no query on a warm open', function (): void {
    Payment::factory()->failed()->create(['provider' => 'payme', 'currency' => 'UZS', 'amount' => 500_000]);
    Organization::factory()->create(['status' => 'active', 'created_at' => now()->subDays(9)]);
    att_course('published', 4, 1, 'warm');

    Livewire::test(AttentionWidget::class)->assertOk();

    expect(QueryLog::during(fn () => Livewire::test(AttentionWidget::class)->assertOk()))->toBe([]);
});

it('is for an active admin only', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $this->actingAs($teacher);

    expect(AttentionWidget::canView())->toBeFalse();
    Livewire::test(AttentionWidget::class)->assertForbidden();
});
