<?php

declare(strict_types=1);

use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
});

function signedInitData(int $telegramUserId, string $firstName = 'Alex', ?string $username = null, string $languageCode = 'ru'): string
{
    return InitDataValidator::sign('test-bot-token-1234567890', [
        'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
        'user' => json_encode(array_filter([
            'id' => $telegramUserId,
            'first_name' => $firstName,
            'username' => $username,
            'language_code' => $languageCode,
        ])),
        'auth_date' => (string) time(),
    ]);
}

it('creates a learner on the first /auth without any context and issues a token', function (): void {
    expect(Learner::count())->toBe(0);

    $response = $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555, 'Alex', 'alex_u'),
    ]);

    $learner = Learner::query()->where('telegram_user_id', 555)->sole();

    $response->assertOk()
        ->assertJsonStructure(['token', 'expires_in', 'learner' => ['id', 'first_name', 'locale', 'onboarding_completed']])
        ->assertJsonPath('learner.id', $learner->id)
        ->assertJsonPath('learner.first_name', 'Alex')
        ->assertJsonPath('learner.locale', 'ru')
        ->assertJsonPath('learner.onboarding_completed', false);

    expect($learner->username)->toBe('alex_u')
        ->and($learner->onboarding_state)->toBe('new')
        ->and($learner->last_seen_at)->not->toBeNull();
});

it('puts the learner id into the token as sub with version 2 and no group claim', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);

    $token = (string) $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])->json('token');

    $claims = (array) JWT::decode($token, new Key((string) config('twa.jwt.secret'), 'HS256'));

    expect($claims['sub'])->toBe((string) $learner->id)
        ->and($claims['v'])->toBe(2)
        ->and($claims)->not->toHaveKey('gid');
});

it('returns the same learner on a repeated /auth and refreshes name and username', function (): void {
    $first = $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555, 'Alex', 'old_name')])
        ->assertOk()->json('learner.id');

    $second = $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555, 'Alexander', 'new_name')])
        ->assertOk()
        ->assertJsonPath('learner.first_name', 'Alexander')
        ->json('learner.id');

    expect($second)->toBe($first)
        ->and(Learner::count())->toBe(1)
        ->and(Learner::query()->sole()->username)->toBe('new_name');
});

it('does not touch interface_locale of a learner who finished onboarding', function (): void {
    Learner::factory()->onboarded()->create(['telegram_user_id' => 555, 'interface_locale' => 'uz']);

    $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555, languageCode: 'ru')])
        ->assertOk()
        ->assertJsonPath('learner.locale', 'uz');
});

it('derives the locale of a new learner from the Telegram language', function (): void {
    $this->postJson('/api/twa/auth', ['init_data' => signedInitData(556, languageCode: 'uz')])
        ->assertOk()
        ->assertJsonPath('learner.locale', 'uz');
});

it('logs in a learner without any group membership', function (): void {
    Learner::factory()->create(['telegram_user_id' => 555]);

    $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])->assertOk();
});

it('never answers 409 session_context_required: a learner in several groups logs in with or without context', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    $groups = TelegramGroup::factory()->count(2)->create(['status' => 'active']);
    foreach ($groups as $group) {
        GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
    }
    $session = TrainingSession::factory()->create(['telegram_group_id' => $groups[1]->id]);

    $withoutContext = $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])
        ->assertOk()->json('learner.id');
    $withContext = $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555),
        'context' => ['type' => 'training', 'session_id' => $session->id],
    ])->assertOk()->json('learner.id');

    expect($withoutContext)->toBe($learner->id)
        ->and($withContext)->toBe($learner->id);
});

it('marks a learner created from a group session context as twa_group and ignores an unknown session', function (): void {
    $session = TrainingSession::factory()->create();

    $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(601),
        'context' => ['type' => 'training', 'session_id' => $session->id],
    ])->assertOk();
    $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(602),
        'context' => ['type' => 'exam', 'session_id' => 999_999],
    ])->assertOk();

    expect(Learner::where('telegram_user_id', 601)->value('acquisition_source'))->toBe('twa_group')
        ->and(Learner::where('telegram_user_id', 602)->value('acquisition_source'))->toBeNull();
});

