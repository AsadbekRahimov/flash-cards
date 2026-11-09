<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\BotMenuBuilder;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\ClassCaravan;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Game\ClassKit;
use Tests\Support\Game\GameKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.11, docs/04 B.3 / B.15 (G2.9b): `/start_caravan` and `/cancel_caravan` in a group - the teacher of the group or an admin; the
 * refusals by reason; the message with the button of the Mini App; the menu; and the silence while GAME_ACCESS=off.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    config(['game.class.centers_only' => true, 'twa.base_url' => 'https://twa.test', 'telegram.bot_username' => 'lexibot']);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function caravanSays(string $text, int $chatId = -1001234, int $fromId = 777, string $type = 'supergroup'): void
{
    app(TelegramDispatcher::class)->dispatch([
        'message' => ['chat' => ['id' => $chatId, 'type' => $type], 'from' => ['id' => $fromId], 'text' => $text],
    ]);
}

it('starts the caravan for the teacher of the group and posts the message with the button of the Mini App', function (): void {
    $w = ClassKit::world(3);

    caravanSays('/start_caravan');

    $caravan = ClassCaravan::query()->sole();
    expect($caravan->status)->toBe(ClassCaravanStatus::Active)
        ->and($caravan->telegram_group_id)->toBe($w['group']->id)
        ->and($caravan->started_by_user_id)->toBe($w['teacher']->id)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(-1001234)
        ->and($this->tg->sent[0]['text'])->toContain('Караван класса отправился')->toContain('18 ⭐')
        ->and($this->tg->sent[0]['button'])->toBe('Открыть караван')
        ->and($this->tg->sent[0]['url'])->toBe("https://twa.test/twa/game/class/{$caravan->id}")
        ->and($this->tg->immediateButtons)->toBe([-1001234])
        ->and(ProductEvent::query()->where('name', ProductEventName::GameClassStarted->value)->count())->toBe(1);
});

it('says until when the caravan is on its way, in the timezone of learners', function (): void {
    ClassKit::world(2);

    caravanSays('/start_caravan@lexibot');

    expect($this->tg->sent[0]['text'])->toContain('до 12.11 15:00');
});

it('speaks the language of the group (uz)', function (): void {
    $w = ClassKit::world(2);
    $w['group']->forceFill(['meta' => ['locale' => 'uz']])->save();

    caravanSays('/start_caravan');

    expect($this->tg->sent[0]['text'])->toContain('Sinf karvoni yo\'lga chiqdi')->and($this->tg->sent[0]['button'])->toBe('Karvonni ochish');
});

it('lets an admin who is not a teacher of the group start it', function (): void {
    ClassKit::world(2);
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true, 'telegram_user_id' => 555]);
    $admin->assignRole('admin');

    caravanSays('/start_caravan', fromId: 555);

    expect(ClassCaravan::query()->sole()->started_by_user_id)->toBe($admin->id);
});

it('refuses a user who is not a teacher of the group', function (): void {
    ClassKit::world(2);
    User::factory()->create(['is_active' => true, 'telegram_user_id' => 888]);

    caravanSays('/start_caravan', fromId: 888);

    expect(ClassCaravan::query()->count())->toBe(0)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('только учитель этой группы');
});

it('tells a sender who is not linked to a user that the Telegram account is not linked', function (): void {
    ClassKit::world(2);

    caravanSays('/start_caravan', fromId: 999);

    expect(ClassCaravan::query()->count())->toBe(0)
        ->and($this->tg->sent[0]['text'])->toBe(__('errors.teacher_not_linked', [], 'ru'));
});

it('answers "for centers" to a group that does not belong to a center, and the same to a center without a live subscription', function (): void {
    $w = ClassKit::world(2);
    $w['group']->forceFill(['organization_id' => null])->save();

    caravanSays('/start_caravan');

    expect(ClassCaravan::query()->count())->toBe(0)
        ->and($this->tg->sent[0]['text'])->toBe(__('game.class.centers_only', [], 'ru'));

    $w['group']->forceFill(['organization_id' => ClassKit::center()->id])->save();
    DB::table('subscriptions')->where('subscriber_type', 'organization')->update(['status' => 'canceled', 'current_period_ends_at' => now()->subDay()]);

    caravanSays('/start_caravan');

    expect(ClassCaravan::query()->count())->toBe(0)->and($this->tg->sent)->toHaveCount(2)
        ->and($this->tg->sent[1]['text'])->toBe(__('game.class.centers_only', [], 'ru'));
});

it('starts a caravan for a group without a center when centers_only is off', function (): void {
    $w = ClassKit::world(2);
    $w['group']->forceFill(['organization_id' => null])->save();
    config(['game.class.centers_only' => false]);

    caravanSays('/start_caravan');

    expect(ClassCaravan::query()->count())->toBe(1);
});

