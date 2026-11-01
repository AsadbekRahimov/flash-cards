<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Domain\Practice\Support\PracticeContentValidator;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeSession;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §13: the size of a snapshot. It is measured in UTF-8 (a Cyrillic letter is two bytes, not the six of its \uXXXX escape) and
 * the limit `Snapshot::MAX_BYTES` is the round number that keeps at least a quarter on top of the BIGGEST snapshot the content
 * limits of `config/practice.php` (`content.*`) allow: 5 tasks, every text at its limit - English texts and fragments, Russian
 * Cyrillic, Uzbek Latin - for a solo session in each language, for a battle of the two (both languages in every element, exactly 5
 * tasks) and for a dictation with all its spares for the swap. The pairs are not authored: their cards are words and translations
 * of the course (columns of 100 and 500 characters), and even those at the limits of the columns make 9 KB solo, 25 KB in a battle.
 *
 * If the limits of the content grow, or a mode keeps more in its snapshot, the reserve test fails - raise the limit then, or cut
 * the content (PracticeContentValidator), but never let a learner meet a 500 at the opening of a session that the hub offered.
 */

uses(RefreshDatabase::class);

/** The reserve the limit keeps over the biggest valid snapshot. */
const SNAPSHOT_RESERVE = 1.25;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A Russian text of exactly `$length` characters. */
function snapCyrillic(int $length): string
{
    return mb_substr(str_repeat('Пояснение к заданию для ученика. ', 20), 0, $length);
}

/** A Latin text of exactly `$length` characters that begins with `$label` (so that texts differ). */
function snapLatin(int $length, string $label = 'x'): string
{
    return substr($label.str_repeat('abcdefghij', 40), 0, $length);
}

/**
 * A lesson with the biggest content the limits allow, all of it valid (PracticeContentValidator::canonical), and a learner who has
 * met every word: 5 sentences (8 fragments of 40 characters, 6 orders), 5 collocations (160 characters, 3 options of 40), 8 voiced
 * dictation phrases (80 characters and 5 more accepted texts), 7 voiced words - the 3 + 2 of a solo dictation and 4 + 4 spares.
 * All texts in both languages at their limits.
 *
 * @return array{0: Learner, 1: Course, 2: Lesson}
 */
function snapWorstWorld(string $locale = 'ru'): array
{
    [$learner, $course, $lesson] = DictationKit::world($locale);
    $validator = app(PracticeContentValidator::class);
    $audio = app(PracticeAudioService::class);
    $limit = fn (string $key): int => (int) config("practice.content.{$key}");
    $target = DictationKit::word($lesson, $learner, 'target', voiced: false);

    for ($i = 1; $i <= 5; $i++) {
        $fragments = [];
        for ($f = 0; $f < $limit('sentence_fragments.max'); $f++) {
            $fragments[] = snapLatin($limit('fragment_max_length'), "s{$i}f{$f}x");
        }
        $orders = [];
        $order = $fragments;
        for ($o = 0; $o < $limit('sentence_accepted_max'); $o++) {
            $orders[] = $order;
            $order = [...array_slice($order, 1), $order[0]];
        }

        $content = $validator->canonical(PracticeItemType::Sentence, [
            'prompt' => ['ru' => snapCyrillic($limit('prompt_max')), 'uz' => snapLatin($limit('prompt_max'))],
            'fragments' => $fragments,
            'accepted' => $orders,
            'explanation' => ['ru' => snapCyrillic($limit('explanation_max')), 'uz' => snapLatin($limit('explanation_max'))],
        ]);
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($target)->withContent($content)->create();
    }

    for ($i = 1; $i <= 5; $i++) {
        $options = [snapLatin($limit('fragment_max_length'), "a{$i}"), snapLatin($limit('fragment_max_length'), "b{$i}"), snapLatin($limit('fragment_max_length'), "c{$i}")];
        $content = $validator->canonical(PracticeItemType::Collocation, [
            'text' => substr("Let's ___ ".str_repeat("word{$i} ", 40), 0, $limit('collocation_text_max')),
            'options' => $options,
            'correct' => [$options[0]],
            'translation' => ['ru' => snapCyrillic($limit('prompt_max')), 'uz' => snapLatin($limit('prompt_max'))],
            'explanation' => ['ru' => snapCyrillic($limit('explanation_max')), 'uz' => snapLatin($limit('explanation_max'))],
        ]);
        PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($target)->withContent($content)->create();
    }

    for ($i = 1; $i <= 8; $i++) {
        $others = [];
        for ($v = 1; $v <= $limit('dictation_accepted_max'); $v++) {
            $others[] = snapLatin($limit('dictation_text_max'), "p{$i}v{$v}x");
        }
        $content = $validator->canonical(PracticeItemType::Dictation, [
            'text' => snapLatin($limit('dictation_text_max'), "p{$i}x"),
            'accepted' => $others,
            'translation' => ['ru' => snapCyrillic($limit('prompt_max')), 'uz' => snapLatin($limit('prompt_max'))],
            'explanation' => ['ru' => snapCyrillic($limit('explanation_max')), 'uz' => snapLatin($limit('explanation_max'))],
        ]);
        $item = PracticeItem::factory()->dictation()->published()->audioReady()->forLesson($lesson)->targeting($target)->withContent($content)->create();
        Storage::disk('practice_audio')->put((string) $audio->pathFor(PracticeItemVersion::query()->findOrFail($item->published_version_id)), 'mp3');
    }

    for ($i = 1; $i <= 7; $i++) {
        DictationKit::word($lesson, $learner, snapLatin(40, "word{$i}x"), snapCyrillic(100));
    }

    return [$learner, $course, $lesson];
}

