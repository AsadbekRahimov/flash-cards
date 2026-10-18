<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Course;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoGroupSeeder extends Seeder
{
    public function run(): void
    {
        // Needs DemoContentSeeder to have run first (see DatabaseSeeder).
        $courseId = Course::query()
            ->where('slug', (string) config('content.default_course_slug'))
            ->value('id');

        $group = TelegramGroup::updateOrCreate(
            ['chat_id' => -1001234567890],
            [
                'title' => 'Demo English Class',
                'status' => 'active',
                'course_id' => $courseId,
                'meta' => ['member_count' => 5, 'type' => 'supergroup'],
            ],
        );

        $teacher = User::where('email', 'teacher@local')->first();
        if ($teacher !== null) {
            $teacher->telegramGroups()->syncWithoutDetaching([
                $group->id => ['is_primary' => true],
            ]);
        }
    }
}
