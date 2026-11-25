<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/33 §4, §7.2, docs/04 A.23, docs/07 §5 (K7): who gets what from the routes of the cup. A stranger learns nothing - a missing cup, a
 * cup of another chat and a failed check of Telegram answer the same 403 by id; a forwarded invitation asks Telegram about the one who
 * opened it; a stranger's attempt is the 404 of a missing one; leaving and returning, one learner in two cups, the switch `CUP_ACCESS`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function cupHttp(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

function cupStranger(): Learner
{
    $learner = Learner::factory()->create(['first_name' => 'Stranger']);
    CupKit::member($learner, 'left', false);

    return $learner;
}

/** A missing cup, by the shape of the id. */
function cupMissingId(): int
{
    return (int) Cup::query()->max('id') + 1000;
}

it('lets a member of the chat open the invitation by the link and records the view once', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $member = Learner::factory()->create(['first_name' => 'Malika']);
    CupKit::member($member);

    $response = cupHttp($member, 'GET', "/cups/by-code/{$cup->public_code}")->assertOk();
    cupHttp($member, 'GET', "/cups/by-code/{$cup->public_code}")->assertOk();

    expect($response->json('id'))->toBe($cup->id)
        ->and($response->json('me'))->toBe(['joined' => false, 'participant_id' => null, 'status' => null, 'display_name' => 'Malika', 'left' => false])
        ->and(ProductEvent::query()->where('name', ProductEventName::CupInviteViewed->value)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'cup.invite_viewed')->value('props'))->toBe(['cup_id' => $cup->id, 'joined' => false, 'status' => 'scheduled']);
});

it('refuses a forwarded invitation to a stranger with the very answer of a code that does not exist', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $group = CupKit::group();
    $group->forceFill(['title' => 'Secret Friends'])->save();
    $cup = CupKit::scheduled($group, CarbonImmutable::now());
    $stranger = cupStranger();

    $forwarded = cupHttp($stranger, 'GET', "/cups/by-code/{$cup->public_code}")->assertForbidden();
    $missing = cupHttp($stranger, 'GET', '/cups/by-code/'.str_repeat('A', 22))->assertForbidden();

    expect($forwarded->json())->toBe($missing->json())
        ->and($forwarded->json('error.code'))->toBe('cup_not_member')
        ->and($forwarded->getContent())->not->toContain('Secret Friends')
        ->and(ProductEvent::query()->where('name', 'cup.invite_viewed')->count())->toBe(0);
    // ... and he cannot join through the id either: the join asks Telegram about HIM.
    expect(cupHttp($stranger, 'POST', "/cups/{$cup->id}/join")->assertForbidden()->json('error.code'))->toBe('cup_not_member')
        ->and(CupParticipant::query()->count())->toBe(0);
});

it('answers every refusal by the numeric id with one 403 - no cup, a stranger, a closed cup, a failed check', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    $stranger = cupStranger();
    $missing = cupMissingId();
    $reference = cupHttp($stranger, 'GET', "/cups/{$missing}")->assertForbidden()->json();

    foreach (['GET' => ['', '/rounds/today'], 'POST' => ['/join', '/rounds/today', '/cancel'], 'PATCH' => ['/me', '/settings']] as $method => $paths) {
        foreach ($paths as $path) {
            $body = $method === 'PATCH' ? ['display_name' => 'X', 'summaries_enabled' => false] : [];
            expect(cupHttp($stranger, $method, "/cups/{$cup->id}{$path}", $body)->assertForbidden()->json())->toBe($reference, "{$method} {$path}")
                ->and(cupHttp($stranger, $method, "/cups/{$missing}{$path}", $body)->assertForbidden()->json())->toBe($reference, "{$method} {$path} (missing)");
        }
    }

    // A finished cup answers the stranger the same (not 410 - that would tell him it existed).
    $cup->forceFill(['status' => 'finished', 'active_group_id' => null, 'finished_at' => now()])->save();
    expect(cupHttp($stranger, 'POST', "/cups/{$cup->id}/join")->assertForbidden()->json())->toBe($reference);

    // Telegram out of reach or the bot unable to check: the same 403 by id (the reasons are for the link, not for a probe).
    $other = Learner::factory()->create();
    CupKit::memberFails($other, new TelegramApiException('cURL error 28: timeout'));
    expect(cupHttp($other, 'GET', "/cups/{$cup->id}")->assertForbidden()->json())->toBe($reference);
});

