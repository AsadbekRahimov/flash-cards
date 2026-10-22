<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single rule "may this user run teacher actions of the bot in this group":
 * the account is active (`users.is_active`) AND is assigned to the group (`teacher_groups`).
 * The group's own status stays a separate check (it has its own error: group_inactive).
 */
class TeacherAuthority
{
    public function canTeach(User $user, TelegramGroup $group): bool
    {
        if ($user->is_active !== true) {
            return false;
        }

        return DB::table('teacher_groups')
            ->where('user_id', $user->id)
            ->where('telegram_group_id', $group->id)
            ->exists();
    }
}
