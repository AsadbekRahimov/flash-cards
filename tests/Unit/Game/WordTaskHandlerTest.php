<?php

declare(strict_types=1);

use App\Domain\Game\DTO\RunPoolWord;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Tasks\WordTaskHandler;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Learning\Services\SpellingGrader;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Services\Modes\PairsMode;

/*
 * docs/18 §12.3.1, §12.3.2: the five tasks made of the words - the verdicts of each (correct / typo / wrong / an empty answer), the forms
 * of an answer to a gap, the options a client may not invent, the projection without a secret of the snapshot, the hint (the same one
 * every time), the review and the label of a mistake. Pure: elements are built from words of the pool, nothing touches the database.
 */

const WTH_SEED = 'word-task-seed-aaaaaaaaaaaaaaaaaaaaaaa';

function wthHandler(): WordTaskHandler
{
    return new WordTaskHandler(new SpellingGrader);
}

function wthLocales(): LocaleSet
{
    return LocaleSet::of('ru', 'uz');
}

function wthWord(int $id, string $word, string $ru, ?string $uz = null, bool $voiced = true, ?string $example = null): RunPoolWord
{
    $translations = ['ru' => $ru, 'uz' => $uz ?? "{$ru}-uz"];
    $material = new MaterialWord($id, 1, $word, $ru, $voiced ? "audio/{$word}.mp3" : null, $translations, $example ?? "I say {$word} every day.");

    return new RunPoolWord($material, $voiced ? "audio/{$word}.mp3" : null, (new GapFillBuilder)->fromText($word, $material->example), PairsMode::candidate($material, wthLocales()));
}

/** @return list<RunPoolWord> three distractors */
function wthDistractors(): array
{
    return [wthWord(2, 'bread', 'хлеб'), wthWord(3, 'water', 'вода'), wthWord(4, 'house', 'дом')];
}

function wthChoice(TaskKind $kind, ?RunEvent $event = null): SnapshotElement
{
    $taken = [];

    return WordTaskHandler::buildChoice($kind, wthWord(1, 'mother', 'мама', 'ona'), wthDistractors(), wthLocales(), WTH_SEED, $taken, $event);
}

function wthSpell(string $word = 'apple'): SnapshotElement
{
    $taken = [];

    return WordTaskHandler::buildSpell(wthWord(1, $word, 'яблоко', 'olma'), wthLocales(), $taken);
}

function wthGap(): SnapshotElement
{
    $taken = [];

    return WordTaskHandler::buildGap(wthWord(1, 'walk', 'гулять', 'yurmoq', example: 'We walk to school.'), wthLocales(), $taken);
}

function wthState(): RunState
{
    return RunState::initial(3);
}

/** The id of an option of the element: the right one, or the first wrong one. */
function wthOption(SnapshotElement $element, bool $right): string
{
    $correct = (string) $element->get('correct_option_id');
    foreach ($element->get('options') as $option) {
        if (($option['id'] === $correct) === $right) {
            return $option['id'];
        }
    }

    throw new RuntimeException('No such option.');
}

// ------------------------------------------------------------------ the kinds with options

it('grades an option: the right one is correct, any other wrong with the word to be brought closer', function (TaskKind $kind): void {
    $element = wthChoice($kind);

    $right = wthHandler()->grade($element, ['option_id' => wthOption($element, true)], wthState());
    $wrong = wthHandler()->grade($element, ['option_id' => wthOption($element, false)], wthState());

    expect($right->verdict)->toBe(Verdict::Correct)->and($right->srsErrorWordIds)->toBe([])->and($right->final)->toBeTrue()
        ->and($right->normalizedAnswer)->toBe(['option_id' => wthOption($element, true)])
        ->and($wrong->verdict)->toBe(Verdict::Wrong)->and($wrong->srsErrorWordIds)->toBe([1])
        ->and($right->reveal)->toBe($wrong->reveal);
})->with([TaskKind::TranslateFwd, TaskKind::TranslateBack, TaskKind::Listen]);

it('takes a skip as a skipped verdict and a mistake for the SRS, in every kind', function (SnapshotElement $element): void {
    $grade = wthHandler()->grade($element, ['skip' => true], wthState());

    expect($grade->verdict)->toBe(Verdict::Skipped)->and($grade->srsErrorWordIds)->toBe([1])->and($grade->normalizedAnswer)->toBe(['skip' => true])
        ->and($grade->reveal)->not->toBeNull();
})->with([
    'translate_fwd' => fn () => wthChoice(TaskKind::TranslateFwd),
    'translate_back' => fn () => wthChoice(TaskKind::TranslateBack),
    'listen' => fn () => wthChoice(TaskKind::Listen),
    'spell' => fn () => wthSpell(),
    'gap' => fn () => wthGap(),
]);