it('shows the table to a participant without asking Telegram, and to a member of the chat after a fresh check', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    CupKit::telegram()->chatMemberLookups = [];

    $mine = cupHttp($a, 'GET', "/cups/{$cup->id}")->assertOk();
    expect(CupKit::telegram()->chatMemberLookups)->toBe([])
        ->and($mine->json('me.participant_id'))->toBe(CupParticipant::query()->where('learner_id', $a->id)->value('id'))
        ->and($mine->json('standings'))->toHaveCount(2);

    $member = Learner::factory()->create();
    CupKit::member($member);
    $theirs = cupHttp($member, 'GET', "/cups/{$cup->id}")->assertOk();
    expect($theirs->json('me'))->toBeNull()
        ->and(CupKit::telegram()->chatMemberLookups)->toHaveCount(1)
        ->and(cupHttp($member, 'GET', "/cups/{$cup->id}/rounds/today")->assertOk()->json('state'))->toBe('not_available');
});

it('hides a stranger\'s attempt behind the 404 of a missing one, on every route of the attempt', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b]] = CupKit::running(2);
    $attempt = cupHttp($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();
    $missing = (int) CupRoundAttempt::query()->max('id') + 100;
    $answer = ['request_id' => (string) Str::uuid(), 'element_id' => $attempt['current']['id'], 'answer' => ['skip' => true]];

    $reference = cupHttp($b, 'GET', "/cup-rounds/{$missing}")->assertNotFound()->json();
    expect($reference['error']['code'])->toBe('cup_round_not_found')
        ->and(cupHttp($b, 'GET', "/cup-rounds/{$attempt['attempt_id']}")->assertNotFound()->json())->toBe($reference)
        ->and(cupHttp($b, 'POST', "/cup-rounds/{$attempt['attempt_id']}/answer", $answer)->assertNotFound()->json())->toBe($reference)
        ->and(cupHttp($b, 'GET', "/cup-rounds/{$attempt['attempt_id']}/audio/{$attempt['current']['id']}")->assertNotFound()->json())->toBe($reference)
        ->and(CupRoundAttempt::query()->findOrFail($attempt['attempt_id'])->answered)->toBe(0);
});

it('joins a member once (201, then 200) and refuses a draft and a closed cup to him with their own codes', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $group = CupKit::group();
    $member = Learner::factory()->create(['first_name' => 'Umar']);
    CupKit::member($member);
    $draft = app(CupLifecycleService::class)->propose($group, CupKit::admin(), null, CarbonImmutable::now())->cup;

    expect(cupHttp($member, 'POST', "/cups/{$draft->id}/join")->assertStatus(409)->json('error.code'))->toBe('cup_not_open');

    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $first = cupHttp($member, 'POST', "/cups/{$cup->id}/join", ['display_name' => '  Umar   the  Great '])->assertCreated();
    $again = cupHttp($member, 'POST', "/cups/{$cup->id}/join")->assertOk();
    expect($first->json('display_name'))->toBe('Umar the Great')
        ->and($again->json('participant_id'))->toBe($first->json('participant_id'))
        ->and(cupHttp($member, 'POST', "/cups/{$cup->id}/join", ['display_name' => "\u{202E}\u{200B}"])->assertStatus(422)->json('error.code'))->toBe('cup_display_name_invalid');

    $fresh = Learner::factory()->create();
    CupKit::member($fresh);
    expect(cupHttp($fresh, 'POST', "/cups/{$cup->id}/join", ['display_name' => "\u{202E}\u{200B}"])->assertStatus(422)->json('error.code'))->toBe('cup_display_name_invalid');

    $cup->forceFill(['status' => 'canceled', 'cancel_reason' => 'chat_admin', 'active_group_id' => null, 'canceled_at' => now()])->save();
    expect(cupHttp($fresh, 'POST', "/cups/{$cup->id}/join")->assertStatus(410)->json('error.code'))->toBe('cup_closed');
});

