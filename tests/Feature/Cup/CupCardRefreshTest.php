<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Services\CupCards;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Cup\Services\CupStandings;
use App\Domain\Telegram\Services\GroupChatMigrator;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Cup\CupBotKit;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §3.2, §6.3 (K6): `cups:refresh-cards` - the card is edited in place, only with news since its last render, at most once in
 * 5 minutes, only when its text changed; "message is not modified" is a success; a deleted card comes back at most once a day; a
 * chat that became a supergroup gets the card anew; a paused cup is not edited.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test', 'app.brand' => 'Muhrla']);
    Storage::fake('public');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A running cup of `$players` whose card was posted at the first minute of day 1. */
function cardedCup(int $players = 2): array
{
    $world = CupKit::running($players);
    app(CupOutbox::class)->enqueue($world['cup']->id, 'card', CupMessageKind::Card, $world['start']);
    $world['cup'] = Cup::query()->with('telegramGroup')->findOrFail($world['cup']->id);
    expect($world['cup']->card_message_id)->not->toBeNull();

    return $world;
}

/** `$learner` scored `$points` on day `$day` at `$at` (what the core writes on a right answer). */
function scored(Cup $cup, Learner $learner, int $points, CarbonImmutable $at, int $day = 1): void
{
    $participant = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $learner->id)->sole();
    DB::table('cup_day_scores')->updateOrInsert(['participant_id' => $participant->id, 'day' => $day], ['cup_id' => $cup->id, 'round_points' => $points, 'updated_at' => $at]);
    $participant->forceFill(['best_total' => $points, 'best_round_points' => $points, 'updated_at' => $at])->saveQuietly();
    app(CupStandings::class)->forget($cup->id);
}

function refreshCards(CarbonImmutable $at): void
{
    CarbonImmutable::setTestNow($at);
    Artisan::call('cups:refresh-cards');
}

it('edits the caption of the card with the new table - once, not again within 5 minutes, not at all without news', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = cardedCup();
    scored($cup, $a, 8, $start->addMinutes(2));

    refreshCards($start->addMinutes(3)); // not 5 minutes since the post yet
    expect(CupKit::telegram()->captionEdits)->toBe([]);

    refreshCards($start->addMinutes(6));
    $edit = CupKit::telegram()->captionEdits[0];
    expect(CupKit::telegram()->captionEdits)->toHaveCount(1)
        ->and($edit['message_id'])->toBe($cup->card_message_id)
        ->and($edit['caption'])->toContain('День 1 из 7')->toContain('1. Player 0 — 8')->toContain('Участников: 2 · сводка в 20:00')
        ->and(array_keys(CupBotKit::buttons($edit['markup'])))->toBe(['▶️ Играть сегодня', '📊 Таблица', '📖 Правила']);

    scored($cup, $a, 10, $start->addMinutes(7));
    refreshCards($start->addMinutes(8)); // news, but the card was edited 2 minutes ago
    refreshCards($start->addMinutes(30)->addSeconds(5)); // the 10 points - edited
    refreshCards($start->addMinutes(50)); // nothing new since
    expect(CupKit::telegram()->captionEdits)->toHaveCount(2)
        ->and(CupKit::telegram()->captionEdits[1]['caption'])->toContain('1. Player 0 — 10');
    expect(app(CupCards::class)->dueIds($start->addMinutes(50), 100))->toBe([]);
});

it('does not edit when the text did not change and counts "message is not modified" as a success', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = cardedCup();
    $hash = $cup->card_text_hash;
    DB::table('cup_participants')->where('cup_id', $cup->id)->update(['updated_at' => $start->addMinute()]); // a write, same table

    refreshCards($start->addMinutes(6));
    expect(CupKit::telegram()->captionEdits)->toBe([])->and($cup->fresh()->card_text_hash)->toBe($hash);

    scored($cup, $a, 4, $start->addMinutes(7));
    CupKit::telegram()->failCaptionEdits = true;
    refreshCards($start->addMinutes(13));

    expect($cup->fresh()->card_text_hash)->not->toBe($hash)
        ->and(app(CupCards::class)->dueIds($start->addMinutes(30), 100))->toBe([]);
});

it('moves the card to the next day at midnight without a new message', function (): void {
    ['cup' => $cup, 'start' => $start] = cardedCup();

    refreshCards($start->addDay());

    expect(CupKit::telegram()->captionEdits[0]['caption'])->toContain('День 2 из 7')
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1);
});

