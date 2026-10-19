<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Database\Seeder;

/**
 * Five onboarded learners in the demo group; the first one also studies in a
 * second group, which shows "one person, one progress" (a single
 * `word_repetitions` row per word, however many groups).
 */
class DemoLearnersSeeder extends Seeder
{
    /** @var list<array{telegram_user_id:int,first_name:string,last_name:?string,username:?string}> */
    private const LEARNERS = [
        ['telegram_user_id' => 200_000_001, 'first_name' => 'Alice', 'last_name' => 'Johnson', 'username' => 'alice_j'],
        ['telegram_user_id' => 200_000_002, 'first_name' => 'Bob', 'last_name' => 'Smith', 'username' => 'bob_s'],
        ['telegram_user_id' => 200_000_003, 'first_name' => 'Charlie', 'last_name' => null, 'username' => null],
        ['telegram_user_id' => 200_000_004, 'first_name' => 'Diana', 'last_name' => 'Lee', 'username' => 'diana'],
        ['telegram_user_id' => 200_000_005, 'first_name' => 'Eve', 'last_name' => 'Brown', 'username' => 'eve_b'],
    ];

    public function run(): void
    {
        $memberships = app(GroupMembershipService::class);

        $group = TelegramGroup::where('chat_id', -1001234567890)->firstOrFail();

        $courseId = app(CourseCatalogService::class)->defaultCourse()->id;

        $secondGroup = TelegramGroup::updateOrCreate(
            ['chat_id' => -1001234567891],
            [
                'title' => 'Demo English Class B',
                'status' => 'active',
                'course_id' => $courseId,
                'meta' => ['member_count' => 1, 'type' => 'supergroup'],
            ],
        );

        foreach (self::LEARNERS as $index => $l) {
            $learner = Learner::updateOrCreate(
                ['telegram_user_id' => $l['telegram_user_id']],
                [
                    'first_name' => $l['first_name'],
                    'last_name' => $l['last_name'],
                    'username' => $l['username'],
                    'language_code' => 'en',
                    'interface_locale' => 'ru',
                    'timezone' => (string) config('learners.default_timezone'),
                    'daily_goal' => (int) config('learners.default_daily_goal'),
                    'reminder_time' => (string) config('learners.default_reminder_time'),
                    'onboarding_state' => 'done',
                    'onboarding_completed_at' => now(),
                    'active_course_id' => $courseId,
                    'acquisition_source' => 'group',
                ],
            );

            $memberships->join($learner, $group, 'seed');

            if ($index === 0) {
                $memberships->join($learner, $secondGroup, 'seed');
            }
        }
    }
}
