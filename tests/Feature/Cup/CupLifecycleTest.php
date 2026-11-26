<?php

declare(strict_types=1);

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Cup\Services\CupScoreBook;
use App\Domain\Cup\Services\CupStandings;
use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\GroupChatMigrator;
use App\Models\Cup;
use App\Models\CupDay;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §3 (K4): the life of a cup - draft, publication by an administrator of the chat, the start at the midnight (or a day later),
 * the cancel, the finish with shared places - each transition idempotent.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function lifecycle(): CupLifecycleService
{
    return app(CupLifecycleService::class);
}

function joinCup(Cup $cup, ?Learner $learner = null, ?CarbonImmutable $at = null): Learner
{
    $learner ??= Learner::factory()->create();
    CupKit::member($learner);
    app(CupParticipationService::class)->join(Cup::query()->with('telegramGroup')->findOrFail($cup->id), $learner, null, $at ?? CarbonImmutable::now());

    return $learner;
}

it('proposes one draft per chat: a second press returns the same draft', function (): void {
    $group = CupKit::group();

    $first = lifecycle()->propose($group, 42);
    $second = lifecycle()->propose($group, 43);

    expect($first->created)->toBeTrue()->and($second->created)->toBeFalse()
        ->and($second->cup->id)->toBe($first->cup->id)
        ->and(Cup::query()->count())->toBe(1)
        ->and($first->cup->status->value)->toBe('draft')
        ->and($first->cup->active_group_id)->toBe($group->id)
        ->and(strlen($first->cup->public_code))->toBe(22)
        ->and(strlen($first->cup->seed_secret))->toBe(64)
        ->and(ProductEvent::query()->where('name', 'cup.proposed')->count())->toBe(1);
});

it('offers the cup only where the switch allows it', function (string $access, array $meta, bool $allowed): void {
    config(['cup.access' => $access]);
    $group = CupKit::group($meta);

    if ($allowed) {
        expect(lifecycle()->propose($group, 42)->created)->toBeTrue();
    } else {
        expect(fn () => lifecycle()->propose($group, 42))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_unavailable', 403]));
    }
})->with([
    'off' => ['off', ['cup_pilot' => true], false],
    'pilot, a pilot group' => ['pilot', ['cup_pilot' => true], true],
    'pilot, another group' => ['pilot', [], false],
    'all' => ['all', [], true],
]);

it('proposes nothing while the cup is paused in the group', function (array $attributes, string $reason): void {
    $group = CupKit::group();
    $group->forceFill($attributes)->save();

    expect(fn () => lifecycle()->propose($group->fresh(), 42))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->details['reason']])->toBe(['cup_paused', $reason]));
})->with([
    'bot removed' => [['bot_status' => 'kicked'], 'bot_removed'],
    'bot left' => [['bot_status' => 'left'], 'bot_removed'],
    'blocked by the platform' => [['meta' => ['cups_blocked' => true]], 'blocked'],
]);

it('lets only an administrator of the chat publish, asking Telegram each time', function (string $status, bool $allowed): void {
    $group = CupKit::group();
    $cup = lifecycle()->propose($group, 42)->cup;
    CupKit::telegram()->chatMembersByUser[555] = new ChatMemberInfo($status, $status !== 'left');

    if ($allowed) {
        expect(lifecycle()->publish($cup, 555)->status->value)->toBe('scheduled');
    } else {
        expect(fn () => lifecycle()->publish($cup, 555))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_chat_admin'));
        expect($cup->fresh()?->status->value)->toBe('draft');
    }
})->with([
    'creator' => ['creator', true],
    'administrator' => ['administrator', true],
    'member' => ['member', false],
    'restricted' => ['restricted', false],
    'left' => ['left', false],
]);

it('refuses the publication when Telegram cannot say who is an administrator, and never caches the answer', function (): void {
    $cup = lifecycle()->propose(CupKit::group(), 42)->cup;
    CupKit::telegram()->chatMembersByUser[555] = new TelegramApiException('cURL error 28: timeout');

    expect(fn () => lifecycle()->publish($cup, 555))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_unverifiable', 503]));

    CupKit::telegram()->chatMembersByUser[555] = new ChatMemberInfo('administrator', true);
    CupKit::telegram()->chatMemberLookups = [];
    lifecycle()->publish($cup, 555);
    CupKit::telegram()->chatMembersByUser[555] = new ChatMemberInfo('member', true);
    $other = lifecycle()->propose(CupKit::group(), 42)->cup;
    expect(fn () => lifecycle()->publish($other, 555))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_chat_admin'));
});

