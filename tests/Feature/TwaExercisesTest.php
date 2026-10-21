<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Domain\Learning\Services\ExerciseSelector;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Exercise types (docs/13 §2.5, §8.2; docs/06 §4.3; docs/04 A.3): the selector is deterministic, the
 * prompt carries what the screen needs, spelling / gap-fill are graded by the server and are Pro.
 */

function exHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A personal lesson session with $count review cards due now, all with the same `repetitions`.
 *
 * @return array{learner: Learner, lesson: Lesson, session: TrainingSession, words: list<Word>}
 */
function exScenario(int $count = 1, int $repetitions = 3, bool $pro = false, array $wordAttributes = []): array
{
    $learner = Learner::factory()->create();
    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo']);
    }

    $course = Course::factory()->create(['status' => 'published', 'visibility' => 'public']);
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->for($stage)->create();
    $words = [];
    for ($i = 0; $i < $count; $i++) {
        $words[] = Word::factory()->for($lesson)->create($wordAttributes);
    }

    foreach ($words as $word) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'state' => RepetitionState::Review->value,
            'repetitions' => $repetitions,
            'interval_days' => 6,
            'next_review_at' => now()->subHour(),
            'introduced_mode' => 'lesson',
        ]);
    }

    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);

    return compact('learner', 'lesson', 'session', 'words');
}

function exRepetition(int $repetitions, RepetitionState $state = RepetitionState::Review): WordRepetition
{
    return new WordRepetition(['repetitions' => $repetitions, 'state' => $state->value]);
}

function exWord(int $id, string $word = 'walk', ?string $example = 'They walked home.'): Word
{
    $w = new Word(['word' => $word, 'example' => $example]);
    $w->id = $id;

    return $w;
}

function proEntitlement(): Entitlement
{
    return Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, 1);
}

// --- selector ---------------------------------------------------------------------------------