it('refuses an option that is not one of the four, and an answer of the wrong shape', function (): void {
    $element = wthChoice(TaskKind::TranslateFwd);
    $refuse = static function (array $answer) use ($element): string {
        try {
            wthHandler()->grade($element, $answer, wthState());
        } catch (GameException $e) {
            return $e->errorCode;
        }

        return 'accepted';
    };

    expect($refuse(['option_id' => 'oNope']))->toBe('unknown_option_id')
        ->and($refuse(['option_id' => '']))->toBe('invalid_answer_shape')
        ->and($refuse(['option_id' => 5]))->toBe('invalid_answer_shape')
        ->and($refuse(['text' => 'мама']))->toBe('invalid_answer_shape')
        ->and($refuse([]))->toBe('invalid_answer_shape');
});

it('shows the options in the locale of the viewer and never marks the right one', function (): void {
    $fwd = wthChoice(TaskKind::TranslateFwd);
    $back = wthChoice(TaskKind::TranslateBack);

    $ru = wthHandler()->project($fwd, 'ru', wthState());
    $uz = wthHandler()->project($fwd, 'uz', wthState());

    expect(array_column($ru['options'], 'text'))->toContain('мама', 'хлеб', 'вода', 'дом')
        ->and(array_column($uz['options'], 'text'))->toContain('ona', 'хлеб-uz', 'вода-uz', 'дом-uz')
        ->and($ru['prompt'])->toBe('mother')
        ->and(wthHandler()->project($back, 'uz', wthState())['prompt'])->toBe('ona')
        ->and(array_column(wthHandler()->project($back, 'ru', wthState())['options'], 'text'))->toContain('mother', 'bread', 'water', 'house');
});

it('builds four distinct options of the seed, the right one among them, the same every time', function (): void {
    $a = wthChoice(TaskKind::TranslateBack);
    $b = wthChoice(TaskKind::TranslateBack);

    expect(array_column($a->get('options'), 'text'))->toBe(array_column($b->get('options'), 'text'))
        ->and($a->get('options'))->toHaveCount(4)
        ->and(array_unique(array_column($a->get('options'), 'id')))->toHaveCount(4)
        ->and(in_array($a->get('correct_option_id'), array_column($a->get('options'), 'id'), true))->toBeTrue();
});

it('plays a listen task: the audio flag in the projection, a sandstorm only as a field of the element', function (): void {
    $storm = wthChoice(TaskKind::Listen, RunEvent::Sandstorm);

    $projection = wthHandler()->project($storm, 'ru', wthState());

    expect($projection['audio'])->toBeTrue()
        ->and($projection)->not->toHaveKey('audio_path')->not->toHaveKey('audio_disk')
        ->and($storm->get('audio_path'))->toBe('audio/mother.mp3')
        ->and($storm->get('event'))->toBe('sandstorm');
    $taken = [];
    expect(fn () => WordTaskHandler::buildChoice(TaskKind::Listen, wthWord(1, 'mother', 'мама', voiced: false), wthDistractors(), wthLocales(), WTH_SEED, $taken))->toThrow(InvalidArgumentException::class);
});

