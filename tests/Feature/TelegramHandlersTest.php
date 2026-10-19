<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->api = Mockery::mock(TelegramClient::class);
    $this->app->instance(TelegramClient::class, $this->api);
});

it('creates a pending TelegramGroup on my_chat_member into a group', function (): void {
    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -10012345, 'type' => 'supergroup', 'title' => 'Class 1'],
            'new_chat_member' => ['status' => 'member'],
        ],
    ]);

    $group = TelegramGroup::where('chat_id', -10012345)->firstOrFail();
    expect($group->status)->toBe('pending');
    expect($group->title)->toBe('Class 1');
});

it('keeps an active group active on subsequent my_chat_member updates', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -10012345, 'status' => 'active', 'title' => 'Old title']);

    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -10012345, 'type' => 'supergroup', 'title' => 'Renamed class'],
            'new_chat_member' => ['status' => 'administrator'],
        ],
    ]);

    $group = TelegramGroup::where('chat_id', -10012345)->firstOrFail();
    expect($group->status)->toBe('active');
    expect($group->title)->toBe('Renamed class');
});

it('marks group as disabled when bot is kicked', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -9999, 'status' => 'active']);

    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -9999, 'type' => 'supergroup', 'title' => 'X'],
            'new_chat_member' => ['status' => 'kicked'],
        ],
    ]);

    expect(TelegramGroup::where('chat_id', -9999)->value('status'))->toBe('disabled');
});

it('drops the sponsored Pro of the members at once when the bot is removed from the group', function (): void {
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null]);
    $group = TelegramGroup::factory()->create(['chat_id' => -9998, 'status' => 'active', 'organization_id' => $org->id]);
    $member = Learner::factory()->memberOf($group)->create();
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($member)->plan)->toBe(Plan::Pro);   // primes the cache

    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -9998, 'type' => 'supergroup', 'title' => 'X'],
            'new_chat_member' => ['status' => 'left'],
        ],
    ]);

    expect($group->fresh()?->status)->toBe('disabled')
        ->and($resolver->for($member)->plan)->toBe(Plan::Free);
});

it('creates a learner and an active membership for every human in new_chat_members', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7777, 'status' => 'active']);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7777, 'type' => 'supergroup'],
            'new_chat_members' => [
                ['id' => 111, 'first_name' => 'Alice', 'language_code' => 'uz', 'is_bot' => false],
                ['id' => 222, 'first_name' => 'Bob', 'username' => 'bobby', 'is_bot' => false],
                ['id' => 333, 'first_name' => 'Bot', 'is_bot' => true],
            ],
        ],
    ]);

    expect(Learner::count())->toBe(2)
        ->and(GroupMember::where('telegram_group_id', $group->id)->where('status', 'active')->count())->toBe(2)
        ->and(Learner::where('telegram_user_id', 222)->value('username'))->toBe('bobby')
        ->and(Learner::where('telegram_user_id', 333)->exists())->toBeFalse();

    // Arrived through a group, not through /start in DM: onboarding is still ahead of them.
    $alice = Learner::where('telegram_user_id', 111)->sole();
    expect($alice->acquisition_source)->toBe('group')
        ->and($alice->onboarding_state)->toBe('new')
        ->and($alice->interface_locale)->toBe('uz');
});

it('keeps ONE learner when the same person joins a second group', function (): void {
    $groupA = TelegramGroup::factory()->create(['chat_id' => -7001, 'status' => 'active']);
    $groupB = TelegramGroup::factory()->create(['chat_id' => -7002, 'status' => 'active']);
    $join = fn (int $chatId, string $firstName) => app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => $chatId, 'type' => 'supergroup'],
            'new_chat_members' => [['id' => 111, 'first_name' => $firstName, 'username' => 'alice_u', 'is_bot' => false]],
        ],
    ]);

    $join(-7001, 'Alice');
    $join(-7002, 'Alicia');

    $learner = Learner::where('telegram_user_id', 111)->sole();
    expect(Learner::count())->toBe(1)
        ->and($learner->first_name)->toBe('Alicia')
        ->and($learner->acquisition_source)->toBe('group')
        ->and(GroupMember::where('learner_id', $learner->id)->pluck('telegram_group_id')->sort()->values()->all())
        ->toBe([$groupA->id, $groupB->id]);
});

it('re-activates a membership when a person who left joins again', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7003, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group, 'left')->create(['telegram_user_id' => 111]);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7003, 'type' => 'supergroup'],
            'new_chat_members' => [['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
        ],
    ]);

    $member = GroupMember::where('learner_id', $learner->id)->sole();
    expect($member->status)->toBe('active')
        ->and($member->left_at)->toBeNull()
        ->and(GroupMember::count())->toBe(1);
});

