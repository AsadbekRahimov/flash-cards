<?php

declare(strict_types=1);

/*
 * POST /api/twa/share/story (docs/40 §2.4, docs/04 A.26): the exact contract `{media_url, text, widget_link}`, the subject taken only
 * from the learner's own row (the series of the JWT's learner, their own achievement), the codes the Mini App falls back on (403, 404,
 * 422, 429), the caption within 200 characters with the invite link, the statement budget, and the client's story events.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Twa\Services\JwtService;
use App\Models\Achievement;
use App\Models\Learner;
use App\Models\ProductEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public', ['url' => 'https://muhrla.test/storage']);
    config([
        'app.brand' => 'Muhrla',
        'app.brand_logo' => null,
        'telegram.bot_username' => 'MuhrlaBot',
        'achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art',
        'share.enabled' => true,
        'share.prepare.per_minute' => 20,
        'share.prepare.per_day' => 100,
    ]);
});

/** @param  array<string, mixed>  $body */
function storyPost(Learner $learner, array $body, string $path = '/api/twa/share/story'): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson($path, $body);
}

it('requires a TWA token', function (): void {
    $this->postJson('/api/twa/share/story', ['kind' => 'streak'])->assertUnauthorized();
});

it('answers exactly {media_url, text, widget_link} for the series of the learner, with the invite link and no name', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru', 'streak_current' => 7, 'daily_goal' => 20, 'first_name' => 'Азиз']);

    $response = storyPost($learner, ['kind' => 'streak'])->assertOk();
    $code = $learner->fresh()?->referral_code;
    $link = 'https://t.me/MuhrlaBot?start=ref_'.$code;

    $response->assertExactJson([
        'media_url' => $response->json('media_url'),
        'text' => '7 дней подряд в Muhrla · '.$link,
        'widget_link' => ['url' => $link, 'name' => 'Muhrla'],
    ]);
    $url = (string) $response->json('media_url');
    expect($url)->toStartWith('https://muhrla.test/storage/share-cards/story_streak_7-ru-')
        ->and(preg_match('#^/storage/share-cards/[a-z0-9_]{1,40}-(ru|uz)-[0-9a-f]{20}\.jpg$#', (string) parse_url($url, PHP_URL_PATH)))->toBe(1)
        ->and(mb_strlen((string) $response->json('text')))->toBeLessThanOrEqual(200)
        ->and((string) $response->json('text'))->not->toContain('Азиз');

    $file = Storage::disk('public')->path('share-cards/'.basename((string) parse_url($url, PHP_URL_PATH)));
    expect(is_file($file))->toBeTrue()->and(getimagesize($file)[1] ?? null)->toBe(1920);

    expect(ProductEvent::query()->where('name', ProductEventName::SharePrepared->value)->value('props'))
        ->toEqual(['kind' => 'streak', 'subject' => null, 'via' => 'story']);
});

it('speaks the language of the learner: the Uzbek caption and card for a uz learner', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'uz', 'streak_current' => 3, 'daily_goal' => 10]);

    $response = storyPost($learner, ['kind' => 'streak'])->assertOk();

    expect((string) $response->json('text'))->toStartWith("Muhrla'da 3 kun ketma-ket · https://t.me/MuhrlaBot?start=ref_")
        ->and((string) $response->json('media_url'))->toContain('/story_streak_3-uz-');
});

it('answers 404 share_subject_not_found for a series of 0', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 0]);

    storyPost($learner, ['kind' => 'streak'])->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'share_subject_not_found', 'message' => 'Nothing to share.']]);

    expect(Storage::disk('public')->allFiles('share-cards'))->toBe([]);
});

