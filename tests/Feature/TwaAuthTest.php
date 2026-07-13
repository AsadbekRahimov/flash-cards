<?php

declare(strict_types=1);

use App\Domain\Twa\Services\InitDataValidator;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
});

function signedInitData(int $telegramUserId, string $firstName = 'Alex'): string
{
    return InitDataValidator::sign('test-bot-token-1234567890', [
        'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
        'user' => json_encode([
            'id' => $telegramUserId,
            'first_name' => $firstName,
            'language_code' => 'ru',
        ]),
        'auth_date' => (string) time(),
    ]);
}

it('issues a JWT for a known active student', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create([
        'telegram_group_id' => $group->id,
        'telegram_user_id' => 555,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555),
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'expires_in', 'student' => ['id', 'first_name', 'telegram_group_id']]);

    expect($response->json('student.id'))->toBe($student->id);
});

it('returns 401 on invalid init_data', function (): void {
    $this->postJson('/api/twa/auth', ['init_data' => 'garbage'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_init_data');
});

it('returns 401 for tampered hash', function (): void {
    $valid = signedInitData(555);
    $tampered = preg_replace('/hash=[a-f0-9]+/', 'hash='.str_repeat('0', 64), $valid);

    $this->postJson('/api/twa/auth', ['init_data' => $tampered])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_init_data');
});

it('returns 403 when the student is not registered', function (): void {
    $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(999_999),
    ])->assertStatus(403)->assertJsonPath('error.code', 'student_not_found');
});

it('returns 403 when the student group is inactive', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'pending']);
    Student::factory()->create([
        'telegram_group_id' => $group->id,
        'telegram_user_id' => 555,
        'is_active' => true,
    ]);

    $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555),
    ])->assertStatus(403)->assertJsonPath('error.code', 'group_inactive');
});

it('requires the init_data field', function (): void {
    $this->postJson('/api/twa/auth', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['init_data']);
});

it('selects the membership that belongs to the requested session', function (): void {
    $firstGroup = TelegramGroup::factory()->create(['status' => 'active']);
    $secondGroup = TelegramGroup::factory()->create(['status' => 'active']);
    Student::factory()->create([
        'telegram_group_id' => $firstGroup->id,
        'telegram_user_id' => 555,
        'is_active' => true,
    ]);
    $expectedStudent = Student::factory()->create([
        'telegram_group_id' => $secondGroup->id,
        'telegram_user_id' => 555,
        'is_active' => true,
    ]);
    $session = TrainingSession::factory()->create([
        'telegram_group_id' => $secondGroup->id,
    ]);

    $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555),
        'context' => [
            'type' => 'training',
            'session_id' => $session->id,
        ],
    ])->assertOk()
        ->assertJsonPath('student.id', $expectedStudent->id)
        ->assertJsonPath('student.telegram_group_id', $secondGroup->id);
});

it('requires session context when a Telegram account belongs to multiple active groups', function (): void {
    $groups = TelegramGroup::factory()->count(2)->create(['status' => 'active']);

    foreach ($groups as $group) {
        Student::factory()->create([
            'telegram_group_id' => $group->id,
            'telegram_user_id' => 555,
            'is_active' => true,
        ]);
    }

    $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555),
    ])->assertStatus(409)->assertJsonPath('error.code', 'session_context_required');
});

it('invalidates an issued JWT when its group is deactivated', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    Student::factory()->create([
        'telegram_group_id' => $group->id,
        'telegram_user_id' => 555,
        'is_active' => true,
    ]);

    $token = $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555),
    ])->assertOk()->json('token');

    $group->update(['status' => 'disabled']);

    $this->withToken((string) $token)
        ->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'group_inactive');
});