it('ignores new_chat_members of an unknown group', function (): void {
    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7999, 'type' => 'supergroup'],
            'new_chat_members' => [['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
        ],
    ]);

    expect(Learner::count())->toBe(0)->and(GroupMember::count())->toBe(0);
});

it('marks the membership as left on a chat_member left update', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7004, 'status' => 'active']);
    $other = TelegramGroup::factory()->create(['chat_id' => -7005, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->memberOf($other)->create(['telegram_user_id' => 111]);

    app(TelegramDispatcher::class)->dispatch([
        'chat_member' => [
            'chat' => ['id' => -7004, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'left', 'user' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
        ],
    ]);

    $left = GroupMember::where('learner_id', $learner->id)->where('telegram_group_id', $group->id)->sole();
    expect($left->status)->toBe('left')
        ->and($left->left_at)->not->toBeNull()
        // The other group is untouched; the learner (and their progress) stays.
        ->and(GroupMember::where('learner_id', $learner->id)->where('telegram_group_id', $other->id)->value('status'))->toBe('active')
        ->and(Learner::count())->toBe(1);
});

it('marks the membership as removed on a chat_member kicked update', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7006, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 111]);

    app(TelegramDispatcher::class)->dispatch([
        'chat_member' => [
            'chat' => ['id' => -7006, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
        ],
    ]);

    expect(GroupMember::where('learner_id', $learner->id)->value('status'))->toBe('removed');
});

it('creates the membership on a chat_member join update and does not create learners on leave', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7007, 'status' => 'active']);
    $update = fn (string $status, int $userId) => ['chat_member' => [
        'chat' => ['id' => -7007, 'type' => 'supergroup'],
        'new_chat_member' => ['status' => $status, 'user' => ['id' => $userId, 'first_name' => 'X', 'is_bot' => false]],
    ]];

    app(TelegramDispatcher::class)->dispatch($update('member', 111));
    app(TelegramDispatcher::class)->dispatch($update('left', 222)); // never seen before

    expect(Learner::pluck('telegram_user_id')->all())->toBe([111])
        ->and(GroupMember::where('telegram_group_id', $group->id)->where('status', 'active')->count())->toBe(1);
});

it('ignores chat_member updates of bots, unknown groups and private chats', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -7008, 'status' => 'active']);

    $dispatch = fn (array $chat, array $user) => app(TelegramDispatcher::class)->dispatch(['chat_member' => [
        'chat' => $chat,
        'new_chat_member' => ['status' => 'member', 'user' => $user],
    ]]);

    $dispatch(['id' => -7008, 'type' => 'supergroup'], ['id' => 900, 'first_name' => 'Bot', 'is_bot' => true]);
    $dispatch(['id' => -7777777, 'type' => 'supergroup'], ['id' => 111, 'first_name' => 'A', 'is_bot' => false]);
    $dispatch(['id' => 111, 'type' => 'private'], ['id' => 111, 'first_name' => 'A', 'is_bot' => false]);

    expect(Learner::count())->toBe(0)->and(GroupMember::count())->toBe(0);
});

it('ends the membership on a left_chat_member service message (bot without admin rights)', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7010, 'status' => 'active']);
    $other = TelegramGroup::factory()->create(['chat_id' => -7011, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->memberOf($other)->create(['telegram_user_id' => 111]);
    $date = now()->addMinute()->getTimestamp();

    $dispatch = fn () => app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => -7010, 'type' => 'supergroup'],
        'from' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false],
        'date' => $date,
        'left_chat_member' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false],
    ]]);

    $dispatch();
    $left = GroupMember::where('learner_id', $learner->id)->where('telegram_group_id', $group->id)->sole();
    expect($left->status)->toBe('left')
        ->and($left->left_at?->getTimestamp())->toBe($date)
        ->and(GroupMember::where('learner_id', $learner->id)->where('telegram_group_id', $other->id)->value('status'))->toBe('active');

    // A repeated delivery is a no-op: the recorded leave is not overwritten.
    $dispatch();
    expect($left->fresh()?->left_at?->getTimestamp())->toBe($date)
        ->and(DB::table('product_events')->where('name', 'group.member_left')->count())->toBe(1);
});

