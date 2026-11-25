<?php

declare(strict_types=1);

/*
 * ShareMessageBuilder (docs/36 §1в, H3): the one InlineQueryResult of a message shared "through the bot". Only the learner's own
 * subject (someone else's, a locked or a missing one look the same), texts only from lang/share.php, the learner's invite link in the
 * one URL button, our own HTTPS card as the photo, an article without it.
 */

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\ShareMessageBuilder;
use App\Domain\Game\Services\GameCardSender;
use App\Models\Achievement;
use App\Models\GameCityProgress;
use App\Models\Learner;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public', ['url' => 'https://muhrla.test/storage']);
    config([
        'app.brand' => 'Muhrla',
        'app.brand_logo' => null,
        'telegram.bot_username' => 'MuhrlaBot',
        'achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art',
        'billing.trial.days' => 14,
        'game.access' => 'all',
    ]);
});

/** @return array<string, mixed> */
function shareBuild(Learner $learner, ShareKind $kind, ?string $subject = null, string $locale = 'ru'): array
{
    return app(ShareMessageBuilder::class)->build($learner, $kind, $subject, $locale);
}

function shareBuildError(Learner $learner, ShareKind $kind, ?string $subject = null): ?ShareException
{
    try {
        shareBuild($learner, $kind, $subject);
    } catch (ShareException $e) {
        return $e;
    }

    return null;
}

it('builds the photo of an own unlocked achievement: our https card, the lang caption, one button with the learner\'s ref link', function (): void {
    $learner = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);

    $result = shareBuild($learner, ShareKind::Achievement, 'streak_7');
    $title = AchievementKey::Streak7->title('ru');
    $code = $learner->fresh()?->referral_code;

    expect($result['type'])->toBe('photo')
        ->and($result['photo_url'])->toStartWith('https://muhrla.test/storage/share-cards/streak_7-ru-')->toEndWith('.jpg')
        ->and($result['thumbnail_url'])->toBe($result['photo_url'])
        ->and($result['photo_width'])->toBe(1080)
        ->and($result['photo_height'])->toBe(1080)
        ->and($result['caption'])->toBe(__('share.caption.achievement', ['title' => $title], 'ru'))
        ->and($result['caption'])->toContain('«'.$title.'»')->not->toContain('http')
        ->and($result['title'])->toBe(__('share.title.achievement', ['title' => $title], 'ru'))
        ->and($result['reply_markup'])->toBe(['inline_keyboard' => [[[
            'text' => '🚀 Начать изучение',
            'url' => 'https://t.me/MuhrlaBot?start=ref_'.$code,
        ]]]])
        ->and($result)->not->toHaveKey('parse_mode')
        ->and(strlen((string) $result['id']))->toBeLessThanOrEqual(64)
        ->and(Storage::disk('public')->exists('share-cards/'.basename((string) $result['photo_url'])))->toBeTrue();
});

it('does not tell someone else\'s achievement, a locked one, an unknown key or garbage from "none"', function (): void {
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $other->id]);

    foreach (['streak_7', 'streak_30', 'no_such_key', "streak_7' or 1=1", 'STREAK_7', '', str_repeat('a', 41), null] as $subject) {
        $e = shareBuildError($learner, ShareKind::Achievement, $subject);
        expect($e)->not->toBeNull()
            ->and($e?->errorCode)->toBe(ShareException::NOT_FOUND)
            ->and($e?->httpStatus())->toBe(404)
            ->and($e?->getMessage())->toBe('Nothing to share.');
    }
});

it('answers "not found" before it looks at the bot: a missing subject is 404 even without a bot username', function (): void {
    config(['telegram.bot_username' => '']);
    $learner = Learner::factory()->create();

    expect(shareBuildError($learner, ShareKind::Achievement, 'streak_7')?->errorCode)->toBe(ShareException::NOT_FOUND);

    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);
    expect(shareBuildError($learner, ShareKind::Achievement, 'streak_7')?->errorCode)->toBe(ShareException::UNAVAILABLE)
        ->and(shareBuildError($learner, ShareKind::Invite)?->errorCode)->toBe(ShareException::UNAVAILABLE)
        ->and(shareBuildError($learner, ShareKind::FriendInvite)?->reason)->toBe('bot_username_missing');
});