it('keeps silent in a group that is not active (the group lock) and starts nothing', function (): void {
    $w = ClassKit::world(2);
    $w['group']->forceFill(['status' => 'pending'])->save();

    caravanSays('/start_caravan');

    expect(ClassCaravan::query()->count())->toBe(0)->and($this->tg->sent)->toBe([]);
});

it('answers that the caravan is on its way, until when, to a second start', function (): void {
    ClassKit::world(2);

    caravanSays('/start_caravan');
    caravanSays('/start_caravan');

    expect(ClassCaravan::query()->count())->toBe(1)
        ->and($this->tg->sent)->toHaveCount(2)
        ->and($this->tg->sent[1]['text'])->toBe('Караван этой группы уже в пути — до 12.11 15:00.');
});

it('withdraws the caravan when Telegram refuses the message, so that a retry works', function (): void {
    ClassKit::world(2);
    $this->tg->failSends = 1;

    caravanSays('/start_caravan');

    $caravan = ClassCaravan::query()->sole();
    expect($caravan->status)->toBe(ClassCaravanStatus::Canceled)
        ->and($caravan->active_group_id)->toBeNull()
        ->and($caravan->meta['canceled_reason'])->toBe('not_delivered')
        ->and($this->tg->sent[0]['text'])->toBe(__('game.class.not_delivered', [], 'ru'));

    caravanSays('/start_caravan');

    expect(ClassCaravan::query()->where('status', 'active')->count())->toBe(1);
});

it('cancels the caravan on /cancel_caravan and says so; there are no summary and no other message', function (): void {
    $w = ClassKit::world(2);
    caravanSays('/start_caravan');
    $this->tg->sent = [];

    caravanSays('/cancel_caravan');

    $caravan = ClassCaravan::query()->sole();
    expect($caravan->status)->toBe(ClassCaravanStatus::Canceled)
        ->and($caravan->active_group_id)->toBeNull()
        ->and($caravan->meta['canceled_by_user_id'])->toBe($w['teacher']->id)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('Караван класса отменён');
});

it('keeps the cancellation when Telegram refuses the message "canceled", and says it once when it is accepted', function (): void {
    ClassKit::world(2);
    caravanSays('/start_caravan');
    $this->tg->sent = [];
    $this->tg->failSends = 1;

    caravanSays('/cancel_caravan');

    expect(ClassCaravan::query()->sole()->status)->toBe(ClassCaravanStatus::Canceled)
        ->and($this->tg->sent)->toBe([]);

    caravanSays('/cancel_caravan');

    expect($this->tg->sent)->toHaveCount(1)->and($this->tg->sent[0]['text'])->toBe(__('game.class.no_active', [], 'ru'));
});

it('does not say "canceled" when the scheduler has closed the caravan under the command, nor on a repeated cancel', function (): void {
    $w = ClassKit::world(2);
    caravanSays('/start_caravan');
    $caravan = ClassCaravan::query()->sole();
    $this->tg->sent = [];

    // the handler read the caravan as active; the sweep finished it before the cancel took its lock
    $stale = $caravan->fresh();
    ClassCaravan::query()->whereKey($caravan->id)->update(['status' => 'finished', 'active_group_id' => null, 'finished_at' => now()]);
    $first = app(ClassCaravanService::class)->cancel($stale, $w['teacher']);

    expect($first->canceledNow)->toBeFalse()
        ->and($first->caravan->status)->toBe(ClassCaravanStatus::Finished);

    // two cancels one after another: only the first one has canceled
    caravanSays('/start_caravan');
    $second = ClassCaravan::query()->where('status', 'active')->sole();
    $one = app(ClassCaravanService::class)->cancel($second, $w['teacher']);
    $two = app(ClassCaravanService::class)->cancel($second, $w['teacher']);

    expect([$one->canceledNow, $two->canceledNow])->toBe([true, false])
        ->and($this->tg->sent)->toHaveCount(1); // only the new "has set off"
});

it('answers "no caravan on its way" to /cancel_caravan of a group without one', function (): void {
    ClassKit::world(2);

    caravanSays('/cancel_caravan');

    expect($this->tg->sent)->toHaveCount(1)->and($this->tg->sent[0]['text'])->toBe(__('game.class.no_active', [], 'ru'));
});

it('does not let a stranger cancel the caravan', function (): void {
    ClassKit::world(2);
    caravanSays('/start_caravan');
    User::factory()->create(['is_active' => true, 'telegram_user_id' => 888]);
    $this->tg->sent = [];

    caravanSays('/cancel_caravan', fromId: 888);

    expect(ClassCaravan::query()->sole()->status)->toBe(ClassCaravanStatus::Active)
        ->and($this->tg->sent[0]['text'])->toContain('только учитель этой группы');
});