describe('ExerciseSelector', function (): void {
    beforeEach(function (): void {
        $this->selector = app(ExerciseSelector::class);
        $this->all = new LearnerExerciseSettings;
    });

    it('serves ungraduated cards and the first passes as flash', function (): void {
        foreach ([RepetitionState::New, RepetitionState::Learning, RepetitionState::Relearning] as $state) {
            expect($this->selector->select(exRepetition(5, $state), exWord(1), proEntitlement(), $this->all, 'lesson'))->toBe(ExerciseType::Flash);
        }
        expect($this->selector->select(exRepetition(0), exWord(1), proEntitlement(), $this->all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($this->selector->select(exRepetition(1), exWord(1), proEntitlement(), $this->all, 'lesson'))->toBe(ExerciseType::Flash);
    });

    it('is deterministic: the same inputs always give the same type, and spreads over the three Free types', function (): void {
        $seen = [];
        for ($wordId = 1; $wordId <= 60; $wordId++) {
            $first = $this->selector->select(exRepetition(2), exWord($wordId), Entitlement::free(), $this->all, 'lesson');
            $again = $this->selector->select(exRepetition(2), exWord($wordId), Entitlement::free(), $this->all, 'lesson');
            expect($again)->toBe($first);
            $seen[$first->value] = true;
        }

        expect(array_keys($seen))->toEqualCanonicalizing(['flash', 'reverse', 'audio']);
    });

    it('never gives a server-graded exercise to Free, whatever the repetitions', function (): void {
        for ($reps = 2; $reps <= 12; $reps++) {
            for ($wordId = 1; $wordId <= 10; $wordId++) {
                $type = $this->selector->select(exRepetition($reps), exWord($wordId), Entitlement::free(), $this->all, 'daily_review');
                expect($type->serverGraded())->toBeFalse();
            }
        }
    });

    it('gives Pro gap_fill every third pass when the example carries the word, else spelling', function (): void {
        expect($this->selector->select(exRepetition(3), exWord(1), proEntitlement(), $this->all, 'lesson'))->toBe(ExerciseType::GapFill)
            ->and($this->selector->select(exRepetition(6), exWord(1), proEntitlement(), $this->all, 'lesson'))->toBe(ExerciseType::GapFill)
            ->and($this->selector->select(exRepetition(3), exWord(1, 'go', 'She went home.'), proEntitlement(), $this->all, 'lesson'))->toBe(ExerciseType::Spelling)
            ->and($this->selector->select(exRepetition(3), exWord(1, 'go', null), proEntitlement(), $this->all, 'lesson'))->toBe(ExerciseType::Spelling);

        // Not a third pass: one of the client-graded types.
        foreach ([2, 4, 5, 7] as $reps) {
            expect($this->selector->select(exRepetition($reps), exWord(1), proEntitlement(), $this->all, 'lesson')->serverGraded())->toBeFalse();
        }
    });

    it('respects the learner settings: a switched-off type is skipped, flash never is', function (): void {
        $noTyped = new LearnerExerciseSettings(spelling: false, gapFill: false);
        expect($this->selector->select(exRepetition(3), exWord(1), proEntitlement(), $noTyped, 'lesson')->serverGraded())->toBeFalse();

        $noGap = new LearnerExerciseSettings(gapFill: false);
        expect($this->selector->select(exRepetition(3), exWord(1), proEntitlement(), $noGap, 'lesson'))->toBe(ExerciseType::Spelling);

        $onlyFlash = new LearnerExerciseSettings(false, false, false, false);
        for ($wordId = 1; $wordId <= 20; $wordId++) {
            expect($this->selector->select(exRepetition(3), exWord($wordId), proEntitlement(), $onlyFlash, 'lesson'))->toBe(ExerciseType::Flash);
        }
    });

    it('limits a group lesson to flash and reverse, even for Pro on a third pass', function (): void {
        $seen = [];
        for ($wordId = 1; $wordId <= 40; $wordId++) {
            $type = $this->selector->select(exRepetition(3), exWord($wordId), proEntitlement(), $this->all, 'group_lesson');
            expect($type)->toBeIn([ExerciseType::Flash, ExerciseType::Reverse]);
            $seen[$type->value] = true;
        }
        expect(array_keys($seen))->toEqualCanonicalizing(['flash', 'reverse']);
    });
});

// --- prompt builder ---------------------------------------------------------------------------

describe('ExercisePromptBuilder', function (): void {
    it('builds reverse with four English words and audio with four translations, stable per seed', function (): void {
        $s = exScenario(5, 2);
        $word = $s['words'][0];
        $translation = new ResolvedTranslation($word->translation, 'ru', false);
        $builder = app(ExercisePromptBuilder::class);

        $reverse = $builder->build(ExerciseType::Reverse, $word, $translation, 'ru', null, 42);
        expect($reverse->type)->toBe(ExerciseType::Reverse)
            ->and($reverse->front)->toBe($word->translation)
            ->and($reverse->options)->toHaveCount(4)
            ->and($reverse->options)->toContain($word->word)
            ->and(array_unique(array_map('mb_strtolower', (array) $reverse->options)))->toHaveCount(4)
            ->and($builder->build(ExerciseType::Reverse, $word, $translation, 'ru', null, 42)->options)->toBe($reverse->options);

        // The order is a hash of (seed, candidate): one other seed may happen to give the same order (1 in 120 with
        // four options of five words, and the ids that go into the hash differ per run), so look at a range of seeds.
        $others = array_map(
            static fn (int $seed): array => (array) $builder->build(ExerciseType::Reverse, $word, $translation, 'ru', null, $seed)->options,
            range(43, 72),
        );
        expect(array_filter($others, static fn (array $options): bool => $options !== $reverse->options))->not->toBeEmpty();

        $audio = $builder->build(ExerciseType::Audio, $word, $translation, 'ru', 'https://x.test/a.mp3', 42);
        expect($audio->type)->toBe(ExerciseType::Audio)
            ->and($audio->front)->toBe('')
            ->and($audio->audioUrl)->toBe('https://x.test/a.mp3')
            ->and($audio->options)->toHaveCount(4)
            ->and($audio->options)->toContain($word->translation);
    });

    it('falls back to flash when there are not enough distractors', function (): void {
        $s = exScenario(2, 2);
        $word = $s['words'][0];
        $prompt = app(ExercisePromptBuilder::class)->build(ExerciseType::Reverse, $word, new ResolvedTranslation('x', 'ru', false), 'ru', null, 1);

        expect($prompt->type)->toBe(ExerciseType::Flash)->and($prompt->options)->toBeNull();
    });

    it('borrows distractors from the course and then from published public platform courses only', function (): void {
        $s = exScenario(1, 2);
        $word = $s['words'][0];
        $course = $s['lesson']->stage?->course;

        // A private course of a center must not leak into the options.
        $private = Course::factory()->create(['status' => 'published', 'visibility' => 'private', 'owner_type' => 'organization']);
        $privateLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $private->id]))->create();
        Word::factory()->count(5)->for($privateLesson)->create(['word' => fn () => 'secret'.fake()->unique()->numberBetween(1, 1000)]);

        $builder = app(ExercisePromptBuilder::class);
        expect($builder->build(ExerciseType::Reverse, $word, new ResolvedTranslation('x', 'ru', false), 'ru', null, 1)->type)->toBe(ExerciseType::Flash);

        $otherLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course?->id]))->create();
        Word::factory()->count(3)->for($otherLesson)->create();
        $prompt = $builder->build(ExerciseType::Reverse, $word, new ResolvedTranslation('x', 'ru', false), 'ru', null, 1);

        expect($prompt->type)->toBe(ExerciseType::Reverse)
            ->and(array_filter((array) $prompt->options, fn (string $o): bool => str_starts_with($o, 'secret')))->toBe([]);
    });
});