it('records a member removed by an admin as removed', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7012, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 111]);

    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => -7012, 'type' => 'supergroup'],
        'from' => ['id' => 5, 'first_name' => 'Teacher', 'is_bot' => false],
        'date' => now()->addMinute()->getTimestamp(),
        'left_chat_member' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false],
    ]]);

    expect(GroupMember::where('learner_id', $learner->id)->value('status'))->toBe('removed');
});

it('ignores left_chat_member of the bot itself, of an unknown group and of a stranger', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7013, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 111]);

    $dispatch = fn (int $chatId, array $user) => app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $chatId, 'type' => 'supergroup'],
        'date' => now()->addMinute()->getTimestamp(),
        'left_chat_member' => $user,
    ]]);

    $dispatch(-7013, ['id' => 900, 'first_name' => 'LexiBot', 'is_bot' => true]);
    $dispatch(-7777777, ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]);
    $dispatch(-7013, ['id' => 222, 'first_name' => 'Stranger', 'is_bot' => false]);   // never seen: no learner created

    expect(GroupMember::where('learner_id', $learner->id)->value('status'))->toBe('active')
        ->and(Learner::count())->toBe(1);
});

it('treats a restricted chat_member without membership as a leave and a restricted member as staying', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7009, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 111]);
    $restricted = fn (array $extra) => ['chat_member' => [
        'chat' => ['id' => -7009, 'type' => 'supergroup'],
        'new_chat_member' => ['status' => 'restricted', 'user' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false], ...$extra],
    ]];

    app(TelegramDispatcher::class)->dispatch($restricted(['is_member' => true]));
    expect(GroupMember::where('learner_id', $learner->id)->value('status'))->toBe('active');

    app(TelegramDispatcher::class)->dispatch($restricted(['is_member' => false]));
    $member = GroupMember::where('learner_id', $learner->id)->sole();
    expect($member->status)->toBe('left')->and($member->left_at)->not->toBeNull();

    // is_member absent is not "still a member" either.
    $member->update(['status' => 'active', 'left_at' => null]);
    app(TelegramDispatcher::class)->dispatch($restricted([]));
    expect($member->fresh()->status)->toBe('left');

    // ...and a restricted member who is back inside re-activates.
    app(TelegramDispatcher::class)->dispatch($restricted(['is_member' => true, 'date' => now()->addMinute()->timestamp]));
    expect($member->fresh()->status)->toBe('active');
});

it('does not revive a soft-deleted learner nor create a membership on passive group events', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7010, 'status' => 'active']);
    $learner = Learner::factory()->create(['telegram_user_id' => 111]);
    $learner->delete();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7010, 'type' => 'supergroup'],
            'new_chat_members' => [['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
        ],
    ]);
    app(TelegramDispatcher::class)->dispatch(['chat_member' => [
        'chat' => ['id' => -7010, 'type' => 'supergroup'],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
    ]]);

    expect(Learner::withTrashed()->count())->toBe(1)
        ->and(Learner::withTrashed()->find($learner->id)?->trashed())->toBeTrue()
        ->and(GroupMember::count())->toBe(0)
        ->and($group->members()->count())->toBe(0);
});

it('does not touch last_seen_at on passive group events', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -7011, 'status' => 'active']);
    $old = now()->subDays(9)->startOfSecond();
    $learner = Learner::factory()->create(['telegram_user_id' => 111, 'last_seen_at' => $old]);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7011, 'type' => 'supergroup'],
            'new_chat_members' => [['id' => 111, 'first_name' => 'Alice', 'is_bot' => false], ['id' => 222, 'first_name' => 'Bob', 'is_bot' => false]],
        ],
    ]);
    app(TelegramDispatcher::class)->dispatch(['chat_member' => [
        'chat' => ['id' => -7011, 'type' => 'supergroup'],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
    ]]);

    expect($learner->fresh()->last_seen_at?->equalTo($old))->toBeTrue()
        ->and(Learner::where('telegram_user_id', 222)->value('last_seen_at'))->toBeNull();
});