it('keeps a participant who left the chat in the table and lets him back when Telegram says he returned', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup);

    // He left: the table is still his to see (a participant of any status), marked "left"; a new round needs the chat.
    $table = cupHttp($a, 'GET', "/cups/{$cup->id}")->assertOk();
    expect($table->json('me.status'))->toBe('left')
        ->and(collect($table->json('standings'))->firstWhere('me', true)['left'])->toBeTrue();
    CupKit::member($a, 'left', false);
    Cache::forget(CupMembershipGate::key($cup->id, $a->id));
    expect(cupHttp($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertForbidden()->json('error.code'))->toBe('cup_not_member');

    // He came back: the fresh check of the start makes him active again - the same row, the same scores.
    CupKit::member($a);
    Cache::forget(CupMembershipGate::key($cup->id, $a->id));
    $participantId = CupParticipant::query()->where('learner_id', $a->id)->value('id');
    expect(cupHttp($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json('participant_id'))->toBe($participantId)
        ->and(cupHttp($a, 'GET', "/cups/{$cup->id}")->assertOk()->json('me.status'))->toBe('active')
        ->and(CupParticipant::query()->where('learner_id', $a->id)->count())->toBe(1);
});

it('keeps the two cups of one learner apart: attempts, points and tables', function (): void {
    ['cup' => $first, 'learners' => [$a, $b], 'start' => $now] = CupKit::running(2);
    // The second chat publishes later; both cups run on the same day for this learner.
    $group = CupKit::group();
    $second = CupKit::scheduled($group, $now);
    $c = Learner::factory()->create();
    CupKit::member($c);
    foreach ([$a, $c] as $learner) {
        cupHttp($learner, 'POST', "/cups/{$second->id}/join")->assertCreated();
    }
    $second = Cup::query()->findOrFail($second->id);
    CarbonImmutable::setTestNow(CarbonImmutable::instance($second->starts_at)->addMinute());
    app(CupScheduler::class)->advance(CarbonImmutable::now());

    $one = cupHttp($a, 'POST', "/cups/{$first->id}/rounds/today")->assertSuccessful()->json();
    $two = cupHttp($a, 'POST', "/cups/{$second->id}/rounds/today")->assertCreated()->json();

    expect($one['attempt_id'])->not->toBe($two['attempt_id'])
        ->and($one['cup_id'])->toBe($first->id)
        ->and($two['cup_id'])->toBe($second->id)
        ->and($one['participant_id'])->not->toBe($two['participant_id']);

    $tables = [cupHttp($a, 'GET', "/cups/{$first->id}")->json('standings'), cupHttp($a, 'GET', "/cups/{$second->id}")->json('standings')];
    expect(array_column($tables[0], 'participant_id'))->not->toContain($two['participant_id'])
        ->and(array_column($tables[1], 'participant_id'))->not->toContain($one['participant_id'])
        ->and(cupHttp($a, 'GET', '/cups')->assertOk()->json('cups.*.id'))->toEqualCanonicalizing([$first->id, $second->id]);
});

it('answers every route the same 403 cup_unavailable when the cup is switched off, whatever exists', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $attempt = cupHttp($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json('attempt_id');
    config(['cup.access' => 'off']);
    $missing = cupMissingId();

    $answers = [];
    foreach ([
        ['GET', '/cups'], ['GET', "/cups/by-code/{$cup->public_code}"], ['GET', '/cups/by-code/'.str_repeat('B', 22)],
        ['GET', "/cups/{$cup->id}"], ['GET', "/cups/{$missing}"], ['POST', "/cups/{$cup->id}/join"], ['PATCH', "/cups/{$cup->id}/me"],
        ['GET', "/cups/{$cup->id}/rounds/today"], ['POST', "/cups/{$cup->id}/rounds/today"], ['GET', "/cup-rounds/{$attempt}"],
        ['POST', "/cup-rounds/{$attempt}/answer"], ['GET', "/cup-rounds/{$attempt}/audio/x"], ['GET', "/cups/setup/{$cup->public_code}"],
        ['POST', "/cups/setup/{$cup->public_code}/publish"], ['PATCH', "/cups/{$cup->id}/settings"], ['POST', "/cups/{$cup->id}/cancel"],
    ] as [$method, $path]) {
        $answers["{$method} {$path}"] = [cupHttp($a, $method, $path)->status(), cupHttp($a, $method, $path)->json()];
    }

    expect(array_unique(array_map('json_encode', $answers)))->toHaveCount(1)
        ->and(reset($answers))->toBe([403, ['error' => ['code' => 'cup_unavailable', 'message' => 'The cup is not available in this chat.']]]);
});