it('requires the bot to be an administrator of the chat to publish (owner К1)', function (): void {
    $cup = lifecycle()->propose(CupKit::group(), 42)->cup;
    CupKit::telegram()->chatMemberStatus = 'member'; // the bot's own status
    CupKit::admin(555);

    expect(fn () => lifecycle()->publish($cup, 555))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_bot_not_admin'));
});

it('refuses an unknown level and a level without enough words', function (): void {
    $cup = lifecycle()->propose(CupKit::group(), 42)->cup;
    CupKit::admin(555);
    CupKit::course(30, 'B1', 'cup-b1-small');

    expect(fn () => lifecycle()->publish($cup, 555, 'Z9'))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_level_invalid'));
    expect(fn () => lifecycle()->publish($cup, 555, 'B1'))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_material_unavailable', 422]));
    expect(fn () => lifecycle()->publish($cup, 555, 'C1'))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_material_unavailable'));
});

it('counts only the words translated into every supported locale', function (): void {
    $course = CupKit::course(90, 'B2', 'cup-b2');
    DB::table('word_translations')->whereIn('word_id', DB::table('words')->join('lessons', 'lessons.id', '=', 'words.lesson_id')->join('stages', 'stages.id', '=', 'lessons.stage_id')->where('stages.course_id', $course->id)->limit(30)->pluck('words.id'))->delete();
    $cup = lifecycle()->propose(CupKit::group(), 42)->cup;
    CupKit::admin(555);

    expect(fn () => lifecycle()->publish($cup, 555, 'B2'))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_material_unavailable'));
});

it('publishes at the nearest Tashkent midnight at least 6 hours away and freezes the sizes', function (): void {
    config(['cup.round.tasks' => 8]);
    $cup = lifecycle()->propose(CupKit::group(), 42)->cup;
    CupKit::admin(555);
    $now = CarbonImmutable::parse('2026-12-01 15:30:00'); // 20:30 in Tashkent

    $published = lifecycle()->publish($cup, 555, 'A2', 'uz', $now);

    expect($published->starts_at?->toIso8601ZuluString())->toBe('2026-12-02T19:00:00Z')
        ->and($published->ends_at?->toIso8601ZuluString())->toBe('2026-12-09T19:00:00Z')
        ->and($published->round_tasks)->toBe(8)
        ->and($published->locale)->toBe('uz')
        ->and($published->confirmed_by_telegram_user_id)->toBe(555);

    config(['cup.round.tasks' => 10]);
    $again = lifecycle()->publish($published, 555);
    expect($again->round_tasks)->toBe(8)->and(ProductEvent::query()->where('name', 'cup.published')->count())->toBe(1);
});

it('changes level and language only in a draft, the summaries in any open cup', function (): void {
    $cup = lifecycle()->propose(CupKit::group(), 42)->cup;
    CupKit::admin(555);
    $cup = lifecycle()->updateSettings($cup, 555, ['locale' => 'uz', 'summary_minute' => 1300]);
    expect($cup->locale)->toBe('uz')->and($cup->summary_minute)->toBe(1300);

    $cup = lifecycle()->publish($cup, 555);
    expect(fn () => lifecycle()->updateSettings($cup, 555, ['locale' => 'ru']))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_already_published'));
    expect(lifecycle()->updateSettings($cup, 555, ['summaries_enabled' => false])->summaries_enabled)->toBeFalse();
    CupKit::telegram()->chatMembersByUser[42] = new ChatMemberInfo('member', true);
    expect(fn () => lifecycle()->updateSettings($cup, 42, ['summaries_enabled' => true]))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_chat_admin'));
});

it('lets an administrator of the chat cancel, not anybody else, once', function (): void {
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $member = Learner::factory()->create();
    CupKit::member($member);

    expect(fn () => lifecycle()->cancelByChatAdmin($cup, (int) $member->telegram_user_id))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_chat_admin'));
    expect(lifecycle()->cancelByChatAdmin($cup, CupKit::admin()))->toBeTrue();
    $cup->refresh();
    expect($cup->status->value)->toBe('canceled')->and($cup->cancel_reason?->value)->toBe('chat_admin')->and($cup->active_group_id)->toBeNull();
    expect(lifecycle()->cancelByChatAdmin($cup, CupKit::admin()))->toBeFalse();
    expect(lifecycle()->cancelByPlatform($cup))->toBeFalse();

    // the chat may have a new cup now
    expect(lifecycle()->propose($cup->telegramGroup, 42)->created)->toBeTrue();
});