it('tells the story of an own achievement, and answers 404 alike for someone else\'s, a locked and an unknown one', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $other = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Words100)->create(['learner_id' => $learner->id]);
    Achievement::factory()->key(AchievementKey::Streak30)->create(['learner_id' => $other->id]);

    $own = storyPost($learner, ['kind' => 'achievement', 'subject' => 'words_100'])->assertOk();
    expect((string) $own->json('media_url'))->toContain('/story_words_100-ru-')
        ->and((string) $own->json('text'))->toStartWith('Достижение «100 слов» в Muhrla · https://t.me/MuhrlaBot?start=ref_');

    $answers = [];
    foreach (['streak_30', 'streak_7', 'no_such_key'] as $subject) {
        $answers[] = storyPost($learner, ['kind' => 'achievement', 'subject' => $subject])->assertNotFound()->json();
    }
    expect(array_unique(array_map('json_encode', $answers)))->toHaveCount(1)
        ->and($answers[0])->toBe(['error' => ['code' => 'share_subject_not_found', 'message' => 'Nothing to share.']]);
});

it('answers 403 share_unavailable when sharing is off, the bot has no username, or there is no https address for the picture', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 5]);

    config(['share.enabled' => false]);
    storyPost($learner, ['kind' => 'streak'])->assertForbidden()->assertExactJson(['error' => ['code' => 'share_unavailable', 'message' => 'Sharing is unavailable.']]);

    config(['share.enabled' => true, 'telegram.bot_username' => '']);
    storyPost($learner, ['kind' => 'streak'])->assertForbidden()->assertJsonPath('error.code', 'share_unavailable');

    config(['telegram.bot_username' => 'MuhrlaBot']);
    Storage::fake('public', ['url' => 'http://muhrla.test/storage']);
    storyPost($learner, ['kind' => 'streak'])->assertForbidden()->assertJsonPath('error.code', 'share_unavailable');

    Storage::fake('cards', ['url' => 'https://muhrla.test/cards']);
    config(['achievements.disk' => 'cards']);
    storyPost($learner, ['kind' => 'streak'])->assertForbidden()->assertJsonPath('error.code', 'share_unavailable');

    // A platform ban is refused by TwaAuth before the controller (the service refuses it too).
    $learner->update(['blocked_at' => now()]);
    config(['achievements.disk' => 'public']);
    storyPost($learner, ['kind' => 'streak'])->assertUnauthorized()->assertJsonPath('error.code', 'learner_blocked');
});

it('answers 422 for a kind without a story, an unknown kind, and a missing, forbidden or malformed subject', function (array $body, string $field): void {
    $learner = Learner::factory()->create(['streak_current' => 5]);

    storyPost($learner, $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'no kind' => [[], 'kind'],
    'unknown kind' => [['kind' => 'photo'], 'kind'],
    'invite (no story)' => [['kind' => 'invite'], 'kind'],
    'friend_invite (no story)' => [['kind' => 'friend_invite'], 'kind'],
    'game_city (no story)' => [['kind' => 'game_city', 'subject' => '5'], 'kind'],
    'league_week (later)' => [['kind' => 'league_week', 'subject' => '1'], 'kind'],
    'achievement without subject' => [['kind' => 'achievement'], 'subject'],
    'achievement, bad subject' => [['kind' => 'achievement', 'subject' => '../etc/passwd'], 'subject'],
    'achievement, long subject' => [['kind' => 'achievement', 'subject' => str_repeat('a', 41)], 'subject'],
    'streak with a subject' => [['kind' => 'streak', 'subject' => 'streak_7'], 'subject'],
]);

it('refuses the series through /share/prepare: a story only', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 5]);

    storyPost($learner, ['kind' => 'streak'], '/api/twa/share/prepare')->assertUnprocessable()->assertJsonValidationErrors(['kind']);
});

it('shares the limit twa-share with the prepared message: per minute, cached answers included', function (): void {
    config(['share.prepare.per_minute' => 3]);
    $learner = Learner::factory()->create(['streak_current' => 5]);

    storyPost($learner, ['kind' => 'streak'])->assertOk();
    storyPost($learner, ['kind' => 'streak'])->assertOk();
    storyPost($learner, ['kind' => 'invite'], '/api/twa/share/prepare')->assertOk();
    storyPost($learner, ['kind' => 'streak'])->assertTooManyRequests()->assertHeader('Retry-After');

    $this->travel(61)->seconds();
    storyPost($learner, ['kind' => 'streak'])->assertOk();
});

