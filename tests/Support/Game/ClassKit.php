<?php

declare(strict_types=1);

namespace Tests\Support\Game;

use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Worlds for the tests of the class caravan wiring (G2.9b: the bot, `/org`, `GET /game/class`): a center with a live subscription,
 * an active group of it on a course, its members and its teacher. The words of the boss are not needed here (the core is pinned by
 * ClassCaravanTest), so a world has none.
 */
final class ClassKit
{
    /**
     * @return array{organization: Organization, group: TelegramGroup, course: Course, teacher: User, learners: list<Learner>}
     */
    public static function world(int $members = 2, ?Organization $organization = null, int $chatId = -1001234, string $title = 'Группа A1', ?int $teacherTelegramId = 777): array
    {
        $organization ??= self::center();
        $course = Course::factory()->published()->create();
        $group = TelegramGroup::factory()->create([
            'chat_id' => $chatId,
            'organization_id' => $organization->id,
            'course_id' => $course->id,
            'title' => $title,
            'status' => 'active',
        ]);

        $learners = [];
        for ($i = 0; $i < $members; $i++) {
            $learners[] = $learner = Learner::factory()->create(['first_name' => "Ученик {$i}"]);
            GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
        }

        return ['organization' => $organization, 'group' => $group, 'course' => $course, 'teacher' => self::teacher($group, $teacherTelegramId), 'learners' => $learners];
    }

    /** A center with a live subscription: the condition of `class.centers_only`. */
    public static function center(): Organization
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->forOrganization($organization)->create();

        return $organization;
    }

    /** A teacher of the group (`teacher_groups`) with a Telegram account. */
    public static function teacher(TelegramGroup $group, ?int $telegramUserId = 777): User
    {
        $user = User::factory()->create(['is_active' => true, 'telegram_user_id' => $telegramUserId]);
        DB::table('teacher_groups')->insert([
            'user_id' => $user->id,
            'telegram_group_id' => $group->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