it('shows the end of the cup on its card after the finish, with the one button «Итоговая таблица»', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = cardedCup();
    scored($cup, $a, 20, $start->addMinutes(2));
    $end = CarbonImmutable::instance($cup->ends_at)->addMinutes(31);
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['built_at' => $start]);
    app(CupLifecycleService::class)->finish($cup->id, $end);

    refreshCards($end->addMinute());

    $edit = CupKit::telegram()->captionEdits[0];
    expect($edit['caption'])->toContain('Кубок завершён')->toContain('1. Player 0 — 20')
        ->and(array_keys(CupBotKit::buttons($edit['markup'])))->toBe(['🏆 Итоговая таблица']);
});

it('posts a deleted card anew - at most once a day', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = cardedCup();
    CupKit::telegram()->failCaptionEditsWith = 'message to edit not found';

    scored($cup, $a, 2, $start->addMinutes(10));
    refreshCards($start->addHours(2)); // the card is 2 hours old: not posted again yet
    expect(CupKit::telegram()->photoMessages)->toHaveCount(1);

    scored($cup, $a, 4, $start->addHours(25));
    refreshCards($start->addHours(25)->addMinutes(6));

    $cup->refresh();
    expect(CupKit::telegram()->photoMessages)->toHaveCount(2)
        ->and($cup->card_message_id)->toBe(CupKit::telegram()->photoMessages[1]['message_id'])
        ->and(CupBotKit::card($cup)->status)->toBe(CupMessageStatus::Sent);
});

it('posts the card anew in the supergroup a group became', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = cardedCup();
    app(GroupChatMigrator::class)->migrate($cup->telegramGroup->chat_id, -1_008_888_888_888);
    scored($cup, $a, 2, $start->addMinutes(1));

    refreshCards($start->addMinutes(6));

    expect(CupKit::telegram()->captionEdits)->toBe([])
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(2)
        ->and(CupKit::telegram()->photoMessages[1]['chat_id'])->toBe(-1_008_888_888_888)
        ->and($cup->fresh()->card_chat_id)->toBe(-1_008_888_888_888);
});

it('does not edit the card of a paused cup, and waits while its chat has no slot', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = cardedCup();
    scored($cup, $a, 2, $start->addMinutes(1));
    CarbonImmutable::setTestNow($start->addMinutes(6));
    app(TelegramMessageRateLimiter::class)->pause(30, $cup->telegramGroup->chat_id);

    refreshCards($start->addMinutes(6));
    expect(CupKit::telegram()->captionEdits)->toBe([]);

    refreshCards($start->addMinutes(12));
    expect(CupKit::telegram()->captionEdits)->toHaveCount(1);

    scored($cup, $a, 4, $start->addMinutes(13));
    $cup->telegramGroup->update(['bot_status' => 'left']);
    refreshCards($start->addMinutes(20));
    expect(CupKit::telegram()->captionEdits)->toHaveCount(1);
});

it('puts the ordinary emoji of a live Pro next to the name, never the name of Telegram', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $start] = cardedCup();
    $a->forceFill(['pro_emoji' => 'fire'])->save();
    $b->forceFill(['pro_emoji' => 'fire'])->save(); // a Free learner: the choice is kept, not shown
    Subscription::factory()->create([
        'subscriber_id' => $a->id,
        'provider' => 'trial',
        'status' => 'trialing',
        'starts_at' => $start->subDays(3),
        'current_period_ends_at' => $start->addDays(10),
    ]);
    app(EntitlementResolver::class)->forget($a);
    CupParticipant::query()->where('learner_id', $a->id)->update(['display_name' => 'Malika']);
    scored($cup, $a, 6, $start->addMinutes(1));
    scored($cup, $b, 4, $start->addMinutes(1));

    refreshCards($start->addMinutes(6));

    expect(CupKit::telegram()->captionEdits[0]['caption'])->toContain('1. Malika 🔥 — 6')->toContain('2. Player 1 — 4');
});

it('writes the card of an Uzbek cup in Uzbek', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = cardedCup();
    Cup::query()->whereKey($cup->id)->update(['locale' => 'uz']);
    scored($cup, $a, 2, $start->addMinutes(1));

    refreshCards($start->addMinutes(6));

    expect(CupKit::telegram()->captionEdits[0]['caption'])->toContain("Muhrla do'stlar kubogi")->toContain('7 kundan 1-kun')
        ->and(array_keys(CupBotKit::buttons(CupKit::telegram()->captionEdits[0]['markup'])))->toBe(["▶️ Bugun o'ynash", '📊 Jadval', '📖 Qoidalar']);
});

it('keeps the card row unique: a republish resets the same row', function (): void {
    ['cup' => $cup, 'start' => $start] = cardedCup();

    app(CupOutbox::class)->republishCard($cup->id, $start->addMinute());
    app(CupOutbox::class)->republishCard($cup->id, $start->addMinutes(2));

    expect(CupMessage::query()->where('cup_id', $cup->id)->count())->toBe(1)
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(3);
});
