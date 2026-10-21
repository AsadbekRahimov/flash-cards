<?php

declare(strict_types=1);

/*
 * GET /api/twa/achievements and POST /api/twa/achievements/{key}/shared (docs/04 A.15, docs/13 §7.4):
 * the whole catalog in the request language, the progress of the locked ones, the share text and link,
 * the idempotent "shared" mark, and what a stranger or a bad key gets.
 */

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Twa\Services\JwtService;
use App\Models\Achievement;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot']);
});

/** @param  array<string, mixed>  $body */
function twAch(Learner $learner, string $method = 'GET', string $path = '/api/twa/achievements', array $body = []): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $path, $body);
}

it('requires a TWA token', function (): void {
    $this->getJson('/api/twa/achievements')->assertUnauthorized();
    $this->postJson('/api/twa/achievements/streak_7/shared')->assertUnauthorized();
});

it('lists the whole catalog with the exact JSON shape, locked ones with their progress', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 3, 'streak_best' => 3]);
    WordRepetition::factory()->count(40)->create(['learner_id' => $learner->id, 'repetitions' => 1]);
    Achievement::factory()->key(AchievementKey::FirstSession)->create(['learner_id' => $learner->id]);

    $response = twAch($learner)->assertOk()->assertExactJsonStructure([
        'unlocked_count', 'total', 'share_link',
        'items' => ['*' => ['key', 'title', 'description', 'unlocked_at', 'shared_at', 'progress', 'share_text']],
    ]);

    $items = collect($response->json('items'))->keyBy('key');
    expect($response->json('unlocked_count'))->toBe(1)
        ->and($response->json('total'))->toBe(count(AchievementKey::cases()))
        ->and($response->json('items.0.key'))->toBe('first_session')
        ->and($response->json('share_link'))->toStartWith('https://t.me/LexiFlowBot?start=ref_')
        ->and($items['first_session']['title'])->toBe('Первый шаг')
        ->and($items['first_session']['unlocked_at'])->not->toBeNull()
        ->and($items['first_session']['share_text'])->toBe('Я получил(а) достижение «Первый шаг» в LexiFlow! Учим английский в Telegram.')
        ->and($items['first_session']['progress'])->toBeNull()
        ->and($items['streak_7']['unlocked_at'])->toBeNull()
        ->and($items['streak_7']['share_text'])->toBeNull()
        ->and($items['streak_7']['progress'])->toBe(['current' => 3, 'target' => 7])
        ->and($items['words_100']['progress'])->toBe(['current' => 40, 'target' => 100])
        ->and($items['league_top1']['progress'])->toBeNull();
});

it('answers in the language of the learner', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);

    $items = collect(twAch($learner)->assertOk()->json('items'))->keyBy('key');

    expect($items['words_100']['title'])->toBe("100 ta so'z")
        ->and($items['streak_7']['description'])->toBe('Bir hafta tanaffussiz mashq');
});

it('has no share link while the bot username is not configured', function (): void {
    config(['telegram.bot_username' => '']);

    twAch(Learner::factory()->create())->assertOk()->assertJsonPath('share_link', null);
});

it('shows only the achievements of the caller', function (): void {
    $mine = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => Learner::factory()->create()->id]);

    twAch($mine)->assertOk()->assertJsonPath('unlocked_count', 0);
});

it('marks an unlocked achievement as shared once and answers with the refreshed list', function (): void {
    $learner = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);

    $first = twAch($learner, 'POST', '/api/twa/achievements/streak_7/shared')->assertOk();
    twAch($learner, 'POST', '/api/twa/achievements/streak_7/shared')->assertOk();

    $item = collect($first->json('items'))->firstWhere('key', 'streak_7');
    expect($item['shared_at'])->not->toBeNull()
        ->and(ProductEvent::query()->where('name', 'achievement.shared')->count())->toBe(1)
        ->and(Achievement::query()->firstOrFail()->shared_at)->not->toBeNull();
});

it('answers 404 for a key that is locked, unknown or somebody else\'s', function (): void {
    $learner = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Words100)->create(['learner_id' => Learner::factory()->create()->id]);

    twAch($learner, 'POST', '/api/twa/achievements/words_100/shared')->assertNotFound()->assertJsonPath('error.code', 'achievement_not_found');
    twAch($learner, 'POST', '/api/twa/achievements/no_such_key/shared')->assertNotFound()->assertJsonPath('error.code', 'achievement_not_found');
    twAch($learner, 'POST', '/api/twa/achievements/BAD-KEY/shared')->assertNotFound();

    expect(ProductEvent::query()->where('name', 'achievement.shared')->count())->toBe(0);
});