it('does not overwrite the acquisition source of an existing learner', function (): void {
    Learner::factory()->create(['telegram_user_id' => 555, 'acquisition_source' => 'src:blog']);
    $session = TrainingSession::factory()->create();

    $this->postJson('/api/twa/auth', [
        'init_data' => signedInitData(555),
        'context' => ['type' => 'training', 'session_id' => $session->id],
    ])->assertOk();

    expect(Learner::query()->sole()->acquisition_source)->toBe('src:blog');
});

it('revives a soft-deleted learner instead of violating the unique telegram_user_id', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    $learner->delete();

    $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])
        ->assertOk()
        ->assertJsonPath('learner.id', $learner->id);

    expect(Learner::count())->toBe(1);
});

it('returns 401 learner_blocked from /auth for a banned learner', function (): void {
    Learner::factory()->create(['telegram_user_id' => 555, 'blocked_at' => now()]);

    $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'learner_blocked');
});

it('returns 401 on invalid init_data', function (): void {
    $this->postJson('/api/twa/auth', ['init_data' => 'garbage'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_init_data');

    expect(Learner::count())->toBe(0);
});

it('returns 401 for tampered hash', function (): void {
    $valid = signedInitData(555);
    $tampered = preg_replace('/hash=[a-f0-9]+/', 'hash='.str_repeat('0', 64), $valid);

    $this->postJson('/api/twa/auth', ['init_data' => $tampered])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_init_data');

    expect(Learner::count())->toBe(0);
});

it('requires the init_data field', function (): void {
    $this->postJson('/api/twa/auth', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['init_data']);
});

it('accepts the issued token on protected routes', function (): void {
    $token = (string) $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])->json('token');

    $this->withToken($token)
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('learner.first_name', 'Alex');
});

it('rejects a request without a bearer token', function (): void {
    $this->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'missing_token');
});

it('rejects a token of the old format (no version claim) with 401 invalid_token', function (): void {
    // The sub of an old token is a students.id: it must never be read as a learner id.
    $learner = Learner::factory()->create();
    $legacy = JWT::encode([
        'iss' => 'lexiflow',
        'sub' => (string) $learner->id,
        'gid' => 7,
        'iat' => time(),
        'exp' => time() + 300,
    ], (string) config('twa.jwt.secret'), 'HS256');

    $this->withToken($legacy)
        ->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_token');
});

it('rejects a token with another version', function (): void {
    $learner = Learner::factory()->create();
    $token = JWT::encode([
        'iss' => 'lexiflow',
        'sub' => (string) $learner->id,
        'v' => 1,
        'iat' => time(),
        'exp' => time() + 300,
    ], (string) config('twa.jwt.secret'), 'HS256');

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_token');
});

it('rejects an expired token', function (): void {
    $learner = Learner::factory()->create();
    $token = JWT::encode([
        'iss' => 'lexiflow',
        'sub' => (string) $learner->id,
        'v' => 2,
        'iat' => time() - 1000,
        'exp' => time() - 100,
    ], (string) config('twa.jwt.secret'), 'HS256');

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_token');
});

it('returns 401 learner_not_found for a token whose learner does not exist', function (): void {
    $token = app(JwtService::class)->issue(987_654)['token'];

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'learner_not_found');
});

it('returns 401 learner_not_found once the learner is soft-deleted', function (): void {
    $learner = Learner::factory()->create();
    $token = app(JwtService::class)->issue($learner->id)['token'];

    $learner->delete();

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'learner_not_found');
});

it('invalidates an issued token when the learner is blocked', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    $token = (string) $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])->json('token');

    $this->withToken($token)->getJson('/api/twa/me')->assertOk();

    $learner->update(['blocked_at' => now()]);

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'learner_blocked');
});

it('keeps the token valid when a group is deactivated: the group just disappears from /me', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    Learner::factory()->memberOf($group)->create(['telegram_user_id' => 555]);

    $token = (string) $this->postJson('/api/twa/auth', ['init_data' => signedInitData(555)])->json('token');

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('groups.0.id', $group->id);

    $group->update(['status' => 'disabled']);

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('groups', []);
});
