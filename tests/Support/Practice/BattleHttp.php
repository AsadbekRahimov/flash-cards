<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The battle as the TWA calls it (docs/16 §7.3, §7.4): every route of `/api/twa/practice/battles`, with the token of the player
 * who asks. The steps of a match over HTTP: an invitation, the acceptance, both ready, the first poll that starts it, the right /
 * wrong answer to a round built from the snapshot the server keeps (`BattleKit` reads it; no client ever gets it).
 *
 * Every call sends a fresh token, so two players alternate in one test without carrying each other's headers.
 */
final class BattleHttp
{
    public const BASE = '/api/twa/practice/battles';

    /** @param array<string, mixed> $body */
    public static function post(Learner $by, string $path, array $body = []): TestResponse
    {
        return test()->withHeaders(PracticeKit::headers($by))->postJson(self::BASE.$path, $body);
    }

    public static function get(Learner $by, string $path): TestResponse
    {
        return test()->withHeaders(PracticeKit::headers($by))->getJson(self::BASE.$path);
    }

    /**
     * The invitation to a friend, the way the setup screen sends it. A test makes more invitations than the 5 a minute that
     * `twa-battle-invite` allows (the clock stands still), so the limits of the one who invites are lifted first; the limiters
     * themselves are tested by BattleRateLimitTest with `post()`.
     */
    public static function invite(Learner $by, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null): TestResponse
    {
        PracticeKit::resetLimiters($by);

        return self::post($by, '', [
            'friendship_id' => $friendship->id,
            'mode' => $mode->value,
            'course' => $course->slug,
            'lesson_id' => $lesson?->id,
        ]);
    }

    /**
     * A rematch the way the TWA sends it: the battle that is over, and - whether they are needed or not - the mode, the course and the
     * lesson of it (and the friendship when it knows it).
     *
     * @param  array<string, mixed>  $with  what is sent along (or instead)
     */
    public static function rematch(Learner $by, int $sourceId, array $with = []): TestResponse
    {
        PracticeKit::resetLimiters($by);

        return self::post($by, '', ['rematch_of' => $sourceId, ...$with]);
    }

    public static function view(Learner $by, int $id): TestResponse
    {
        return self::get($by, "/{$id}");
    }

    public static function poll(Learner $by, int $id): TestResponse
    {
        return self::get($by, "/{$id}/poll");
    }

    public static function round(Learner $by, int $id, int $no): TestResponse
    {
        return self::get($by, "/{$id}/round?no={$no}");
    }

    public static function accept(Learner $by, int $id): TestResponse
    {
        return self::post($by, "/{$id}/accept");
    }

    public static function decline(Learner $by, int $id): TestResponse
    {
        return self::post($by, "/{$id}/decline");
    }

    public static function cancel(Learner $by, int $id): TestResponse
    {
        return self::post($by, "/{$id}/cancel");
    }

    public static function ready(Learner $by, int $id): TestResponse
    {
        return self::post($by, "/{$id}/ready");
    }

    public static function surrender(Learner $by, int $id): TestResponse
    {
        return self::post($by, "/{$id}/surrender", ['confirm' => true]);
    }

    /** @param array<string, mixed> $answer */
    public static function answer(Learner $by, int $id, int $round, array $answer, ?string $requestId = null): TestResponse
    {
        return self::post($by, "/{$id}/answer", ['request_id' => $requestId ?? (string) Str::uuid(), 'round' => $round, 'answer' => $answer]);
    }

    public static function audioFailed(Learner $by, int $id, string $elementId, ?string $requestId = null): TestResponse
    {
        return self::post($by, "/{$id}/audio-failed", ['request_id' => $requestId ?? (string) Str::uuid(), 'element_id' => $elementId]);
    }

    /** A clip of the match by the `audio_url` the server gave (a path of the API, as the TWA fetches it). */
    public static function audio(Learner $by, string $url): TestResponse
    {
        return test()->withHeaders(PracticeKit::headers($by))->get($url);
    }

    public static function options(Learner $by, Friendship $friendship, Course $course, ?Lesson $lesson = null): TestResponse
    {
        $lessonQuery = $lesson === null ? '' : "&lesson_id={$lesson->id}";

        return self::get($by, "/options?friendship_id={$friendship->id}&course={$course->slug}{$lessonQuery}");
    }

    /** An invitation of `$by` accepted by `$friend`: the id of a battle in the room. */
    public static function room(Learner $by, Learner $friend, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null): int
    {
        $id = (int) self::invite($by, $friendship, $mode, $course, $lesson)->assertCreated()->json('id');
        self::accept($friend, $id)->assertOk();

        return $id;
    }

    /** Both ready: a countdown. */
    public static function countdown(Learner $by, Learner $friend, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null): int
    {
        $id = self::room($by, $friend, $friendship, $mode, $course, $lesson);
        self::ready($by, $id)->assertOk();
        self::ready($friend, $id)->assertOk()->assertJsonPath('status', 'countdown');

        return $id;
    }

    /**
     * The match is on: the clock goes to `starts_at` and the first poll turns the countdown into round 1 / answering. The caller sets
     * the time (`CarbonImmutable::setTestNow`) before and after; here only the poll that starts it.
     */
    public static function playing(Learner $by, Learner $friend, Friendship $friendship, PracticeMode $mode, Course $course, ?Lesson $lesson = null): int
    {
        $id = self::countdown($by, $friend, $friendship, $mode, $course, $lesson);
        CarbonImmutable::setTestNow(BattleKit::fresh($id)->starts_at);
        self::poll($by, $id)->assertOk()->assertJsonPath('status', 'playing');

        return $id;
    }
}