it('expires a draft after 48 hours on the scheduler', function (): void {
    $cup = lifecycle()->propose(CupKit::group(), 42, null, CarbonImmutable::now())->cup;

    app(CupScheduler::class)->advance(CarbonImmutable::now()->addHours(47));
    expect($cup->fresh()?->status->value)->toBe('draft');

    $report = app(CupScheduler::class)->advance(CarbonImmutable::now()->addHours(49));
    expect($report['drafts_expired'])->toBe(1)
        ->and($cup->fresh()?->cancel_reason?->value)->toBe('draft_expired');
});

it('starts a cup of two at its midnight: the days in UTC, the plan without a repeated word, the snapshot of day 1', function (): void {
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    joinCup($cup);
    joinCup($cup);
    $midnight = CarbonImmutable::instance($cup->starts_at);

    expect(app(CupScheduler::class)->advance($midnight->subSecond())['started'])->toBe(0);
    $report = app(CupScheduler::class)->advance($midnight);

    $cup->refresh();
    expect($report['started'])->toBe(1)->and($report['snapshots'])->toBe(1)
        ->and($cup->status->value)->toBe('running');
    $days = CupDay::query()->where('cup_id', $cup->id)->orderBy('day')->get();
    expect($days)->toHaveCount(7)
        ->and($days[0]->starts_at->toIso8601ZuluString())->toBe('2026-12-01T19:00:00Z')
        ->and($days[6]->ends_at->toIso8601ZuluString())->toBe('2026-12-08T19:00:00Z')
        ->and($days[0]->built_at)->not->toBeNull()
        ->and($days[1]->built_at)->toBeNull();
    $words = collect($cup->word_plan)->flatten();
    expect($cup->word_plan)->toHaveCount(7)
        ->and($words->count())->toBe($words->unique()->count())
        ->and(count($cup->word_plan[1]))->toBe(12);

    // a second run changes nothing
    expect(app(CupScheduler::class)->advance($midnight->addMinute())['started'])->toBe(0);
    expect(CupDay::query()->where('cup_id', $cup->id)->count())->toBe(7);
});

it('waits a day for a second participant, then gives up after a week', function (): void {
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    joinCup($cup);
    $midnight = CarbonImmutable::instance($cup->starts_at);

    $report = app(CupScheduler::class)->advance($midnight->addMinutes(2));
    $cup->refresh();
    expect($report['shifted'])->toBe(1)
        ->and($cup->status->value)->toBe('scheduled')
        ->and($cup->starts_at?->toIso8601ZuluString())->toBe('2026-12-02T19:00:00Z')
        ->and($cup->ends_at?->toIso8601ZuluString())->toBe('2026-12-09T19:00:00Z');

    $report = app(CupScheduler::class)->advance(CarbonImmutable::now()->addDays(7)->addHours(14));
    $cup->refresh();
    expect($report['canceled'])->toBe(1)->and($cup->cancel_reason?->value)->toBe('no_players')->and($cup->active_group_id)->toBeNull();
});

it('lets a late participant join a running cup from the current day and refuses one after the end', function (): void {
    ['cup' => $cup, 'start' => $start] = CupKit::running(2);
    $late = joinCup($cup, null, $start->addDays(3));

    expect(CupParticipant::query()->where('learner_id', $late->id)->value('first_day'))->toBe(4);
    expect(fn () => joinCup($cup, null, CarbonImmutable::instance($cup->ends_at)->addMinute()))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_closed'));
});

it('refuses to join a draft and a canceled cup', function (): void {
    $draft = lifecycle()->propose(CupKit::group(), 42)->cup;
    expect(fn () => joinCup($draft))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_open'));
    lifecycle()->cancelByPlatform($draft);
    expect(fn () => joinCup($draft))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_closed', 410]));
});