it('refuses to build with the wrong number of distractors or a kind without options', function (): void {
    $taken = [];
    $word = wthWord(1, 'mother', 'мама');

    expect(fn () => WordTaskHandler::buildChoice(TaskKind::TranslateFwd, $word, array_slice(wthDistractors(), 0, 2), wthLocales(), WTH_SEED, $taken))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WordTaskHandler::buildChoice(TaskKind::Spell, $word, wthDistractors(), wthLocales(), WTH_SEED, $taken))->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------------------------------ spell

it('grades a spelling: exact (any case and spacing) is correct, a letter off is a typo, anything else wrong', function (string $answer, Verdict $verdict): void {
    $grade = wthHandler()->grade(wthSpell('apple'), ['text' => $answer], wthState());

    expect($grade->verdict)->toBe($verdict)
        ->and($grade->srsErrorWordIds)->toBe($verdict === Verdict::Wrong ? [1] : [])
        ->and($grade->normalizedAnswer)->toBe(['text' => $answer]);
})->with([
    'exact' => ['apple', Verdict::Correct],
    'upper case' => ['APPLE', Verdict::Correct],
    'spaces around' => ['  apple ', Verdict::Correct],
    'a letter missing' => ['aple', Verdict::Typo],
    'two letters swapped' => ['appel', Verdict::Typo],
    'another word' => ['banana', Verdict::Wrong],
    'gibberish' => ['zzzzzzzzzz', Verdict::Wrong],
]);

it('takes an empty or blank text as a skip, and refuses a text that is not a string or is too long', function (): void {
    $element = wthSpell();

    foreach (['', '   '] as $blank) {
        $grade = wthHandler()->grade($element, ['text' => $blank], wthState());
        expect($grade->verdict)->toBe(Verdict::Skipped)->and($grade->srsErrorWordIds)->toBe([1]);
    }
    foreach ([['text' => 5], ['text' => ['apple']], ['text' => str_repeat('a', WordTaskHandler::MAX_TEXT + 1)], ['option_id' => 'o1'], []] as $answer) {
        expect(fn () => wthHandler()->grade($element, $answer, wthState()))->toThrow(fn (GameException $e) => expect($e->errorCode)->toBe('invalid_answer_shape'));
    }
});

it('projects a spelling as the translation and the length, not the word', function (): void {
    $element = wthSpell('apple');
    $projection = wthHandler()->project($element, 'uz', wthState());

    expect($projection)->toBe(['id' => $element->id, 'kind' => 'spell', 'prompt' => 'olma', 'answer_length' => 5])
        ->and(json_encode($projection))->not->toContain('apple');
});

// ------------------------------------------------------------------ gap

it('grades a gap by the forms of the word: the form of the example, the base word and the forms of it are correct; a letter off a typo', function (string $answer, Verdict $verdict): void {
    $grade = wthHandler()->grade(wthGap(), ['text' => $answer], wthState());

    expect($grade->verdict)->toBe($verdict);
})->with([
    'the form of the example' => ['walk', Verdict::Correct],
    'upper case' => ['Walk', Verdict::Correct],
    'a form of the word' => ['walks', Verdict::Correct],
    'a letter off a form of 5 letters' => ['walkz', Verdict::Typo],
    'a short word has no tolerance' => ['walc', Verdict::Wrong],
    'another word' => ['run', Verdict::Wrong],
]);

it('takes the form of the word that stands in the example, whatever it is', function (): void {
    $taken = [];
    $word = wthWord(1, 'walk', 'гулять', example: 'He walks to school.');
    $element = WordTaskHandler::buildGap($word, wthLocales(), $taken);

    expect($element->get('correct_text'))->toBe('walks')->and($element->get('expected'))->toContain('walks', 'walk')
        ->and(wthHandler()->grade($element, ['text' => 'walks'], wthState())->verdict)->toBe(Verdict::Correct)
        ->and(wthHandler()->grade($element, ['text' => 'walk'], wthState())->verdict)->toBe(Verdict::Correct)
        ->and($element->get('full_text'))->toBe('He walks to school.');
});

it('refuses a gap without the example of the word, and a gap answer of the wrong shape', function (): void {
    $taken = [];
    $noExample = new RunPoolWord(new MaterialWord(1, 1, 'walk', 'гулять', null, ['ru' => 'гулять'], null), null, null, null);

    expect(fn () => WordTaskHandler::buildGap($noExample, wthLocales(), $taken))->toThrow(InvalidArgumentException::class)
        ->and(fn () => wthHandler()->grade(wthGap(), ['option_id' => 'o1'], wthState()))->toThrow(GameException::class);
});

it('projects a gap as the sentence with the blank and the translation, without the word', function (): void {
    $element = wthGap();
    $projection = wthHandler()->project($element, 'ru', wthState());

    expect(array_keys($projection))->toBe(['id', 'kind', 'masked', 'translation'])
        ->and($projection['masked'])->toBe($element->get('masked'))->and($projection['translation'])->toBe('гулять')
        ->and($projection['masked'])->not->toContain('walk ');
});

// ------------------------------------------------------------------ projections, hints, reviews

it('projects no key of the snapshot that holds a secret, for any kind', function (SnapshotElement $element): void {
    $projection = wthHandler()->project($element, 'ru', wthState());
    $forbidden = ['correct_option_id', 'expected', 'pairs', 'correct_ids', 'accepted', 'audio_path', 'audio_disk', 'word_id', 'version_id', 'item_id', 'target_word_ids', 'word', 'correct_text', 'full_text', 'seed'];

    expect(array_intersect(array_keys($projection), $forbidden))->toBe([])
        ->and($projection['id'])->toBe($element->id)->and($projection['kind'])->toBe($element->kind);
    foreach ($projection['options'] ?? [] as $option) {
        expect(array_keys($option))->toBe(['id', 'text']);
    }
})->with([
    'translate_fwd' => fn () => wthChoice(TaskKind::TranslateFwd),
    'translate_back' => fn () => wthChoice(TaskKind::TranslateBack),
    'listen' => fn () => wthChoice(TaskKind::Listen),
    'spell' => fn () => wthSpell(),
    'gap' => fn () => wthGap(),
]);

it('hints the same way every time: two wrong options removed, or the first two letters', function (): void {
    $choice = wthChoice(TaskKind::TranslateFwd);
    $hint = wthHandler()->hint($choice, wthState());

    expect($hint)->toBe(wthHandler()->hint($choice, wthState()))
        ->and($hint['options_removed'])->toHaveCount(2)
        ->and(array_unique($hint['options_removed']))->toHaveCount(2)
        ->and(in_array($choice->get('correct_option_id'), $hint['options_removed'], true))->toBeFalse()
        ->and(wthHandler()->hint(wthSpell('apple'), wthState()))->toBe(['prefix' => 'ap'])
        ->and(wthHandler()->hint(wthGap(), wthState()))->toBe(['prefix' => 'wa']);
});

it('shows the hint already given as part of the projection', function (): void {
    $element = wthChoice(TaskKind::Listen);
    $state = wthState();
    $state->markHinted($element->id, wthHandler()->hint($element, $state) ?? []);

    expect(wthHandler()->project($element, 'ru', $state)['hint'])->toBe(wthHandler()->hint($element, $state));
});

it('reveals the right answer in the form of the kind, in the locale of the viewer', function (): void {
    $fwd = wthChoice(TaskKind::TranslateFwd);
    $back = wthChoice(TaskKind::TranslateBack);

    expect(wthHandler()->reveal($fwd, 'ru', wthState()))->toBe(['correct_id' => $fwd->get('correct_option_id'), 'correct_text' => 'мама', 'word' => 'mother', 'translation' => 'мама'])
        ->and(wthHandler()->reveal($fwd, 'uz', wthState())['correct_text'])->toBe('ona')
        ->and(wthHandler()->reveal($back, 'ru', wthState())['correct_text'])->toBe('mother')
        ->and(wthHandler()->reveal(wthChoice(TaskKind::Listen), 'uz', wthState()))->toMatchArray(['correct_text' => 'mother', 'translation' => 'ona'])
        ->and(wthHandler()->reveal(wthSpell('apple'), 'ru', wthState()))->toBe(['correct_text' => 'apple', 'translation' => 'яблоко'])
        ->and(wthHandler()->reveal(wthGap(), 'uz', wthState()))->toBe(['correct_text' => 'walk', 'full_text' => 'We walk to school.', 'translation' => 'yurmoq']);
});

it('labels a mistake with the word and its translation and the answer the learner gave, an option by its text', function (): void {
    $element = wthChoice(TaskKind::TranslateBack);
    $wrong = wthOption($element, false);
    $state = wthState();
    $state->markAnswered($element->id, Verdict::Wrong, true, 0, true, ['option_id' => $wrong], '2026-11-05T10:00:00.000Z');
    $shown = array_column(wthHandler()->project($element, 'ru', $state)['options'], 'text', 'id');

    $rows = wthHandler()->mistakeLabel($element, $state, 'ru');

    expect($rows)->toBe([['label' => 'mother — мама', 'your_answer' => $shown[$wrong]]])
        ->and(wthHandler()->mistakeLabel($element, $state, 'uz')[0]['label'])->toBe('mother — ona');

    $spell = wthSpell('apple');
    $typed = wthState();
    $typed->markAnswered($spell->id, Verdict::Wrong, true, 0, true, ['text' => 'aple?'], '2026-11-05T10:00:00.000Z');
    $skipped = wthState();
    $skipped->markAnswered($spell->id, Verdict::Skipped, true, 0, true, ['skip' => true], '2026-11-05T10:00:00.000Z');

    expect(wthHandler()->mistakeLabel($spell, $typed, 'ru'))->toBe([['label' => 'apple — яблоко', 'your_answer' => 'aple?']])
        ->and(wthHandler()->mistakeLabel($spell, $skipped, 'ru'))->toBe([['label' => 'apple — яблоко', 'your_answer' => null]])
        ->and(wthHandler()->mistakeLabel($spell, wthState(), 'ru'))->toBe([['label' => 'apple — яблоко', 'your_answer' => null]]);
});

it('says which words an element is about, and plays only the five kinds', function (): void {
    expect(wthHandler()->wordIds(wthSpell()))->toBe([1])
        ->and(wthHandler()->kinds())->toBe([TaskKind::TranslateFwd, TaskKind::TranslateBack, TaskKind::Listen, TaskKind::Spell, TaskKind::Gap])
        ->and(fn () => wthHandler()->project(new SnapshotElement('eX', 'dictation', []), 'ru', wthState()))->toThrow(InvalidArgumentException::class);
});
