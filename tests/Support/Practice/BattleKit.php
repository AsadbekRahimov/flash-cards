<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Domain\Practice\DTO\BattleAnswerOutcome;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Battle\BattleReader;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Models\Battle;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\Word;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the tests of the battle (18.6) share: two friends who read different languages (ru / uz), a lesson with words and
 * published tasks of every type for the REAL modes, and the steps of a match - invite, accept, ready, the first poll that
 * starts it, the right / wrong answer to a round built from the snapshot the server keeps.
 *
 * The practice is opened to everybody and the audio disk is faked by the test (`Storage::fake('practice_audio')` BEFORE
 * `world()`: the dictations write their clips there).
 */
final class BattleKit
{
    public const T0 = '2026-10-30 10:00:00';

    /**
     * A course with one lesson, `$words` words (ru "перевод N", uz "tarjima N") and `$items` published tasks of each of the three
     * authored types, two learners (ru / uz) who are friends.
     *
     * @return array{0: Learner, 1: Learner, 2: Course, 3: Lesson, 4: Friendship}
     */
    public static function world(int $words = 24, int $items = 6, string $slug = 'english-a1'): array
    {
        PracticeKit::openAccess();
        [$course, $lesson] = PracticeKit::course($slug);
        $me = Learner::factory()->create(['interface_locale' => 'ru', 'first_name' => 'Bek']);
        $friend = Learner::factory()->create(['interface_locale' => 'uz', 'first_name' => 'Dilnoza']);
        $friendship = Friendship::factory()->between($me, $friend)->create();

        $list = self::words($lesson, $words);
        self::items($lesson, $list, $items);

        return [$me, $friend, $course, $lesson, $friendship];
    }