it('lets the pilot through only for marked groups - and tells it only to who got through the door', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    config(['cup.access' => 'pilot']);
    $stranger = cupStranger();

    expect(cupHttp($a, 'GET', "/cups/{$cup->id}")->assertForbidden()->json('error.code'))->toBe('cup_unavailable')
        ->and(cupHttp($stranger, 'GET', "/cups/{$cup->id}")->assertForbidden()->json('error.code'))->toBe('cup_not_member');

    $cup->telegramGroup->forceFill(['meta' => ['cup_pilot' => true]])->save();
    cupHttp($a, 'GET', "/cups/{$cup->id}")->assertOk();
});

it('tells a member opening the link why the check failed: the bot is no administrator, Telegram is out, a check is running', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $member = Learner::factory()->create();
    PureCache::store()->flush();
    CupKit::telegram()->chatMemberStatus = 'member'; // the bot was demoted
    CupKit::memberFails($member, TelegramApiException::apiError('Bad Request: member list is inaccessible', 400));
    $notAdmin = cupHttp($member, 'GET', "/cups/by-code/{$cup->public_code}")->assertStatus(409);
    expect($notAdmin->json('error'))->toMatchArray(['code' => 'cup_unverifiable', 'details' => ['reason' => 'bot_not_admin']]);

    CupKit::telegram()->chatMemberStatus = 'administrator';
    PureCache::store()->flush();
    CupKit::memberFails($member, new TelegramApiException('cURL error 28: timeout'));
    expect(cupHttp($member, 'GET', "/cups/by-code/{$cup->public_code}")->assertStatus(503)->json('error'))
        ->toMatchArray(['code' => 'cup_unverifiable', 'details' => ['reason' => 'telegram_unavailable']]);
    // ... while by the numeric id a non-participant gets the 403 of a missing cup for it, the join included (docs/33 §11.7 п. 2:
    // only a definite "member" opens a cup by its id; the reasons are for the link and for participants).
    expect(cupHttp($member, 'POST', "/cups/{$cup->id}/join")->assertForbidden()->json('error.code'))->toBe('cup_not_member')
        ->and(cupHttp($member, 'GET', "/cups/{$cup->id}")->assertForbidden()->json('error.code'))->toBe('cup_not_member');

    // The wait of a busy check runs on the real clock (a frozen one would never time out).
    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();
    config(['cup.membership.wait_seconds' => 1]);
    $lock = Cache::lock("cup:member-check:{$cup->id}:{$member->id}", 10);
    $lock->get();
    expect(cupHttp($member, 'GET', "/cups/by-code/{$cup->public_code}")->assertStatus(429)->json('error.code'))->toBe('cup_check_busy');
    $lock->release();
});

it('refuses a new round in a paused cup and in a cup that has not started', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $cup->telegramGroup->forceFill(['meta' => ['cups_blocked' => true]])->save();
    expect(cupHttp($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertStatus(409)->json('error'))
        ->toMatchArray(['code' => 'cup_paused', 'details' => ['reason' => 'blocked']])
        ->and(cupHttp($a, 'GET', "/cups/{$cup->id}")->assertOk()->json('cup.paused'))->toBe('blocked');

    CarbonImmutable::setTestNow('2026-12-10 06:00:00');
    $later = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $member = Learner::factory()->create();
    CupKit::member($member);
    cupHttp($member, 'POST', "/cups/{$later->id}/join")->assertCreated();
    expect(cupHttp($member, 'POST', "/cups/{$later->id}/rounds/today")->assertStatus(409)->json('error.code'))->toBe('cup_not_started');
});

