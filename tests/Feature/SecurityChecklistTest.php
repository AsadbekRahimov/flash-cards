<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Student;
use App\Models\TelegramGroup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
    RateLimiter::clear('twa-auth');
});

it('rate-limits /api/twa/auth at 20 requests per minute per IP', function (): void {
    $server = ['REMOTE_ADDR' => '203.0.113.10'];

    for ($i = 0; $i < 20; $i++) {
        $response = $this->withServerVariables($server)
            ->postJson('/api/twa/auth', ['init_data' => 'garbage']);
        $response->assertStatus(401);
    }

    $this->withServerVariables($server)
        ->postJson('/api/twa/auth', ['init_data' => 'garbage'])
        ->assertStatus(429);
});

it('escapes XSS payload from JSON import when rendered via Blade', function (): void {
    $xss = '<script>alert(1)</script>';
    $rendered = Blade::render('{{ $v }}', ['v' => $xss]);

    expect($rendered)
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('is idempotent on repeated new_chat_members updates (race-safe)', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -8888, 'status' => 'active']);

    $update = [
        'message' => [
            'chat' => ['id' => -8888, 'type' => 'supergroup'],
            'new_chat_members' => [
                ['id' => 42, 'first_name' => 'Alice', 'username' => 'alice', 'is_bot' => false],
            ],
        ],
    ];

    app(TelegramDispatcher::class)->dispatch($update);
    app(TelegramDispatcher::class)->dispatch($update);
    app(TelegramDispatcher::class)->dispatch($update);

    expect(Student::where('telegram_group_id', $group->id)
        ->where('telegram_user_id', 42)
        ->count())->toBe(1);
});

it('enforces unique (telegram_user_id, telegram_group_id) index at DB level', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);

    Student::factory()->create([
        'telegram_group_id' => $group->id,
        'telegram_user_id' => 100,
    ]);

    expect(fn () => Student::factory()->create([
        'telegram_group_id' => $group->id,
        'telegram_user_id' => 100,
    ]))->toThrow(QueryException::class);
});