// --- API --------------------------------------------------------------------------------------

it('returns audio_url when the word has its current pre-generated audio, null otherwise', function (): void {
    Storage::fake('public');
    $s = exScenario(1, 0);
    $word = $s['words'][0];

    $response = $this->withHeaders(exHeaders($s['learner']))->getJson("/api/twa/training/sessions/{$s['session']->id}/next")->assertOk();
    expect($response->json('card.audio_url'))->toBeNull();

    $word->update(['audio_path' => app(WordAudioService::class)->pathFor($word->word)]);
    $response = $this->withHeaders(exHeaders($s['learner']))->getJson("/api/twa/training/sessions/{$s['session']->id}/next")->assertOk();
    expect($response->json('card.audio_url'))->toBe(Storage::disk('public')->url((string) $word->fresh()?->audio_path))
        ->and($response->json('card.prompt.type'))->toBe('flash');
});

it('refuses answer for a flash card: answer_not_accepted for Pro, exercise_pro_required (paywall) for Free', function (): void {
    $free = exScenario(1, 0);
    $this->withHeaders(exHeaders($free['learner']))
        ->postJson("/api/twa/training/sessions/{$free['session']->id}/review", ['word_id' => $free['words'][0]->id, 'answer' => 'walk', 'time_spent_ms' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'exercise_pro_required');

    $pro = exScenario(1, 0, pro: true);
    $this->withHeaders(exHeaders($pro['learner']))
        ->postJson("/api/twa/training/sessions/{$pro['session']->id}/review", ['word_id' => $pro['words'][0]->id, 'answer' => 'walk', 'time_spent_ms' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'answer_not_accepted');

    expect(TrainingReview::query()->count())->toBe(0)
        ->and(WordRepetition::query()->where('learner_id', $free['learner']->id)->value('repetitions'))->toBe(0);
});

it('refuses both quality and answer in one request', function (): void {
    $s = exScenario(1, 0, pro: true);
    $this->withHeaders(exHeaders($s['learner']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", ['word_id' => $s['words'][0]->id, 'quality' => 4, 'answer' => 'walk', 'time_spent_ms' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quality']);

    $this->withHeaders(exHeaders($s['learner']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", ['word_id' => $s['words'][0]->id, 'time_spent_ms' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quality']);
});

it('serves spelling to Pro on the third pass, refuses quality and grades the typed answer itself', function (): void {
    $s = exScenario(1, 3, pro: true, wordAttributes: ['word' => 'resilient', 'example' => 'A paraphrase without the word.']);
    $headers = exHeaders($s['learner']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $card = $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()->json('card');
    // The typed word is the answer: the card must not carry it, its example, transcription or audio.
    expect($card['word'])->toBe('')
        ->and($card['example'])->toBeNull()
        ->and($card['transcription'])->toBeNull()
        ->and($card['audio_url'])->toBeNull();
    expect($card['prompt'])->toBe([
        'type' => 'spelling',
        'front' => $card['translation'],
        'masked_example' => null,
        'options' => null,
        'answer_length' => 9,
    ]);

    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'quality_not_accepted');

    // One edit in a 9-letter word: within the tolerance → the server grades 4.
    $response = $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $card['word_id'], 'answer' => ' Resiliant ', 'time_spent_ms' => 2500])
        ->assertOk();

    expect($response->json('graded'))->toBe(['quality' => 4, 'correct' => true, 'expected' => 'resilient'])
        ->and($response->json('new_interval_days'))->toBeGreaterThan(6);

    $review = TrainingReview::query()->sole();
    expect($review->quality)->toBe(4)
        ->and($review->exercise)->toBe(ExerciseType::Spelling)
        ->and($review->answer_text)->toBe('Resiliant') // TrimStrings middleware; the grader normalizes the case
        ->and($review->interval_before_days)->toBe(6)
        ->and($review->scheduler)->toBe('sm2');
});

it('grades a wrong typed answer as a lapse (quality 1) and an empty answer as wrong', function (): void {
    $s = exScenario(2, 3, pro: true, wordAttributes: ['example' => null]);
    $headers = exHeaders($s['learner']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $card = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');
    expect($card['prompt']['type'])->toBe('spelling');

    $response = $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $card['word_id'], 'answer' => 'completely wrong', 'time_spent_ms' => 100])
        ->assertOk();
    expect($response->json('graded.quality'))->toBe(1)
        ->and($response->json('graded.correct'))->toBeFalse()
        ->and(WordRepetition::query()->where('word_id', $card['word_id'])->sole()->state)->toBe(RepetitionState::Relearning);

    $second = $response->json('card');
    expect($second['prompt']['type'])->toBe('spelling');
    $response = $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $second['word_id'], 'answer' => '', 'time_spent_ms' => 100])
        ->assertOk();
    expect($response->json('graded.quality'))->toBe(1)
        ->and(TrainingReview::query()->where('word_id', $second['word_id'])->value('answer_text'))->toBe(''); // '' = answered nothing; null = client-graded
});

it('cuts answer_text to 100 characters but grades the full text', function (): void {
    $s = exScenario(1, 3, pro: true, wordAttributes: ['word' => 'resilient', 'example' => null]);
    $long = str_repeat('a', 150);

    $this->withHeaders(exHeaders($s['learner']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", ['word_id' => $s['words'][0]->id, 'answer' => $long, 'time_spent_ms' => 100])
        ->assertOk()
        ->assertJsonPath('graded.quality', 1);

    expect(mb_strlen((string) TrainingReview::query()->sole()->answer_text))->toBe(100);
});

it('serves gap_fill when the example carries the word and grades the form in the gap', function (): void {
    $s = exScenario(1, 3, pro: true, wordAttributes: ['word' => 'walk', 'example' => 'They walked home late.']);
    $headers = exHeaders($s['learner']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $card = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');
    expect($card['word'])->toBe('')
        ->and($card['example'])->toBeNull()
        ->and($card['transcription'])->toBeNull()
        ->and($card['audio_url'])->toBeNull();
    expect($card['prompt']['type'])->toBe('gap_fill')
        ->and($card['prompt']['masked_example'])->toBe('They ____ home late.')
        ->and($card['prompt']['answer_length'])->toBe(6)
        ->and($card['prompt']['front'])->toBe($card['translation']);

    // The base word is not the form in the gap: 2 edits in a 6-letter word → wrong.
    $response = $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $card['word_id'], 'answer' => 'WALKED', 'time_spent_ms' => 100])
        ->assertOk();
    expect($response->json('graded'))->toBe(['quality' => 5, 'correct' => true, 'expected' => 'walked'])
        ->and(TrainingReview::query()->sole()->exercise)->toBe(ExerciseType::GapFill);
});

it('never serves a Free learner a server-graded exercise, even on a third pass with an example', function (): void {
    $s = exScenario(6, 3, wordAttributes: ['example' => null]);
    $headers = exHeaders($s['learner']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $types = [];
    for ($i = 0; $i < 6; $i++) {
        $card = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');
        $types[] = $card['prompt']['type'];
        expect($card['prompt']['type'])->toBeIn(['flash', 'reverse', 'audio']);
        if ($card['prompt']['type'] !== 'flash') {
            expect($card['prompt']['options'])->toHaveCount(4);
        }
        $this->withHeaders($headers)
            ->postJson("{$url}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100])
            ->assertOk()
            ->assertJsonPath('graded', null);
    }

    expect(TrainingReview::query()->pluck('exercise')->map(fn (ExerciseType $t): string => $t->value)->all())->toBe($types);
});

it('serves the same prompt twice for the same serve (no state between /next calls)', function (): void {
    $s = exScenario(6, 2);
    $headers = exHeaders($s['learner']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";

    $first = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');
    $second = $this->withHeaders($headers)->getJson("{$url}/next")->json('card');

    expect($second)->toBe($first);
});

it('limits a group lesson to flash and reverse', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create();
    $learner = Learner::factory()->memberOf($group)->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo']);
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    $words = Word::factory()->count(12)->for($lesson)->create();
    foreach ($words as $word) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $word->id, 'state' => RepetitionState::Review->value,
            'repetitions' => 3, 'next_review_at' => now()->subHour(), 'introduced_mode' => 'group_lesson',
        ]);
    }
    $session = TrainingSession::factory()->create(['telegram_group_id' => $group->id, 'lesson_id' => $lesson->id, 'started_by_user_id' => $teacher->id, 'status' => 'open']);
    $headers = exHeaders($learner);

    $types = [];
    for ($i = 0; $i < 12; $i++) {
        $card = $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$session->id}/next")->json('card');
        $types[$card['prompt']['type']] = true;
        $this->withHeaders($headers)
            ->postJson("/api/twa/training/sessions/{$session->id}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100])
            ->assertOk();
    }

    expect(array_keys($types))->toEqualCanonicalizing(['flash', 'reverse']);
});

it('leaves the SM-2 path of a self-graded card unchanged and records exercise = flash', function (): void {
    $s = exScenario(1, 0);
    $word = $s['words'][0];

    $repetition = app(ReviewSubmissionService::class)->submit($s['session'], $s['learner'], (int) $word->id, 5, 900);

    expect($repetition->state)->toBe(RepetitionState::Review)
        ->and($repetition->repetitions)->toBe(1)
        ->and(TrainingReview::query()->sole()->exercise)->toBe(ExerciseType::Flash)
        ->and(TrainingReview::query()->sole()->answer_text)->toBeNull();
});

// --- settings ---------------------------------------------------------------------------------

it('exposes and updates the exercise settings through /me/settings (partial object, strict booleans)', function (): void {
    $learner = Learner::factory()->create();
    $headers = exHeaders($learner);

    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('settings.exercises', ['reverse' => true, 'audio' => true, 'spelling' => true, 'gap_fill' => true]);

    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['exercises' => ['audio' => false]])->assertOk()
        ->assertJsonPath('settings.exercises', ['reverse' => true, 'audio' => false, 'spelling' => true, 'gap_fill' => true]);

    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['exercises' => ['gap_fill' => false, 'audio' => true]])->assertOk()
        ->assertJsonPath('settings.exercises', ['reverse' => true, 'audio' => true, 'spelling' => true, 'gap_fill' => false]);

    expect(LearnerExerciseSettings::fromLearner($learner->fresh() ?? $learner)->gapFill)->toBeFalse();

    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['exercises' => ['audio' => 'no']])->assertStatus(422);
    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['exercises' => ['flash' => false]])->assertStatus(422);
    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['exercises' => 'all'])->assertStatus(422);
});

