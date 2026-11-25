<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupCardRenderer;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Cup\Services\CupStandings;
use App\Domain\Cup\Services\CupSummaries;
use App\Domain\Telegram\Support\TelegramText;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupBotKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * Review of release 5 (docs/33 §11.8): the names the bot prints, the captions within Telegram's limit in UTF-16, the summaries that
 * no longer starve behind cups whose time has not come, the final posted at the finish, the card asked to be posted anew while it was
 * being sent, the key of `cups.meta` written without the stale model, the publication asked of the administrator first.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test']);
    Storage::fake('public');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Points for every participant of the cup (the table the bot prints). */
function reviewScore(Cup $cup, int $points = 4): void
{
    foreach (CupParticipant::query()->where('cup_id', $cup->id)->orderBy('id')->get() as $i => $participant) {
        DB::table('cup_day_scores')->insert(['cup_id' => $cup->id, 'participant_id' => $participant->id, 'day' => 1, 'round_points' => $points + $i, 'updated_at' => now()]);
        $participant->forceFill(['best_total' => $points + $i, 'best_round_points' => $points + $i])->saveQuietly();
    }
    app(CupStandings::class)->forget($cup->id);
}

/** @param array<string, mixed> $body */
function reviewHttp(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

it('refuses at the input a name Telegram would link, with the reason in details', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $member = Learner::factory()->create();
    CupKit::member($member);

    expect(reviewHttp($member, 'POST', "/cups/{$cup->id}/join", ['display_name' => 'visit casino.com'])->assertStatus(422)->json('error'))
        ->toMatchArray(['code' => 'cup_display_name_invalid', 'details' => ['reason' => 'link']])
        ->and(reviewHttp($a, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => '@casino_bot'])->assertStatus(422)->json('error.details.reason'))->toBe('mention')
        ->and(reviewHttp($a, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => str_repeat('🦊', 33)])->assertStatus(422)->json('error.details.reason'))->toBe('too_long')
        ->and(reviewHttp($a, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => str_repeat('🦊', 32)])->assertOk()->json('display_name'))->toBe(str_repeat('🦊', 32));
});

it('defuses in the chat a name stored before the rule, in the card and the final', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    reviewScore($cup);
    DB::table('cup_participants')->where('cup_id', $cup->id)->orderBy('id')->limit(1)->update(['display_name' => '@casino t.me/x']);
    app(CupStandings::class)->forget($cup->id);
    $renderer = app(CupCardRenderer::class);

    $card = $renderer->card($cup, $cup->telegramGroup, CarbonImmutable::now())->text;
    expect($card)->toContain('＠casino t․me/x')->not->toContain('@casino')->not->toContain('t.me');

    $cup->forceFill(['status' => 'finished', 'active_group_id' => null, 'finished_at' => now()])->save();
    DB::table('cup_participants')->where('cup_id', $cup->id)->update(['final_rank' => 1]);
    app(CupStandings::class)->forget($cup->id);
    $final = $renderer->final($cup, $cup->telegramGroup)->text;
    expect($final)->not->toContain('@casino')->toContain('＠casino');
});

it('keeps a caption full of emoji names within 1024 UTF-16 units by dropping whole rows', function (): void {
    ['cup' => $cup] = CupKit::running(12);
    reviewScore($cup);
    foreach (CupParticipant::query()->where('cup_id', $cup->id)->get() as $participant) {
        $participant->forceFill(['display_name' => str_repeat('👨‍👩‍👧', 12)])->saveQuietly(); // 12 graphemes, 96 UTF-16 units
    }
    app(CupStandings::class)->forget($cup->id);

    $content = app(CupCardRenderer::class)->card($cup, $cup->telegramGroup, CarbonImmutable::now());
    $caption = $content->textFor(true);

    expect(TelegramText::length($content->text))->toBeGreaterThan(1024)  // the text keeps its 10 rows (a text card takes 4096)
        ->and(mb_strlen($content->text))->toBeLessThan(1024)               // what the old check (code points) let through
        ->and(substr_count($content->text, '👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧👨‍👩‍👧'))->toBe(10)
        ->and(TelegramText::length($caption))->toBeLessThanOrEqual(1024)
        ->and($caption)->toContain('…и ещё')->not->toEndWith('…')          // whole rows dropped, nothing cut in half
        ->and($caption)->toContain('Участников: 12')
        ->and($content->textFor(false))->toBe($content->text);
});

it('ends the summary of the last day without the promise of a new round at midnight', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    reviewScore($cup);
    $renderer = app(CupCardRenderer::class);

    expect($renderer->summary($cup, $cup->telegramGroup, 1)->text)->toContain('Раунд нового дня откроется в полночь')
        ->and($renderer->summary($cup, $cup->telegramGroup, 7)->text)->toContain('Это последний день')->not->toContain('Раунд нового дня');
    $cup->forceFill(['locale' => 'uz'])->save();
    expect($renderer->summary($cup, $cup->telegramGroup, 7)->text)->toContain('Bu oxirgi kun');
});