it('ignores the commands outside a group', function (): void {
    ClassKit::world(2);

    caravanSays('/start_caravan', chatId: 777, type: 'private');

    expect(ClassCaravan::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------- GAME_ACCESS

it('does not exist while GAME_ACCESS is off: no answer, no caravan, not a query to the game tables', function (): void {
    ClassKit::world(2);
    config(['game.access' => 'off']);
    $queries = QueryLog::during(function (): void {
        caravanSays('/start_caravan');
        caravanSays('/cancel_caravan');
    });

    expect($this->tg->sent)->toBe([])
        ->and(QueryLog::matching($queries, '/class_caravan|game_/'))->toBe([]);
    expect(ClassCaravan::query()->count())->toBe(0);
});

it('works only in the open mode (GAME_ACCESS=all)', function (): void {
    ClassKit::world(2);
    config(['game.access' => 'all']);

    caravanSays('/start_caravan');

    expect(ClassCaravan::query()->count())->toBe(1);
});

it('keeps silent in the pilot mode, as it does while the game is off: the caravan is for the whole group', function (string $command): void {
    $w = ClassKit::world(2);
    $w['learners'][0]->forceFill(['practice_pilot_at' => now()])->save();
    $active = ClassCaravan::factory()->create(['telegram_group_id' => $w['group']->id, 'organization_id' => $w['organization']->id, 'course_id' => $w['course']->id, 'active_group_id' => $w['group']->id]);
    config(['game.access' => 'pilot']);

    caravanSays($command);

    expect($this->tg->sent)->toBe([])
        ->and(ClassCaravan::query()->count())->toBe(1)
        ->and($active->fresh()?->status)->toBe(ClassCaravanStatus::Active);
})->with(['/start_caravan', '/cancel_caravan']);

// ---------------------------------------------------------------------- the menu and /help

it('lists the caravan commands in the list of the group administrators only, before /help, while the game is open', function (): void {
    foreach (app(BotMenuBuilder::class)->commandSets() as $set) {
        $names = array_column($set['commands'], 'command');

        if ($set['scope']['type'] === 'all_chat_administrators') {
            expect($names)->toBe(['start_training', 'start_exam', 'close_exam', 'start_caravan', 'cancel_caravan', 'help']);
            foreach ($set['commands'] as $command) {
                expect($command['description'])->not->toBe('');
            }

            continue;
        }

        expect(array_intersect($names, ['start_caravan', 'cancel_caravan']))->toBe([]);
    }
});

it('does not list the caravan commands in the menu while GAME_ACCESS is off', function (): void {
    config(['game.access' => 'off']);

    foreach (app(BotMenuBuilder::class)->commandSets() as $set) {
        expect(array_column($set['commands'], 'command'))->not->toContain('start_caravan')->not->toContain('cancel_caravan');
    }
});

it('does not list the caravan commands in the menu in the pilot mode either', function (): void {
    config(['game.access' => 'pilot']);

    foreach (app(BotMenuBuilder::class)->commandSets() as $set) {
        expect(array_column($set['commands'], 'command'))->not->toContain('start_caravan')->not->toContain('cancel_caravan');
    }
});

it('lists the caravan commands in the private /help (for teachers) only with GAME_ACCESS=all', function (): void {
    User::factory()->create(['telegram_user_id' => 777]);

    foreach (['all', 'pilot', 'off'] as $mode) {
        config(['game.access' => $mode]);
        caravanSays('/help', chatId: 555, type: 'private');
    }

    expect($this->tg->sent[0]['text'])->toContain('/start_caravan')->toContain('/cancel_caravan')->not->toContain(':caravan')
        ->and($this->tg->sent[1]['text'])->not->toContain('caravan')->not->toContain(':caravan')
        ->and($this->tg->sent[2]['text'])->not->toContain('caravan')->not->toContain(':caravan');
});

it('lists the caravan commands in /help of a group only with GAME_ACCESS=all', function (): void {
    ClassKit::world(2);

    caravanSays('/help');
    config(['game.access' => 'off']);
    caravanSays('/help');
    config(['game.access' => 'pilot']);
    caravanSays('/help');

    expect($this->tg->sent[0]['text'])->toContain('/start_caravan')->toContain('/cancel_caravan')
        ->and($this->tg->sent[1]['text'])->not->toContain('caravan')->not->toContain(':caravan')
        ->and($this->tg->sent[2]['text'])->not->toContain('caravan')->not->toContain(':caravan');
});