/** The size of the snapshot's JSON in UTF-8, and in the escaped form the `json` cast writes. @return array{0: int, 1: int} */
function snapSizes(Snapshot $snapshot): array
{
    return [
        strlen(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        strlen(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR)),
    ];
}

it('builds the biggest snapshot the content limits allow in every mode, for every audience, and keeps a quarter of the limit to spare', function (string $mode, string $audience): void {
    [$learner, $course, $lesson] = snapWorstWorld();
    $scope = new MaterialScope($course, $lesson);
    $builder = app(PracticeSnapshotBuilder::class);
    $mode = PracticeMode::from($mode);

    $snapshot = match ($audience) {
        'solo-ru' => $builder->build($mode, $scope, $learner, LocaleSet::of('ru'), 5, false, str_repeat('a', 32)),
        'solo-uz' => $builder->build($mode, $scope, tap(Learner::factory()->create(['interface_locale' => 'uz']), function (Learner $uz): void {
            foreach (Word::query()->get() as $word) {
                PracticeKit::introduce($uz, $word);
            }
        }), LocaleSet::of('uz'), 5, false, str_repeat('a', 32)),
        default => $builder->build($mode, $scope, null, LocaleSet::of('ru', 'uz'), (int) config('practice.battle.rounds'), true, str_repeat('a', 32)),
    };
    [$utf8] = snapSizes($snapshot);

    expect($snapshot->count())->toBe(5)
        ->and($utf8)->toBeLessThanOrEqual(Snapshot::MAX_BYTES)
        ->and(Snapshot::MAX_BYTES)->toBeGreaterThanOrEqual((int) ceil($utf8 * SNAPSHOT_RESERVE), "the biggest {$audience} {$mode->value} snapshot is {$utf8} bytes");
    // A dictation of a session keeps the spares for the swap with it, a battle has none.
    if ($mode === PracticeMode::Dictation) {
        expect(count($snapshot->pool))->toBe($audience === 'battle' ? 0 : 8);
    }
})->with(function (): array {
    $rows = [];
    foreach (['collocation', 'sentence', 'dictation'] as $mode) {
        foreach (['solo-ru', 'solo-uz', 'battle'] as $audience) {
            $rows["{$mode} {$audience}"] = [$mode, $audience];
        }
    }

    return $rows;
});

it('opens the sessions of the biggest sentences that the hub offers, in ru and in uz: 201, not the 500 of a snapshot over the limit', function (string $locale): void {
    [$learner, $course, $lesson] = snapWorstWorld();
    $learner->update(['interface_locale' => $locale]);
    $headers = PracticeKit::headers($learner);

    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()
        ->assertJsonPath('modes.2.state', 'available')->assertJsonPath('modes.2.available', 5);
    $opened = $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'sentence', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated();

    $stored = Snapshot::fromArray(PracticeSession::query()->findOrFail($opened->json('id'))->snapshot);
    expect($stored->count())->toBe(5)
        ->and(snapSizes($stored)[0])->toBeLessThanOrEqual(Snapshot::MAX_BYTES);
    // That very snapshot did not fit the old limit when it was measured in the escaped form (the ru one; uz is Latin).
    if ($locale === 'ru') {
        expect(snapSizes($stored)[1])->toBeGreaterThan(32 * 1024);
    }
})->with(['ru', 'uz']);

it('opens a dictation with all its spares and swaps a clip in the biggest case: the swap keeps the snapshot under the limit', function (): void {
    [$learner, $course, $lesson] = snapWorstWorld();
    $headers = PracticeKit::headers($learner);

    $opened = $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated();
    $before = Snapshot::fromArray(PracticeSession::query()->findOrFail($opened->json('id'))->snapshot);
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$opened->json('id')}/replace", [
        'request_id' => (string) Str::uuid(), 'element_id' => $opened->json('current.id'), 'reason' => 'audio_unavailable',
    ])->assertOk();
    $after = Snapshot::fromArray(PracticeSession::query()->findOrFail($opened->json('id'))->snapshot);

    expect(count($before->pool))->toBe(8)
        ->and(count($after->pool))->toBe(7)
        ->and(snapSizes($after)[0])->toBeLessThanOrEqual(Snapshot::MAX_BYTES);
});

it('measures the snapshot in UTF-8 bytes: what the escaped form would call too big fits, what is really too big is refused', function (): void {
    $snapshot = fn (int $letters): Snapshot => new Snapshot(PracticeMode::Collocation, ['ru'], [new SnapshotElement('eAAAAAAAAAA', 'collocation', ['text' => str_repeat('Я', $letters)])]);

    // Half of the limit in UTF-8 (2 bytes a letter) is one and a half limits escaped (6 bytes a letter).
    $fits = $snapshot(intdiv(Snapshot::MAX_BYTES, 4));
    expect(snapSizes($fits)[0])->toBeLessThan(Snapshot::MAX_BYTES)
        ->and(snapSizes($fits)[1])->toBeGreaterThan(Snapshot::MAX_BYTES);
    $fits->assertFits();

    // Over the limit in UTF-8 itself.
    $tooBig = $snapshot(intdiv(Snapshot::MAX_BYTES, 2) + 100);
    expect(fn () => $tooBig->assertFits())->toThrow(LogicException::class, 'exceeds the limit of '.Snapshot::MAX_BYTES);
});

it('keeps a round limit with the reserve on top of the biggest snapshot, and no smaller than the 32 KB it replaces', function (): void {
    expect(Snapshot::MAX_BYTES)->toBeGreaterThan(32 * 1024)
        ->and(Snapshot::MAX_BYTES % 1024)->toBe(0)
        ->and(Snapshot::MAX_BYTES / 1024)->toBeLessThanOrEqual(64);
});