it('lets only an administrator of the chat set up, publish, change and cancel - asked of Telegram every time', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $group = CupKit::group();
    $draft = app(CupLifecycleService::class)->propose($group, 1, null, CarbonImmutable::now())->cup;
    $member = Learner::factory()->create();
    CupKit::member($member);
    $admin = Learner::factory()->create();
    CupKit::member($admin, 'administrator');

    $refused = cupHttp($member, 'GET', "/cups/setup/{$draft->public_code}")->assertForbidden()->json();
    expect($refused['error']['code'])->toBe('cup_not_chat_admin')
        ->and(cupHttp($member, 'GET', '/cups/setup/'.str_repeat('C', 22))->assertForbidden()->json())->toBe($refused)
        ->and(cupHttp($member, 'POST', "/cups/setup/{$draft->public_code}/publish")->assertForbidden()->json())->toBe($refused);

    cupHttp($admin, 'GET', "/cups/setup/{$draft->public_code}")->assertOk()->assertJsonPath('status', 'draft');
    expect(cupHttp($admin, 'POST', "/cups/setup/{$draft->public_code}/publish", ['level' => 'Z9'])->assertStatus(422)->json('error.code'))->toBe('cup_level_invalid');
    $published = cupHttp($admin, 'POST', "/cups/setup/{$draft->public_code}/publish", ['level' => 'A2', 'locale' => 'uz'])->assertOk();
    expect($published->json('status'))->toBe('scheduled')->and($published->json('locale'))->toBe('uz');
    // A second press is not an error; a member pressing it learns nothing more than "no".
    cupHttp($admin, 'POST', "/cups/setup/{$draft->public_code}/publish")->assertOk()->assertJsonPath('status', 'scheduled');
    expect(cupHttp($member, 'POST', "/cups/setup/{$draft->public_code}/publish")->assertForbidden()->json())->toBe($refused);

    expect(cupHttp($member, 'PATCH', "/cups/{$draft->id}/settings", ['summaries_enabled' => false])->assertForbidden()->json('error.code'))->toBe('cup_not_chat_admin');
    cupHttp($admin, 'PATCH', "/cups/{$draft->id}/settings", ['summaries_enabled' => false, 'summary_minute' => 1260])
        ->assertOk()->assertJsonPath('summaries_enabled', false)->assertJsonPath('summary_time', '21:00');
    expect(cupHttp($admin, 'PATCH', "/cups/{$draft->id}/settings", ['level' => 'A2'])->assertStatus(409)->json('error.code'))->toBe('cup_already_published')
        ->and(cupHttp($admin, 'PATCH', "/cups/{$draft->id}/settings", ['summary_minute' => 2000])->assertStatus(422)->json('errors'))->toHaveKey('summary_minute');

    expect(cupHttp($member, 'POST', "/cups/{$draft->id}/cancel")->assertForbidden()->json('error.code'))->toBe('cup_not_chat_admin');
    cupHttp($admin, 'POST', "/cups/{$draft->id}/cancel")->assertOk()->assertJsonPath('status', 'canceled')->assertJsonPath('cancel_reason', 'chat_admin');
    expect(DB::table('cups')->where('id', $draft->id)->value('cancel_reason'))->toBe('chat_admin');
});

it('does not take the chat admin from the client: the Telegram id is the learner\'s of the JWT', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $draft = app(CupLifecycleService::class)->propose(CupKit::group(), 1, null, CarbonImmutable::now())->cup;
    $member = Learner::factory()->create();
    CupKit::member($member);
    CupKit::admin(999_000_111);

    cupHttp($member, 'POST', "/cups/setup/{$draft->public_code}/publish", ['telegram_user_id' => 999_000_111, 'user_id' => 999_000_111])->assertForbidden();
    expect(Cup::query()->findOrFail($draft->id)->status->value)->toBe('draft')
        ->and(collect(CupKit::telegram()->chatMemberLookups)->pluck('user_id')->unique()->values()->all())->toBe([(int) $member->telegram_user_id]);
});

it('renames a participant up to three times and refuses a non-participant with the 403 of the door', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    foreach (['Ann', 'Anna', 'Annie'] as $i => $name) {
        cupHttp($a, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => $name])->assertOk()->assertJsonPath('renames_left', 2 - $i);
    }
    expect(cupHttp($a, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Anya'])->assertStatus(409)->json('error.code'))->toBe('cup_rename_limit')
        ->and(cupHttp($a, 'PATCH', "/cups/{$cup->id}/me", [])->assertStatus(422)->json('errors'))->toHaveKey('display_name');

    $member = Learner::factory()->create();
    CupKit::member($member);
    expect(cupHttp($member, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Me'])->assertForbidden()->json('error.code'))->toBe('cup_not_member');
});