it('falls back to an article with the same text and button when the card has no public https address', function (): void {
    $learner = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);
    $photo = shareBuild($learner, ShareKind::Achievement, 'streak_7');

    Storage::fake('public', ['url' => 'http://muhrla.test/storage']);
    $article = shareBuild($learner, ShareKind::Achievement, 'streak_7');

    expect($article['type'])->toBe('article')
        ->and($article)->not->toHaveKey('photo_url')
        ->and($article['title'])->toBe($photo['title'])
        ->and($article['input_message_content'])->toBe(['message_text' => $photo['caption']])
        ->and($article['reply_markup'])->toBe($photo['reply_markup'])
        ->and($article['id'])->not->toBe($photo['id']);
});

it('falls back to an article when the cards are not on the public disk, or the card cannot be drawn', function (): void {
    $learner = Learner::factory()->create();

    Storage::fake('cards', ['url' => 'https://muhrla.test/cards']);
    config(['achievements.disk' => 'cards']);
    expect(shareBuild($learner, ShareKind::Invite)['type'])->toBe('article');

    config(['achievements.disk' => 'public', 'achievements.fonts.bold' => resource_path('fonts/missing.ttf')]);
    expect(shareBuild($learner, ShareKind::Invite)['type'])->toBe('article');
});

it('builds the invitation with the brand card and the length of the trial, and without it when there is no trial', function (): void {
    $learner = Learner::factory()->create();

    $ru = shareBuild($learner, ShareKind::Invite);
    $uz = shareBuild($learner, ShareKind::Invite, locale: 'uz');
    config(['billing.trial.days' => 0]);
    $plain = shareBuild($learner, ShareKind::Invite);

    expect($ru['type'])->toBe('photo')
        ->and($ru['photo_url'])->toContain('/share-cards/invite-ru-')
        ->and($ru['caption'])->toBe('Учу английский в Muhrla — присоединяйся, первые 14 дней Pro бесплатно!')
        ->and($uz['caption'])->toBe(__('share.caption.invite', ['trial' => trans_choice('referrals.days', 14, ['count' => 14], 'uz')], 'uz'))
        ->and($uz['reply_markup']['inline_keyboard'][0][0]['text'])->toBe("🚀 O'rganishni boshlash")
        ->and($plain['caption'])->toBe('Учу английский в Muhrla — присоединяйся!');
});

it('ignores a subject for the invitations: the same message whatever is passed', function (): void {
    $learner = Learner::factory()->create();

    expect(shareBuild($learner, ShareKind::Invite, 'streak_7')['caption'])->toBe(shareBuild($learner, ShareKind::Invite)['caption']);
});

it('builds the friend invitation with the f_ link and its own button', function (): void {
    $learner = Learner::factory()->create();

    $result = shareBuild($learner, ShareKind::FriendInvite);

    expect($result['caption'])->toBe('Давай дружить в Muhrla: сравним серии и устроим вызов!')
        ->and($result['reply_markup'])->toBe(['inline_keyboard' => [[[
            'text' => '🤝 Добавить в друзья',
            'url' => 'https://t.me/MuhrlaBot?start=f_'.$learner->fresh()?->referral_code,
        ]]]]);
});