it('validates the name in the tournament and limits the renames', function (): void {
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $learner = Learner::factory()->create(['first_name' => "  Ma\u{202E}lika\u{200B}  "]);
    CupKit::member($learner);
    $joined = app(CupParticipationService::class)->join($cup, $learner);
    expect($joined->participant->display_name)->toBe('Malika')->and($joined->created)->toBeTrue();
    expect(app(CupParticipationService::class)->join($cup, $learner)->created)->toBeFalse();

    foreach (['', '   ', str_repeat('x', 33), "\u{0007}"] as $bad) {
        expect(fn () => app(CupParticipationService::class)->rename($cup, $joined->participant, $learner, $bad))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_display_name_invalid'));
    }
    $participant = $joined->participant;
    foreach (['One', 'Two', 'Three'] as $name) {
        $participant = app(CupParticipationService::class)->rename($cup, $participant, $learner, $name);
    }
    expect(fn () => app(CupParticipationService::class)->rename($cup, $participant, $learner, 'Four'))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_rename_limit'));
    expect($participant->fresh()?->display_name)->toBe('Three');
});

it('finishes 30 minutes after the end: shared places 1-1-3 by total then round points, one final message', function (): void {
    ['cup' => $cup, 'learners' => $learners] = CupKit::running(4);
    $ids = CupParticipant::query()->where('cup_id', $cup->id)->orderBy('id')->pluck('id')->all();
    $set = [[$ids[0], 1, 20], [$ids[0], 2, 18], [$ids[1], 1, 18], [$ids[1], 2, 20], [$ids[2], 3, 20], [$ids[2], 4, 16], [$ids[3], 1, 2]];
    foreach ($set as [$pid, $day, $points]) {
        app(CupScoreBook::class)->addRoundPoints($cup->id, $pid, $day, $points, 5, CarbonImmutable::now());
    }
    $end = CarbonImmutable::instance($cup->ends_at);

    expect(app(CupScheduler::class)->advance($end->addMinutes(29))['finished'])->toBe(0);
    expect(app(CupScheduler::class)->advance($end->addMinutes(31))['finished'])->toBe(1);
    expect(app(CupScheduler::class)->advance($end->addMinutes(36))['finished'])->toBe(0);
    expect(lifecycle()->finish($cup->id, $end->addHour()))->toBeFalse();

    $cup->refresh();
    expect($cup->status->value)->toBe('finished')->and($cup->active_group_id)->toBeNull();
    expect(CupParticipant::query()->whereIn('id', $ids)->orderBy('id')->pluck('final_rank')->all())->toBe([1, 1, 3, 4]);
    expect(DB::table('cup_messages')->where('cup_id', $cup->id)->where('dedupe_key', 'final')->count())->toBe(1);
    $table = app(CupStandings::class)->table($cup);
    expect(array_column($table, 'rank'))->toBe([1, 1, 3, 4])->and($table[0]['total'])->toBe(38);
});

it('treats a chat with a cup as one with history when the group migrates to a supergroup', function (): void {
    $old = TelegramGroup::factory()->pending()->create(['chat_id' => -100]);
    $fresh = TelegramGroup::factory()->pending()->create(['chat_id' => -1001234]);
    CupKit::scheduled($fresh, CarbonImmutable::now());

    app(GroupChatMigrator::class)->migrate(-100, -1001234);

    expect(TelegramGroup::query()->whereKey($fresh->id)->exists())->toBeTrue('the cup kept its row');
    expect(Cup::query()->where('telegram_group_id', $fresh->id)->exists())->toBeTrue();
});

it('records the events of the life of a cup', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    expect(ProductEvent::query()->whereIn('name', ['cup.proposed', 'cup.published', 'cup.joined', 'cup.started'])->pluck('name')->countBy()->sortKeys()->all())
        ->toBe(['cup.joined' => 2, 'cup.proposed' => 1, 'cup.published' => 1, 'cup.started' => 1]);
});

it('resets an offensive name to the Telegram first name without spending a rename', function (): void {
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $learner = Learner::factory()->create(['first_name' => 'Umar']);
    CupKit::member($learner);
    $participant = app(CupParticipationService::class)->join($cup, $learner, 'Rude name')->participant;

    $reset = app(CupParticipationService::class)->resetDisplayName($participant);

    expect($reset->fresh()?->display_name)->toBe('Umar')->and($reset->fresh()?->rename_count)->toBe(0);
});
