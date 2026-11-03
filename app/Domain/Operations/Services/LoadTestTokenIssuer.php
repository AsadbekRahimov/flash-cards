<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use RuntimeException;

/**
 * Test learners and their JWTs for the k6 scenarios (`load:tokens`, tests/load/k6). Every virtual learner needs a token of its
 * own: the per-learner limiters (`twa-api`, `twa-review`, ...) would otherwise cap the whole test at 60 requests a minute.
 * Learner N has telegram_user_id = BASE + N, so a repeat run takes the same rows instead of piling up new ones.
 * Never in production: the tokens are real, signed with the live secret.
 */
final class LoadTestTokenIssuer
{
    /** Distinct from the perf:seed range (7 000 000 000 + N). */
    public const TELEGRAM_USER_BASE = 8_000_000_000;

    public const MAX_LEARNERS = 5000;

    public const MAX_TTL_SECONDS = 86_400;

    /**
     * @return array{course: string|null, expires_at: int, learners: list<array{learner_id: int, telegram_user_id: int, token: string}>}
     */
    public function issue(int $count, int $ttlSeconds, ?string $courseSlug = null): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Load-test tokens are never issued in production.');
        }

        if ($count < 1 || $count > self::MAX_LEARNERS) {
            throw new RuntimeException(sprintf('The number of learners must be between 1 and %d.', self::MAX_LEARNERS));
        }

        if ($ttlSeconds < 60 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new RuntimeException(sprintf('The token lifetime must be between 60 and %d seconds.', self::MAX_TTL_SECONDS));
        }

        $secret = (string) config('twa.jwt.secret');
        if ($secret === '') {
            throw new RuntimeException('TWA_JWT_SECRET is not set.');
        }

        $course = $this->course($courseSlug);

        $jwt = new JwtService(
            secret: $secret,
            ttl: $ttlSeconds,
            alg: (string) config('twa.jwt.alg', 'HS256'),
            issuer: (string) config('twa.jwt.iss', 'lexiflow'),
        );

        $learners = [];
        $expiresAt = 0;

        for ($n = 1; $n <= $count; $n++) {
            $telegramId = self::TELEGRAM_USER_BASE + $n;

            $learner = Learner::query()->firstOrCreate(['telegram_user_id' => $telegramId], [
                'first_name' => 'Load'.$n,
                'language_code' => 'ru',
                'interface_locale' => 'ru',
                'timezone' => (string) config('learners.default_timezone'),
                'daily_goal' => (int) config('learners.default_daily_goal'),
                'reminder_time' => (string) config('learners.default_reminder_time'),
                'onboarding_state' => 'done',
                'onboarding_completed_at' => now(),
                'active_course_id' => $course?->id,
                'meta' => [],
            ]);

            // A test learner left from an earlier run, blocked or on another course, gets back into shape.
            if ($learner->blocked_at !== null || ($course !== null && $learner->active_course_id !== $course->id)) {
                $learner->forceFill([
                    'blocked_at' => null,
                    'active_course_id' => $course === null ? $learner->active_course_id : $course->id,
                ])->save();
            }

            $issued = $jwt->issue((int) $learner->id);
            $expiresAt = $issued['expires_at'];

            $learners[] = [
                'learner_id' => (int) $learner->id,
                'telegram_user_id' => $telegramId,
                'token' => $issued['token'],
            ];
        }

        return [
            'course' => $course?->slug,
            'expires_at' => $expiresAt,
            'learners' => $learners,
        ];
    }

    private function course(?string $slug): ?Course
    {
        $query = Course::query()->available()->orderBy('id');

        if ($slug === null || $slug === '') {
            return $query->first();
        }

        return $query->where('slug', $slug)->first()
            ?? throw new RuntimeException('No published course with the slug "'.$slug.'".');
    }
}