it('keeps the caption within 200 characters whatever the texts, cutting the phrase and never the link', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru', 'streak_current' => 365]);
    app('translator')->addLines(['share.story.streak.text' => str_repeat('Очень длинная фраза ', 20).':days'], 'ru');

    $text = (string) storyPost($learner, ['kind' => 'streak'])->assertOk()->json('text');
    $link = 'https://t.me/MuhrlaBot?start=ref_'.$learner->fresh()?->referral_code;

    expect(mb_strlen($text))->toBeLessThanOrEqual(200)
        ->and($text)->toEndWith('… · '.$link);
});

it('draws one file per content: two learners with the same series share it, a second tap costs no render', function (): void {
    $one = Learner::factory()->create(['interface_locale' => 'ru', 'streak_current' => 12, 'daily_goal' => 10]);
    $two = Learner::factory()->create(['interface_locale' => 'ru', 'streak_current' => 12, 'daily_goal' => 10]);

    $first = storyPost($one, ['kind' => 'streak'])->assertOk()->json('media_url');
    storyPost($one, ['kind' => 'streak'])->assertOk()->assertJsonPath('media_url', $first);
    storyPost($two, ['kind' => 'streak'])->assertOk()->assertJsonPath('media_url', $first);

    expect(Storage::disk('public')->allFiles('share-cards'))->toHaveCount(1);
});

it('costs at most 3 statements: the learner, the achievement row, the event - and no row for the series', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru', 'streak_current' => 7, 'referral_code' => 'ABCDEFGH']);
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);
    $token = app(JwtService::class)->issue($learner->id)['token'];

    $streak = QueryLog::during(fn () => $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/twa/share/story', ['kind' => 'streak'])->assertOk());
    $achievement = QueryLog::during(fn () => $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/twa/share/story', ['kind' => 'achievement', 'subject' => 'streak_7'])->assertOk());

    expect(count(QueryLog::matching($streak, '/^(select|insert|update|delete)/i')))->toBeLessThanOrEqual(2, implode("\n", $streak))
        ->and(count(QueryLog::matching($achievement, '/^(select|insert|update|delete)/i')))->toBeLessThanOrEqual(3, implode("\n", $achievement))
        ->and(QueryLog::matching($streak, '/word_repetitions|learner_daily_stats|achievements/'))->toBe([]);
});

it('records story_sent and story_fallback {reason} from the client, 204, and refuses the malformed ones', function (): void {
    $learner = Learner::factory()->create();

    storyPost($learner, ['event' => 'story_sent', 'kind' => 'streak'], '/api/twa/share/events')->assertNoContent();
    storyPost($learner, ['event' => 'story_fallback', 'kind' => 'achievement', 'reason' => 'unavailable'], '/api/twa/share/events')->assertNoContent();
    storyPost($learner, ['event' => 'story_fallback', 'kind' => 'streak', 'reason' => 'old_client'], '/api/twa/share/events')->assertNoContent();

    foreach ([
        ['event' => 'story_sent', 'kind' => 'invite'],
        ['event' => 'story_sent', 'kind' => 'streak', 'reason' => 'error'],
        ['event' => 'story_fallback', 'kind' => 'streak'],
        ['event' => 'story_fallback', 'kind' => 'streak', 'reason' => 'spam'],
        ['event' => 'sent', 'kind' => 'streak'],
        ['event' => 'fallback', 'kind' => 'achievement', 'reason' => 'unavailable'],
    ] as $body) {
        storyPost($learner, $body, '/api/twa/share/events')->assertUnprocessable();
    }

    $events = ProductEvent::query()->whereIn('name', ['share.sent', 'share.fallback'])->orderBy('id')->get();
    expect($events->map(fn (ProductEvent $e): array => [$e->name, $e->props])->all())->toEqual([
        ['share.sent', ['kind' => 'streak', 'via' => 'story']],
        ['share.fallback', ['kind' => 'achievement', 'reason' => 'unavailable', 'via' => 'story']],
        ['share.fallback', ['kind' => 'streak', 'reason' => 'old_client', 'via' => 'story']],
    ]);
});
