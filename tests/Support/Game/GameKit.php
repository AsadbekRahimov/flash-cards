<?php

declare(strict_types=1);

namespace Tests\Support\Game;

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;

/**
 * What the tests of the game share (docs/18 §12): the game open to everybody, a stop with words the learner knows (distinct
 * translations, an example with the word in it, a voice when asked), and the answers a client would send to an element of a run -
 * read from the snapshot, which a test may.
 */
final class GameKit
{
    /** The game is `off` by default (docs/18 §12.7.4): open it to everybody. */
    public static function openAccess(): void
    {
        config(['game.access' => 'all']);
    }

    /** No random event in the runs (the tests of the events set the chance themselves). */
    public static function noEvents(): void
    {
        config(['game.events.chance' => 0.0]);
    }

    /**
     * A published course with one stop (module 1, lesson 1) and a learner introduced to `$words` words of it.
     *
     * @return array{0: Learner, 1: Course, 2: Lesson, 3: list<Word>}
     */
    public static function stop(int $words = 8, bool $voiced = false, string $state = 'review'): array
    {
        [$course, $lesson] = PracticeKit::course();
        $learner = Learner::factory()->create();
        $list = [];
        for ($i = 1; $i <= $words; $i++) {
            $list[] = self::word($lesson, $learner, "wordnum{$i}", "перевод {$i}", $voiced, $state);
        }

        return [$learner, $course, $lesson, $list];
    }

    /**
     * A stop rich enough for every kind of task: `$words` voiced words with examples (a round of pairs, listen, gap, spell, the
     * translations), a published collocation, a sentence and a dictation with its clip.
     *
     * @return array{0: Learner, 1: Course, 2: Lesson, 3: list<Word>}
     */
    public static function richStop(int $words = 13): array
    {
        Storage::fake('practice_audio');
        [$learner, $course, $lesson, $list] = self::stop($words, voiced: true);
        PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($list[0])->create();
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($list[1])->create();
        DictationKit::phrase($lesson, $learner, 'Phrase of the caravan');

        return [$learner, $course, $lesson, $list];
    }

    /**
     * A word of the lesson the learner has been introduced to: a translation of its own, an example with the word in it, and - when
     * `$voiced` - the recording of its current text on the disk of the words.
     */
    public static function word(Lesson $lesson, Learner $learner, string $text, string $translation, bool $voiced = false, string $state = 'review'): Word
    {
        $word = Word::factory()->for($lesson)->create([
            'word' => $text,
            'translation' => $translation,
            'example' => "I say {$text} every day.",
            'audio_path' => $voiced ? app(WordAudioService::class)->pathFor($text) : null,
        ]);
        if ($voiced) {
            Storage::disk('public')->put((string) $word->audio_path, 'mp3:'.$text);
        }
        PracticeKit::introduce($learner, $word, $state);

        return $word;
    }

    /** @return array<string, mixed> the element of the snapshot by its id (the secrets included: a test may read them) */
    public static function element(GameRun $run, string $elementId): array
    {
        foreach ($run->refresh()->snapshot['elements'] as $element) {
            if ($element['id'] === $elementId) {
                return $element;
            }
        }

        throw new RuntimeException("No element {$elementId} in the run {$run->id}.");
    }

    /** The current element of the run (null when it is over). @return array<string, mixed>|null */
    public static function current(GameRun $run): ?array
    {
        $run->refresh();
        $index = (int) ($run->state['current'] ?? 0);

        return $run->snapshot['elements'][$index] ?? null;
    }

    /**
     * The answers that get the element right, in order (a round of pairs: every match; the others: one answer).
     *
     * @param  array<string, mixed>  $element
     * @return list<array<string, mixed>>
     */
    public static function rightAnswers(array $element): array
    {
        return match ($element['kind']) {
            'translate_fwd', 'translate_back', 'listen' => [['option_id' => $element['correct_option_id']]],
            'spell' => [['text' => $element['expected']]],
            'gap' => [['text' => $element['expected'][0]]],
            'pairs_round' => array_map(static fn (string $left, string $right): array => ['left_id' => $left, 'right_id' => $right], array_keys($element['pairs']), array_values($element['pairs'])),
            'collocation' => [['option_id' => $element['correct_ids'][0]]],
            'sentence' => [['order' => self::sentenceOrder($element)]],
            'dictation' => [['text' => $element['display']]],
            default => throw new RuntimeException("No answers for an element of the kind {$element['kind']}."),
        };
    }

    /**
     * One answer that gets the element wrong (a round of pairs: a mismatch of its first word).
     *
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public static function wrongAnswer(array $element): array
    {
        return match ($element['kind']) {
            'translate_fwd', 'translate_back', 'listen' => ['option_id' => array_values(array_diff(array_column($element['options'], 'id'), [$element['correct_option_id']]))[0]],
            'spell', 'gap', 'dictation' => ['text' => 'zzzzzzzzzz'],
            'pairs_round' => self::pairsMismatch($element),
            'collocation' => ['option_id' => array_values(array_diff(array_column($element['options'], 'id'), $element['correct_ids']))[0]],
            'sentence' => ['order' => array_reverse(self::sentenceOrder($element))],
            default => throw new RuntimeException("No answers for an element of the kind {$element['kind']}."),
        };
    }

    /**
     * Plays the run to its end: every element right, except the ones whose position (0-based) is in `$wrongAt` - those get one wrong
     * answer (a round of pairs: a mismatch, then the right matches).
     *
     * @param  list<int>  $wrongAt
     */
    public static function play(RunService $runs, GameRun $run, Learner $learner, array $wrongAt = [], ?CarbonImmutable $now = null): GameRun
    {
        for ($guard = 0; $guard < 100; $guard++) {
            $run->refresh();
            if (! $run->isActive()) {
                return $run;
            }
            $index = (int) ($run->state['current'] ?? 0);
            $element = $run->snapshot['elements'][$index] ?? throw new RuntimeException('The run is active without a current element.');

            if (in_array($index, $wrongAt, true)) {
                $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], self::wrongAnswer($element), null, $now);
                $run->refresh();
                if (! $run->isActive() || $element['kind'] !== 'pairs_round') {
                    continue;
                }
            }
            foreach (self::rightAnswers($element) as $answer) {
                $run->refresh();
                if (! $run->isActive()) {
                    return $run;
                }
                $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer, null, $now);
            }
        }

        throw new RuntimeException('The run did not end in 100 steps.');
    }

    /**
     * The ids of the fragments of a sentence in its first accepted order.
     *
     * @param  array<string, mixed>  $element
     * @return list<string>
     */
    public static function sentenceOrder(array $element): array
    {
        $pool = $element['fragments'];
        $ids = [];
        foreach (explode(' ', $element['accepted'][0]) as $token) {
            foreach ($pool as $i => $fragment) {
                if (PracticeTextNormalizer::normalize($fragment['text']) === $token) {
                    $ids[] = $fragment['id'];
                    unset($pool[$i]);

                    continue 2;
                }
            }

            throw new RuntimeException("No fragment reads {$token}.");
        }

        return $ids;
    }

    /** @param array<string, mixed> $element @return array{left_id: string, right_id: string} */
    private static function pairsMismatch(array $element): array
    {
        $left = array_keys($element['pairs'])[0];
        $own = $element['pairs'][$left];
        $other = array_values(array_diff(array_column($element['right'], 'id'), [$own]))[0];

        return ['left_id' => $left, 'right_id' => $other];
    }
}
