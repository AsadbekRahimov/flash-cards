<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Domain\Practice\Services\Modes\PairsTimedMode;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Practice\PairsTimedKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §4.3a, §5.5a (B2): the board of "Pairs against the clock" - 10 rounds of 4 laid out from the words the learner has met, over
 * and over when the words are few, never two words that clash in one round; the board of the usual pairs otherwise.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-18 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return list<array<string, mixed>> the elements of the snapshot a seed gives */
function pairsTimedBuild(Learner $learner, Course $course, Lesson $lesson, string $seed = 'seed-a', int $size = 10): array
{
    return app(PracticeSnapshotBuilder::class)
        ->build(PracticeMode::PairsTimed, new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'), $size, false, $seed)
        ->toArray()['elements'];
}

it('is the fifth mode, solo only, with the handler of its own', function (): void {
    expect(PracticeMode::PairsTimed->value)->toBe('pairs_timed')
        ->and(PracticeMode::PairsTimed->elementKind())->toBe('pairs_round')
        ->and(PracticeMode::PairsTimed->itemType())->toBeNull()
        ->and(PracticeMode::PairsTimed->estimateSeconds())->toBe(60)
        ->and(PracticeMode::PairsTimed->battleable())->toBeFalse()
        ->and(array_values(array_filter(PracticeMode::cases(), fn (PracticeMode $m): bool => ! $m->battleable())))->toBe([PracticeMode::PairsTimed, PracticeMode::WordForm, PracticeMode::ErrorHunt])
        ->and(app(ModeRegistry::class)->for(PracticeMode::PairsTimed))->toBeInstanceOf(PairsTimedMode::class);
});

it('lays 8 words out into 10 rounds of 4, the words coming back between the rounds and never twice in one', function (): void {
    [$learner, $course, $lesson, $words] = PairsTimedKit::world(8);

    $elements = pairsTimedBuild($learner, $course, $lesson);

    expect($elements)->toHaveCount(10);
    foreach ($elements as $i => $element) {
        $ids = array_column($element['left'], 'word_id');
        expect($element['kind'])->toBe('pairs_round')
            ->and($element['round'])->toBe($i + 1)
            ->and($element['rounds'])->toBe(10)
            ->and($ids)->toHaveCount(4)
            ->and(array_unique($ids))->toHaveCount(4)
            ->and($element['right'])->toHaveCount(4)
            ->and($element['pairs'])->toHaveCount(4)
            ->and($element)->not->toHaveKey('battle');
    }

    $all = array_merge(...array_map(fn (array $e): array => array_column($e['left'], 'word_id'), $elements));
    expect(array_unique($all))->toHaveCount(8)
        ->and(array_count_values($all))->each->toBe(5); // 8 words x 5 rounds each = 40 places
});

it('never puts two words with the same translation into one round', function (): void {
    [$learner, $course, $lesson, $words] = PairsTimedKit::world(9);
    // Three words say the same in Russian: they have to stand in different rounds.
    foreach (array_slice($words, 0, 3) as $word) {
        $word->forceFill(['translation' => 'одно и то же'])->save();
    }

    foreach (pairsTimedBuild($learner, $course, $lesson, 'clash') as $element) {
        $labels = array_map(fn (array $card): string => $card['text']['ru'], $element['right']);
        expect(array_unique($labels))->toHaveCount(count($labels));
    }
});

it('gives the same layout for the same seed and another for another', function (): void {
    [$learner, $course, $lesson] = PairsTimedKit::world(12);
    $layout = fn (string $seed): array => array_map(fn (array $e): array => array_column($e['left'], 'word_id'), pairsTimedBuild($learner, $course, $lesson, $seed));

    expect($layout('same'))->toBe($layout('same'))
        ->and($layout('same'))->not->toBe($layout('other'));
});

it('uses every word once in the 10 rounds when there are 40 of them', function (): void {
    [$learner, $course, $lesson] = PairsTimedKit::world(40);

    $all = array_merge(...array_map(fn (array $e): array => array_column($e['left'], 'word_id'), pairsTimedBuild($learner, $course, $lesson)));

    expect($all)->toHaveCount(40)->and(array_unique($all))->toHaveCount(40);
});

it('says no_words with the minimum of 8 when the learner has met fewer fit words, and counts a word without a translation out', function (): void {
    [$learner, $course, $lesson, $words] = PairsTimedKit::world(7);
    $scope = new MaterialScope($course, $lesson);
    $handler = app(PairsTimedMode::class);

    expect($handler->availability($scope, $learner, LocaleSet::of('ru'))->toArray())->toBe(['available' => 7, 'required' => 8, 'reason' => 'no_words']);

    PracticeKit::introduce($learner, Word::factory()->for($lesson)->create(['word' => 'extra', 'translation' => 'экстра']), 'review');
    $fresh = new MaterialScope($course, $lesson);
    expect($handler->availability($fresh, $learner, LocaleSet::of('ru'))->toArray())->toBe(['available' => 8, 'required' => 8, 'reason' => null]);
});

it('says ambiguous when enough words are met but they cannot stand together in one round', function (): void {
    [$learner, $course, $lesson, $words] = PairsTimedKit::world(8);
    foreach ($words as $word) {
        $word->forceFill(['translation' => 'одинаково'])->save();
    }

    $availability = app(PairsTimedMode::class)->availability(new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'));

    expect($availability->isAvailable)->toBeFalse()->and($availability->reason)->toBe('ambiguous');
});

it('knows no mistakes to list: a game against the clock is played again from the start', function (): void {
    [$learner, $course, $lesson] = PairsTimedKit::world(8);
    $snapshot = app(PracticeSnapshotBuilder::class)->build(PracticeMode::PairsTimed, new MaterialScope($course, $lesson), $learner, LocaleSet::of('ru'), 10, false, 'x');

    expect(app(PairsTimedMode::class)->mistakeLabel($snapshot->elements[0], ElementState::fromStored(null, ['matched' => [], 'wrong_left' => ['l1']]), 'ru'))->toBe([]);
});
