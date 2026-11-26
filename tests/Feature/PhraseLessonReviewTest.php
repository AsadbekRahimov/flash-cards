<?php

declare(strict_types=1);

use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Twa\Services\JwtService;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CardAnswers;
use Tests\Support\PhraseKit;

/*
 * The review of a lesson just studied (`lesson_review`, docs/06 §15) on a lesson of PHRASES (docs/06 §16): the phrases met today -
 * in a personal lesson or in a group lesson of a chat - are asked, every answer comes back with `revealed.phrase` (the kind, the
 * register, the literal), and the round still writes nothing: no repetition, no grade, no day statistics, no points.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    config([
        'learning.lesson_review' => ['enabled' => true, 'max_words' => 20, 'max_serves_per_word' => 3],
        'billing.plans.free.limits.new_words_per_day' => 10,
        'practice.access' => 'all', 'practice.plan' => 'all', 'game.access' => 'off',
    ]);
});

/** @return array<string, string> */
function plrHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** Plays an opened session (`session_id`, `card`) to its end with right answers and finishes it. @param array<string, mixed> $opened */
function plrPlay(object $test, array $headers, array $opened, bool $finish = true): void
{
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 200; $guard++) {
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json('card');
    }
    expect($card)->toBeNull();
    if ($finish) {
        $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();
    }
}

/** @return array<string, mixed> what a round may never change for the learner */
function plrFootprint(Learner $learner): array
{
    $rows = static fn (string $table): array => DB::table($table)->where('learner_id', $learner->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();

    return [
        'repetitions' => $rows('word_repetitions'),
        'reviews' => DB::table('training_reviews')->where('learner_id', $learner->id)->count(),
        'day' => $rows('learner_daily_stats'),
        'credits' => DB::table('score_credits')->where('learner_id', $learner->id)->count(),
    ];
}

it('reviews the phrases met today with revealed.phrase on every answer, and writes nothing (personal or group lesson)', function (string $where): void {
    [$course, $lesson] = PhraseKit::import();
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'interface_locale' => 'ru']);
    $h = plrHeaders($learner);

    if ($where === 'group') {
        $group = TelegramGroup::factory()->create(['status' => 'active']);
        $teacher = User::factory()->create();
        DB::table('teacher_groups')->insert(['user_id' => $teacher->id, 'telegram_group_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);
        GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id, 'status' => 'active', 'left_at' => null]);
        $session = app(TrainingSessionService::class)->openForLesson($group, Lesson::query()->findOrFail($lesson->id), $teacher);
        $started = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$session->id}/start")->assertOk()->json();
        plrPlay($this, $h, ['session_id' => $session->id, 'card' => $started['card']], finish: false);
    } else {
        $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id])->assertOk()->json();
        plrPlay($this, $h, $opened);
    }
    $before = plrFootprint($learner);
    expect($before['repetitions'])->not->toBeEmpty();

    $this->travel(2)->minutes();
    $start = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lesson->id])->assertOk()->json();
    $card = $start['card'];
    $answers = 0;
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        // Wrong on the first card, right afterwards: neither may reach the schedule.
        $answered = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$start['session_id']}/review", CardAnswers::body($card, $answers === 0 ? 2 : 4))
            ->assertOk()->json();
        $answers++;
        expect($answered['revealed'] ?? null)->not->toBeNull()
            ->and($answered['revealed']['phrase'] ?? null)->toBeArray()
            ->and($answered['revealed']['phrase'])->toHaveKeys(['kind', 'register', 'variant', 'literal']);
        $card = $answered['card'];
    }
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$start['session_id']}/finish")->assertOk();

    expect($answers)->toBeGreaterThan(1)
        ->and(plrFootprint($learner))->toBe($before);
})->with(['personal', 'group']);