it('does not starve a due summary behind cups whose time has not come (batch smaller than the running cups)', function (): void {
    $cups = [];
    for ($i = 0; $i < 3; $i++) {
        ['cup' => $cups[$i], 'start' => $start] = CupKit::running(2);
        reviewScore($cups[$i]);
    }
    // The first two at 22:00, the third at 20:00 of the cup's time zone (Tashkent); now 20:30 - only the third is due.
    DB::table('cups')->whereIn('id', [$cups[0]->id, $cups[1]->id])->update(['summary_minute' => 1320]);
    DB::table('cups')->where('id', $cups[2]->id)->update(['summary_minute' => 1200]);
    Bus::fake([PostCupMessageJob::class]);

    $report = app(CupSummaries::class)->enqueueDue($start->addHours(20)->addMinutes(29), 2);

    expect($report)->toBe(['due' => 1, 'queued' => 1, 'quiet' => 0])
        ->and(CupMessage::query()->where('kind', 'summary')->pluck('cup_id')->all())->toBe([$cups[2]->id])
        ->and(DB::table('cup_days')->whereNotNull('summary_posted_at')->pluck('cup_id')->all())->toBe([$cups[2]->id]);
    Bus::assertDispatchedTimes(PostCupMessageJob::class, 1);
});

it('queues the final at the finish, not at the next sweep', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    reviewScore($cup);
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['built_at' => now()]);
    Bus::fake([PostCupMessageJob::class]);

    app(CupScheduler::class)->advance(CarbonImmutable::instance($cup->ends_at)->addMinutes(31));

    $final = CupMessage::query()->where('cup_id', $cup->id)->where('dedupe_key', 'final')->sole();
    Bus::assertDispatched(PostCupMessageJob::class, fn (PostCupMessageJob $job): bool => $job->cupMessageId === $final->id);
    Bus::assertDispatchedTimes(PostCupMessageJob::class, 1);

    // A second run finishes nothing and queues nothing more.
    app(CupScheduler::class)->advance(CarbonImmutable::instance($cup->ends_at)->addMinutes(40));
    Bus::assertDispatchedTimes(PostCupMessageJob::class, 1);
});

it('posts the card again after a send that was in flight when the bot came back and did not post it', function (): void {
    ['cup' => $cup, 'start' => $now] = CupKit::running(2);
    $telegram = CupKit::telegram();
    $fired = false;
    $telegram->beforeSend = function () use (&$fired, $cup, $now): void {
        if (! $fired) {
            $fired = true;
            // The bot came back while this send of the card is on its way (the row is `sending`).
            expect(app(CupOutbox::class)->republishCard($cup->id, $now))->toBeTrue();
        }
    };
    $telegram->failSends = 1;
    $telegram->failSendsAsTransport = true; // this send times out: `unknown`, never repeated by itself

    app(CupOutbox::class)->enqueue($cup->id, 'card', CupMessageKind::Card, $now);

    $row = CupBotKit::card($cup);
    expect($row->status->value)->toBe('sent')               // posted by the second send, at once - not a day later
        ->and($telegram->photoMessages)->toHaveCount(1)
        ->and(Cup::query()->findOrFail($cup->id)->meta['card_repost'] ?? null)->toBeFalse();
});

it('does not post the card twice when the send in flight did post it', function (): void {
    ['cup' => $cup, 'start' => $now] = CupKit::running(2);
    $telegram = CupKit::telegram();
    $fired = false;
    $telegram->beforeSend = function () use (&$fired, $cup, $now): void {
        if (! $fired) {
            $fired = true;
            app(CupOutbox::class)->republishCard($cup->id, $now);
            // Another key of `meta` written meanwhile (an action of /admin, another worker): the send must not drop it.
            DB::table('cups')->where('id', $cup->id)->update(['meta->other' => 'kept']);
        }
    };

    app(CupOutbox::class)->enqueue($cup->id, 'card', CupMessageKind::Card, $now);

    $meta = Cup::query()->findOrFail($cup->id)->meta;
    expect(CupBotKit::card($cup)->status->value)->toBe('sent')
        ->and($telegram->photoMessages)->toHaveCount(1)
        ->and($meta['other'] ?? null)->toBe('kept')
        ->and($meta['card_kind'] ?? null)->toBe('photo')
        ->and($meta['card_repost'] ?? null)->toBeFalse();
});

it('asks Telegram who pressed «Опубликовать» before it reads anything about the level', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $draft = app(CupLifecycleService::class)->propose(CupKit::group(), 1)->cup;
    $member = Learner::factory()->create();
    CupKit::member($member);

    expect(fn () => app(CupLifecycleService::class)->publish($draft, (int) $member->telegram_user_id, 'Z9'))
        ->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_chat_admin'));
    expect(reviewHttp($member, 'POST', "/cups/setup/{$draft->public_code}/publish", ['level' => 'Z9'])->assertForbidden()->json('error.code'))->toBe('cup_not_chat_admin');

    DB::table('words')->update(['is_active' => false]); // no material at all: still "not an administrator" for him
    expect(fn () => app(CupLifecycleService::class)->publish($draft, (int) $member->telegram_user_id))
        ->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_chat_admin'));
});