it('applies chat_member and new_chat_members by their Telegram date, not by arrival order', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -7012, 'status' => 'active']);
    $base = now()->subHour()->startOfSecond()->timestamp;
    $user = ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false];
    $chatMember = fn (string $status, int $date) => ['chat_member' => [
        'chat' => ['id' => -7012, 'type' => 'supergroup'],
        'date' => $date,
        'new_chat_member' => ['status' => $status, 'user' => $user],
    ]];

    app(TelegramDispatcher::class)->dispatch($chatMember('member', $base));
    app(TelegramDispatcher::class)->dispatch($chatMember('left', $base + 10));
    // Delivered late: the join happened (t+5) before the leave (t+10).
    app(TelegramDispatcher::class)->dispatch($chatMember('member', $base + 5));
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => -7012, 'type' => 'supergroup'],
        'date' => $base + 6,
        'new_chat_members' => [$user],
    ]]);

    expect(GroupMember::where('telegram_group_id', $group->id)->sole()->status)->toBe('left');

    app(TelegramDispatcher::class)->dispatch($chatMember('member', $base + 20));
    expect(GroupMember::where('telegram_group_id', $group->id)->sole()->status)->toBe('active');

    // A stale leave does not end the newer membership.
    app(TelegramDispatcher::class)->dispatch($chatMember('left', $base + 15));
    expect(GroupMember::where('telegram_group_id', $group->id)->sole()->status)->toBe('active');
});

it('responds to /help in private chat', function (): void {
    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(500, Mockery::pattern('/LexiFlow/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 500, 'type' => 'private'],
            'text' => '/help',
        ],
    ]);
});

it('responds to /help in an active whitelisted group', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -5001, 'status' => 'active']);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(-5001, Mockery::pattern('/LexiFlow Bot в группе/u'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -5001, 'type' => 'supergroup'],
            'text' => '/help',
        ],
    ]);
});

it('stays silent on /help in an unknown or inactive group (group lock)', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -5002, 'status' => 'pending']);

    $this->api->shouldReceive('sendMessage')->never();

    // Inactive (pending) group.
    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -5002, 'type' => 'supergroup'],
            'text' => '/help',
        ],
    ]);

    // Completely unknown group.
    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -9090, 'type' => 'supergroup'],
            'text' => '/help',
        ],
    ]);
});

it('binds a teacher on /start in DM when telegram_user_id matches', function (): void {
    $teacher = User::factory()->create(['telegram_user_id' => 12345, 'last_login_at' => null]);

    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(12345, Mockery::pattern('/Привет/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 12345, 'type' => 'private'],
            'from' => ['id' => 12345],
            'text' => '/start',
        ],
    ]);

    expect($teacher->fresh()->last_login_at)->not->toBeNull();
});

it('tells unknown TG user their ID on /start', function (): void {
    $this->api->shouldReceive('sendMessage')
        ->once()
        ->with(999, Mockery::pattern('/999/'));

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 999, 'type' => 'private'],
            'from' => ['id' => 999],
            'text' => '/start',
        ],
    ]);
});

it('sends a seven-day stats report to a teacher in DM', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 54321]);
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $learner = Learner::factory()->memberOf($group)->create();
    $groupSession = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
    TrainingReview::factory()->count(3)->create([
        'training_session_id' => $groupSession->id,
        'learner_id' => $learner->id,
        'created_at' => now()->subDays(2),
    ]);
    // The learner's personal practice is private: the teacher's report never counts it.
    TrainingReview::factory()->count(4)->create([
        'training_session_id' => TrainingSession::factory()->personal($learner)->create()->id,
        'learner_id' => $learner->id,
        'created_at' => now()->subDays(2),
    ]);

    $api = new class implements TelegramClient
    {
        /** @var list<array{chat_id:int|string,text:string}> */
        public array $messages = [];

        /** @param array<string, mixed>|null $replyMarkup */
        public function sendMessage(
            int|string $chatId,
            string $text,
            ?string $parseMode = null,
            ?array $replyMarkup = null,
        ): void {
            $this->messages[] = ['chat_id' => $chatId, 'text' => $text];
        }

        public function sendWebAppButton(
            int|string $chatId,
            string $text,
            string $buttonText,
            string $url,
            ?string $parseMode = null,
        ): void {
            $this->messages[] = ['chat_id' => $chatId, 'text' => $text];
        }

        public function answerCallbackQuery(string $callbackQueryId): void {}

        public function setWebhook(string $url, string $secretHeader): bool
        {
            return true;
        }

        public function deleteWebhook(): bool
        {
            return true;
        }
    };

    app()->instance(TelegramClient::class, $api);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => 54321, 'type' => 'private'],
            'from' => ['id' => 54321],
            'text' => '/stats',
        ],
    ]);

    expect($api->messages)->toHaveCount(1);
    expect($api->messages[0]['chat_id'])->toBe(54321);
    expect($api->messages[0]['text'])->toMatch('/Статистика LexiFlow/u')
        ->and($api->messages[0]['text'])->toContain('Повторений карточек: 3')
        ->and($api->messages[0]['text'])->toContain('Активных студентов: 1');
});
