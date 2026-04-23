<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Student;
use App\Models\TelegramGroup;
use Illuminate\Database\Seeder;

class DemoStudentsSeeder extends Seeder
{
    /** @var list<array{telegram_user_id:int,first_name:string,last_name:?string,username:?string}> */
    private const STUDENTS = [
        ['telegram_user_id' => 200_000_001, 'first_name' => 'Alice', 'last_name' => 'Johnson', 'username' => 'alice_j'],
        ['telegram_user_id' => 200_000_002, 'first_name' => 'Bob', 'last_name' => 'Smith', 'username' => 'bob_s'],
        ['telegram_user_id' => 200_000_003, 'first_name' => 'Charlie', 'last_name' => null, 'username' => null],
        ['telegram_user_id' => 200_000_004, 'first_name' => 'Diana', 'last_name' => 'Lee', 'username' => 'diana'],
        ['telegram_user_id' => 200_000_005, 'first_name' => 'Eve', 'last_name' => 'Brown', 'username' => 'eve_b'],
    ];

    public function run(): void
    {
        $group = TelegramGroup::where('chat_id', -1001234567890)->firstOrFail();

        foreach (self::STUDENTS as $s) {
            Student::updateOrCreate(
                [
                    'telegram_user_id' => $s['telegram_user_id'],
                    'telegram_group_id' => $group->id,
                ],
                [
                    'first_name' => $s['first_name'],
                    'last_name' => $s['last_name'],
                    'username' => $s['username'],
                    'language_code' => 'en',
                    'is_active' => true,
                ],
            );
        }
    }
}
