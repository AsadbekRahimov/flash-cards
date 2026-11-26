<?php

declare(strict_types=1);

namespace Tests\Support\Cup;

use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Models\Course;
use App\Models\Cup;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;

/**
 * The world of the cup tests (docs/33): a pilot-free switch (`cup.access = all`), a group whose bot is an administrator, a public A2
 * course of the platform with words translated into every supported locale, members answered by the fake Telegram client per user.
 */
final class CupKit
{
    public static function open(): void
    {
        config(['cup.access' => 'all', 'cup.timezone' => 'Asia/Tashkent', 'telegram.bot_token' => '5550001:TEST-TOKEN']);
    }

    public static function telegram(): FakeTelegramClient
    {
        $client = app(TelegramClient::class);
        if (! $client instanceof FakeTelegramClient) {
            throw new \LogicException('The tests of the cup need the fake Telegram client.');
        }

        return $client;
    }

    /** @param array<string, mixed> $meta */
    public static function group(array $meta = []): TelegramGroup
    {
        return TelegramGroup::factory()->pending()->create(['bot_status' => 'administrator', 'meta' => $meta]);
    }

    /**
     * A published public A2 course of the platform with `$count` words in two lessons, each with a `uz` translation (ru is the column).
     */
    public static function course(int $count = 90, string $level = 'A2', string $slug = 'cup-a2'): Course
    {
        $course = Course::factory()->published()->create(['slug' => $slug, 'level' => $level, 'position' => 1]);
        $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
        $lessons = [
            Lesson::factory()->for($stage)->create(['number' => 1]),
            Lesson::factory()->for($stage)->create(['number' => 2]),
        ];

        $now = now();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $tag = self::letters($i);
            $rows[] = [
                'lesson_id' => $lessons[$i % 2]->id,
                'word' => "cup{$tag}",
                'translation' => "слово {$tag}",
                'example' => "I like cup{$tag} a lot.",
                'is_active' => true,
                'meta' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('words')->insert($chunk);
        }
        $translations = [];
        foreach (DB::table('words')->whereIn('lesson_id', [$lessons[0]->id, $lessons[1]->id])->get(['id', 'word']) as $word) {
            $translations[] = ['word_id' => $word->id, 'locale' => 'uz', 'translation' => 'soz '.substr((string) $word->word, 3), 'source' => 'import', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($translations, 200) as $chunk) {
            DB::table('word_translations')->insert($chunk);
        }

        return $course;
    }

    /** The learner is in the chat right now (fresh getChatMember answers `member`). */
    public static function member(Learner $learner, string $status = 'member', bool $isMember = true): void
    {
        self::telegram()->chatMembersByUser[(int) $learner->telegram_user_id] = new ChatMemberInfo($status, $isMember);
    }

    /** getChatMember for the learner throws `$e`. */
    public static function memberFails(Learner $learner, \Throwable $e): void
    {
        self::telegram()->chatMembersByUser[(int) $learner->telegram_user_id] = $e;
    }

    /** A chat administrator (a Telegram user id, no learner needed). */
    public static function admin(int $telegramUserId = 777_000_001): int
    {
        self::telegram()->chatMembersByUser[$telegramUserId] = new ChatMemberInfo('administrator', true);

        return $telegramUserId;
    }

    /** A draft of the group, published by an administrator at `$now` (day 1 starts at the next Tashkent midnight ≥ 6 h away). */
    public static function scheduled(TelegramGroup $group, CarbonImmutable $now): Cup
    {
        $admin = self::admin();
        $lifecycle = app(CupLifecycleService::class);
        $cup = $lifecycle->propose($group, $admin, null, $now)->cup;

        return $lifecycle->publish($cup, $admin, null, null, $now);
    }

    /**
     * A running cup with `$players` members who joined before the start; `now` is moved to the first minute of day 1.
     *
     * @return array{cup: Cup, learners: list<Learner>, start: CarbonImmutable}
     */
    public static function running(int $players = 2, ?TelegramGroup $group = null, ?CarbonImmutable $publishedAt = null): array
    {
        $group ??= self::group();
        $publishedAt ??= CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'); // 11:00 in Tashkent
        CarbonImmutable::setTestNow($publishedAt);
        $cup = self::scheduled($group, $publishedAt);

        $learners = [];
        for ($i = 0; $i < $players; $i++) {
            $learner = Learner::factory()->create(['first_name' => "Player {$i}", 'interface_locale' => $i % 2 === 0 ? 'ru' : 'uz']);
            self::member($learner);
            app(CupParticipationService::class)->join($cup->fresh(['telegramGroup']) ?? $cup, $learner, null, $publishedAt);
            $learners[] = $learner;
        }

        $start = CarbonImmutable::instance($cup->starts_at ?? throw new \LogicException('not scheduled'))->addMinute();
        CarbonImmutable::setTestNow($start);
        app(CupScheduler::class)->advance($start);

        return ['cup' => Cup::query()->with('telegramGroup')->findOrFail($cup->id), 'learners' => $learners, 'start' => $start];
    }

    /** The element of the snapshot (with its secrets) by id - a test may read the right answer. */
    public static function element(int $cupDayId, string $elementId): array
    {
        $snapshot = json_decode((string) DB::table('cup_days')->where('id', $cupDayId)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($snapshot['elements'] as $element) {
            if ($element['id'] === $elementId) {
                return $element;
            }
        }

        throw new \RuntimeException("No element {$elementId} on the day {$cupDayId}.");
    }

    /**
     * The right answer to a task of the snapshot.
     *
     * @return array<string, mixed>
     */
    public static function rightAnswer(int $cupDayId, string $elementId): array
    {
        $element = self::element($cupDayId, $elementId);

        return match ($element['kind']) {
            'spell' => ['text' => $element['expected']],
            'gap' => ['text' => $element['correct_text']],
            default => ['option_id' => $element['correct_option_id']],
        };
    }

    /**
     * A wrong answer to a task of the snapshot.
     *
     * @return array<string, mixed>
     */
    public static function wrongAnswer(int $cupDayId, string $elementId): array
    {
        $element = self::element($cupDayId, $elementId);
        if (in_array($element['kind'], ['spell', 'gap'], true)) {
            return ['text' => 'zzzzzzzzzzzz'];
        }
        foreach ($element['options'] as $option) {
            if ($option['id'] !== $element['correct_option_id']) {
                return ['option_id' => $option['id']];
            }
        }

        throw new \RuntimeException('No wrong option.');
    }

    private static function letters(int $n): string
    {
        $s = '';
        do {
            $s = chr(ord('a') + $n % 26).$s;
            $n = intdiv($n, 26);
        } while ($n > 0);

        return str_pad($s, 3, 'q', STR_PAD_LEFT);
    }
}