it('does not serve a switched-off exercise', function (): void {
    $s = exScenario(1, 3, pro: true, wordAttributes: ['word' => 'walk', 'example' => 'They walked home.']);
    $s['learner']->update(['meta' => ['exercises' => ['gap_fill' => false, 'spelling' => false]]]);

    $card = $this->withHeaders(exHeaders($s['learner']))->getJson("/api/twa/training/sessions/{$s['session']->id}/next")->json('card');
    expect($card['prompt']['type'])->toBeIn(['flash', 'reverse', 'audio']);
});

it('records the flash the client was shown when a reverse / audio pick ran short of distractors', function (): void {
    $s = exScenario(2, 2);
    $word = $s['words'][0];
    $repetition = WordRepetition::query()->where('word_id', $word->id)->sole();

    // A pass count for which the selector picks reverse or audio for this word.
    $picked = null;
    for ($r = 2; $r < 80 && $picked === null; $r++) {
        $repetition->repetitions = $r;
        $type = app(ExerciseSelector::class)->select($repetition, $word, Entitlement::free(), new LearnerExerciseSettings, 'lesson');
        if (in_array($type, [ExerciseType::Reverse, ExerciseType::Audio], true)) {
            $picked = $r;
        }
    }
    expect($picked)->not->toBeNull();
    WordRepetition::query()->whereKey($repetition->id)->update(['repetitions' => $picked]);
    // The other word is due later, so this one is served.
    WordRepetition::query()->where('word_id', $s['words'][1]->id)->update(['next_review_at' => now()->addDay()]);

    $headers = exHeaders($s['learner']);
    $url = "/api/twa/training/sessions/{$s['session']->id}";
    $card = $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()->json('card');
    expect($card['word_id'])->toBe($word->id)
        ->and($card['prompt']['type'])->toBe('flash');

    $this->withHeaders($headers)
        ->postJson("{$url}/review", ['word_id' => $word->id, 'quality' => 4, 'time_spent_ms' => 100])
        ->assertOk();

    expect(TrainingReview::query()->sole()->exercise)->toBe(ExerciseType::Flash);
});
