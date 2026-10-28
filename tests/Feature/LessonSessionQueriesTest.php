<?php

declare(strict_types=1);

/*
 * The steps of a LESSON session (`POST /start`, `GET /next`, `POST /review`): what they answer, and what they cost.
 *
 * `progress.total` is the number of ACTIVE words of the lesson. Every step used to count them with a query of its
 * own, and re-read lesson -> stage -> course just to learn the visibility of the course. The count is the cached
 * `LearningCache::lessonWordCount()` now (dropped by every path that changes words, see WordChangePathsTest), the
 * access check is one query (LessonSessionAvailabilityTest has its verdicts).
 */

use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TrainingSession;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

/**
 * A learner with an open session on a lesson of `$active` active and `$inactive` retired words.
 *
 * @return array{learner: Learner, lesson: Lesson, session: TrainingSession, headers: array<string, string>, words: array{active: list<int>, inactive: list<int>}}
 */
function lessonStepsScenario(int $active = 6, int $inactive = 2): array
{
    ['lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => [$active, $inactive]]]);
    $learner = Learner::factory()->onboarded()->create();

    return [
        'learner' => $learner,
        'lesson' => $lessons['1.1'],
        'session' => app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lessons['1.1']),
        'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']],
        'words' => $words['1.1'],
    ];
}

it('reports done of total ACTIVE words on every step of a lesson session', function (): void {
    $s = lessonStepsScenario(6, 2);
    $base = "/api/twa/training/sessions/{$s['session']->id}";

    $this->withHeaders($s['headers'])->postJson("{$base}/start")->assertOk()
        ->assertJsonPath('progress', ['done' => 0, 'total' => 6]);

    $card = $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()
        ->assertJsonPath('progress', ['done' => 0, 'total' => 6])
        ->json('card');

    // "Easy" graduates the card: one of six is done, and the next card is already on its way.
    $this->withHeaders($s['headers'])->postJson("{$base}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 800])->assertOk()
        ->assertJsonPath('progress', ['done' => 1, 'total' => 6]);
    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()
        ->assertJsonPath('progress', ['done' => 1, 'total' => 6]);
});

it('follows a word that is switched off in the admin panel on the very next step', function (): void {
    $s = lessonStepsScenario(5, 0);
    $base = "/api/twa/training/sessions/{$s['session']->id}";

    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()->assertJsonPath('progress.total', 5);

    // What EditWord does after the toggle: counters follow (the lesson cache is dropped).
    $retired = Word::query()->findOrFail($s['words']['active'][4]);
    $retired->update(['is_active' => false]);
    app(LessonContentRefresher::class)->wordChanged($retired);

    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()->assertJsonPath('progress.total', 4);

    $retired->update(['is_active' => true]);
    app(LessonContentRefresher::class)->wordChanged($retired);

    $this->withHeaders($s['headers'])->getJson("{$base}/next")->assertOk()->assertJsonPath('progress.total', 5);
});