    /**
     * Words of the lesson with a translation in both languages; distinct, so the pairs pack 5 rounds of 4 out of 20.
     *
     * @return list<Word>
     */
    public static function words(Lesson $lesson, int $count): array
    {
        $words = [];
        for ($i = 1; $i <= $count; $i++) {
            $word = Word::factory()->for($lesson)->create(['word' => "word{$i}", 'translation' => "перевод {$i}"]);
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => "tarjima {$i}"]);
            $words[] = $word;
        }

        return $words;
    }

    /**
     * `$count` published tasks of each authored type, aimed at the words; the dictations have their clips on the audio disk.
     *
     * @param  list<Word>  $words
     */
    public static function items(Lesson $lesson, array $words, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $target = $words[($i - 1) % max(1, count($words))] ?? null;
            $factory = fn () => $target === null ? PracticeItem::factory()->published()->forLesson($lesson) : PracticeItem::factory()->published()->forLesson($lesson)->targeting($target);

            $factory()->collocation()->withContent([
                'text' => "Phrase {$i}: they ___ it.",
                'options' => ["right{$i}", "wrong{$i}a", "wrong{$i}b"],
                'correct' => [0],
                'translation' => ['ru' => "Перевод {$i}", 'uz' => "Tarjima {$i}"],
                'explanation' => ['ru' => "Пояснение {$i}", 'uz' => "Izoh {$i}"],
            ])->create();

            $factory()->sentence()->withContent([
                'prompt' => ['ru' => "Предложение {$i}.", 'uz' => "Gap {$i}."],
                'fragments' => ['we', 'play', "game{$i}", 'today'],
                'accepted' => [[0, 1, 2, 3]],
                'display' => "We play game{$i} today.",
                'explanation' => ['ru' => "Порядок {$i}", 'uz' => "Tartib {$i}"],
            ])->create();

            $dictation = $factory()->dictation()->audioReady()->withContent([
                'text' => "Phrase number {$i}",
                'accepted' => ["Phrase number {$i}"],
                'translation' => ['ru' => "Фраза {$i}", 'uz' => "Ibora {$i}"],
                'explanation' => null,
            ])->create();
            $version = PracticeItemVersion::query()->findOrFail($dictation->published_version_id);
            Storage::disk('practice_audio')->put((string) app(PracticeAudioService::class)->pathFor($version), "mp3:{$i}");
        }
    }

    public static function service(): BattleService
    {
        return app(BattleService::class);
    }

    public static function reader(): BattleReader
    {
        return app(BattleReader::class);
    }

    public static function invite(Learner $by, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null, ?int $rematchOf = null): Battle
    {
        return self::service()->invite($by, $rematchOf === null ? $friendship->id : null, $mode, $course->slug, $lesson?->id, $rematchOf);
    }

    /** Invited and accepted: a room with both players present. */
    public static function room(Learner $by, Learner $friend, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null): Battle
    {
        $battle = self::invite($by, $friendship, $mode, $course, $lesson);
        self::service()->accept($friend, $battle->id);

        return self::fresh($battle);
    }

    /** Both ready: a countdown with `starts_at` three seconds ahead and round 1 with its deadline. */
    public static function countdown(Learner $by, Learner $friend, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null): Battle
    {
        $battle = self::room($by, $friend, $friendship, $mode, $course, $lesson);
        self::service()->ready($by, $battle->id);
        self::service()->ready($friend, $battle->id);

        return self::fresh($battle);
    }

    /** The match is on: the clock is moved to `starts_at` and the first poll turns the countdown into round 1 / answering. */
    public static function playing(Learner $by, Learner $friend, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null): Battle
    {
        $battle = self::countdown($by, $friend, $friendship, $mode, $course, $lesson);
        CarbonImmutable::setTestNow($battle->starts_at);
        self::reader()->poll($battle, $by, CarbonImmutable::now());

        $battle = self::fresh($battle);
        if ($battle->status !== BattleStatus::Playing) {
            throw new RuntimeException("The match did not start: {$battle->status->value}.");
        }

        return $battle;
    }

    /** Both players poll at `$time`: their presence is written, and whatever is due happens. */
    public static function bothPoll(Battle $battle, Learner $a, Learner $b, string $time): void
    {
        CarbonImmutable::setTestNow($time);
        foreach ([$a, $b] as $player) {
            self::reader()->poll(self::reader()->find($player, $battle->id), $player, CarbonImmutable::now());
        }
    }

    public static function fresh(Battle|int $battle): Battle
    {
        return Battle::query()->with('participants')->findOrFail($battle instanceof Battle ? $battle->id : $battle);
    }

    /**
     * The element of a round as the server keeps it (a test may read what no client gets).
     *
     * @return array<string, mixed>
     */
    public static function element(Battle $battle, int $round): array
    {
        $element = self::fresh($battle)->snapshot['elements'][$round - 1] ?? null;
        if (! is_array($element)) {
            throw new RuntimeException("No element for round {$round}.");
        }

        return $element;
    }

    /**
     * A right answer to the element, in the form of its mode.
     *
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public static function correct(array $element): array
    {
        return match ($element['kind']) {
            'collocation' => ['option_id' => $element['correct_ids'][0]],
            'sentence' => ['order' => self::orderOf($element, (string) $element['accepted'][0])],
            'dictation' => ['text' => (string) $element['display']],
            'pairs_round' => ['pairs' => $element['pairs']],
            default => throw new RuntimeException("Unknown kind {$element['kind']}."),
        };
    }

    /**
     * A wrong answer to the element: a wrong option, the fragments backwards, nonsense, the first two pairs swapped (2 of 4 right).
     *
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public static function wrong(array $element): array
    {
        switch ($element['kind']) {
            case 'collocation':
                foreach ($element['options'] as $option) {
                    if (! in_array($option['id'], $element['correct_ids'], true)) {
                        return ['option_id' => $option['id']];
                    }
                }
                throw new RuntimeException('Every option is right.');
            case 'sentence':
                return ['order' => array_reverse(self::orderOf($element, (string) $element['accepted'][0]))];
            case 'dictation':
                return ['text' => 'nonsense words here now'];
            case 'pairs_round':
                $pairs = $element['pairs'];
                $lefts = array_keys($pairs);
                [$pairs[$lefts[0]], $pairs[$lefts[1]]] = [$pairs[$lefts[1]], $pairs[$lefts[0]]];

                return ['pairs' => $pairs];
            default:
                throw new RuntimeException("Unknown kind {$element['kind']}.");
        }
    }

    /** @param array<string, mixed> $payload */
    public static function answer(Battle $battle, Learner $by, int $round, array $payload, ?string $requestId = null): BattleAnswerOutcome
    {
        return self::service()->answer($by, $battle->id, $requestId ?? (string) Str::uuid(), $round, $payload);
    }

    /**
     * The ids of the fragments in the order that spells `$accepted` (a normalized text).
     *
     * @param  array<string, mixed>  $element
     * @return list<string>
     */
    private static function orderOf(array $element, string $accepted): array
    {
        $remaining = $accepted;
        $used = [];
        $order = [];
        while ($remaining !== '') {
            foreach ($element['fragments'] as $index => $fragment) {
                if (isset($used[$index])) {
                    continue;
                }
                $text = PracticeTextNormalizer::normalize((string) $fragment['text']);
                if ($remaining === $text || str_starts_with($remaining, $text.' ')) {
                    $order[] = (string) $fragment['id'];
                    $used[$index] = true;
                    $remaining = ltrim(substr($remaining, strlen($text)));

                    continue 2;
                }
            }
            throw new RuntimeException("Cannot spell '{$accepted}' with the fragments.");
        }

        return $order;
    }
}