it('builds the card of an own beaten city, and nothing for a city not beaten, someone else\'s, missing, or a bad id', function (): void {
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();
    $stage = Stage::factory()->create(['number' => 3]);
    $lost = Stage::factory()->create(['number' => 4]);
    $foreign = Stage::factory()->create(['number' => 5]);
    GameCityProgress::factory()->defeated()->create(['learner_id' => $learner->id, 'stage_id' => $stage->id]);
    GameCityProgress::factory()->create(['learner_id' => $learner->id, 'stage_id' => $lost->id, 'boss_attempts' => 2]);
    GameCityProgress::factory()->defeated()->create(['learner_id' => $other->id, 'stage_id' => $foreign->id]);

    $result = shareBuild($learner, ShareKind::GameCity, (string) $stage->id);

    expect($result['type'])->toBe('photo')
        ->and($result['photo_url'])->toContain('/share-cards/boss_3-ru-')
        ->and($result['caption'])->toBe('🐪 Я дошёл(дошла) до Самарканда в игре «Караван» в Muhrla! Учим английский прямо в Telegram.')
        ->and($result['reply_markup']['inline_keyboard'][0][0]['url'])->toBe('https://t.me/MuhrlaBot?start=ref_'.$learner->fresh()?->referral_code);

    foreach ([(string) $lost->id, (string) $foreign->id, '999999', '0', '01', 'abc', str_repeat('9', 19), null] as $subject) {
        expect(shareBuildError($learner, ShareKind::GameCity, $subject)?->errorCode)->toBe(ShareException::NOT_FOUND);
    }
});

it('uses the same card file as the DM of the boss', function (): void {
    $learner = Learner::factory()->create();
    $stage = Stage::factory()->create(['number' => 3]);
    GameCityProgress::factory()->defeated()->create(['learner_id' => $learner->id, 'stage_id' => $stage->id]);

    $result = shareBuild($learner, ShareKind::GameCity, (string) $stage->id);
    $renderer = app(AchievementCardRenderer::class);
    $dm = $renderer->renderSpec(app(GameCardSender::class)->cardSpec(3, 'ru'), 'ru');

    expect($result['photo_url'])->toBe($renderer->publicUrl($dm));
});

it('refuses a city while the game is closed for the learner', function (): void {
    $learner = Learner::factory()->create();
    $stage = Stage::factory()->create(['number' => 3]);
    GameCityProgress::factory()->defeated()->create(['learner_id' => $learner->id, 'stage_id' => $stage->id]);
    config(['game.access' => 'off']);

    $e = shareBuildError($learner, ShareKind::GameCity, (string) $stage->id);

    expect($e?->errorCode)->toBe(ShareException::UNAVAILABLE)->and($e?->reason)->toBe('game_unavailable');
});

it('refuses the kinds that are not there yet', function (ShareKind $kind): void {
    $learner = Learner::factory()->create();

    expect($kind->isAvailable())->toBeFalse()
        ->and(shareBuildError($learner, $kind, '1')?->reason)->toBe('kind_not_available');
})->with([ShareKind::LeagueWeek, ShareKind::MonthPlace]);

it('keeps every caption within Telegram\'s 1024 characters and every text free of links, in both languages', function (): void {
    foreach (['ru', 'uz'] as $locale) {
        foreach (AchievementKey::cases() as $key) {
            $caption = __('share.caption.achievement', ['title' => $key->title($locale)], $locale);
            expect(mb_strlen($caption))->toBeLessThanOrEqual(1024);
        }
        foreach (['achievement', 'invite', 'invite_plain', 'friend_invite', 'game_city'] as $kind) {
            $line = __("share.caption.{$kind}", [], $locale);
            expect($line)->not->toBe("share.caption.{$kind}")->not->toContain('http')->not->toContain('<');
        }
        foreach (['achievement', 'invite', 'friend_invite', 'game_city'] as $kind) {
            expect(__("share.title.{$kind}", [], $locale))->not->toBe("share.title.{$kind}");
        }
    }
});

it('gives the same id to the same content and another one to another language', function (): void {
    $learner = Learner::factory()->create();

    $first = shareBuild($learner, ShareKind::Invite);
    $again = shareBuild($learner, ShareKind::Invite);
    $uz = shareBuild($learner, ShareKind::Invite, locale: 'uz');

    expect($again['id'])->toBe($first['id'])->and($uz['id'])->not->toBe($first['id']);
});
