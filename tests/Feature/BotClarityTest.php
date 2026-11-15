<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\HelpCommandHandler;
use App\Domain\Telegram\Services\BotSupportContact;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\ExamAnswer;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test', 'telegram.bot_token' => '777000111:AAEhBPsecretsecretsecretsecretsecret12', 'billing.paywall.contact' => '']);
    Cache::flush();

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $message */
function clarityMessage(array $message): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => $message]);
}

function clarityGroupText(int $chatId, int $fromId, string $text): void
{
    clarityMessage(['chat' => ['id' => $chatId, 'type' => 'supergroup'], 'from' => ['id' => $fromId], 'text' => $text]);
}

function clarityDm(int $fromId, string $text, string $lang = 'ru'): void
{
    clarityMessage(['chat' => ['id' => $fromId, 'type' => 'private'], 'from' => ['id' => $fromId, 'first_name' => 'Ali', 'language_code' => $lang], 'text' => $text]);
}

function clarityTeacher(int $tgId, ?TelegramGroup $group = null): User
{
    $teacher = User::factory()->create(['telegram_user_id' => $tgId]);
    if ($group !== null) {
        DB::table('teacher_groups')->insert(['user_id' => $teacher->id, 'telegram_group_id' => $group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $teacher;
}

/** @param array<string, mixed> $overrides */
function clarityBotAdded(int $chatId, array $overrides = []): void
{
    app(TelegramDispatcher::class)->dispatch(['my_chat_member' => array_replace_recursive([
        'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Class 1'],
        'from' => ['id' => 4242],
        'old_chat_member' => ['status' => 'left'],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 777000111, 'is_bot' => true]],
    ], $overrides)]);
}

// ---------------------------------------------------------------- 1. silent group

it('welcomes a group the bot was added to with one message: next steps and how a center links it', function (): void {
    $this->tg->chatMemberStatus = 'administrator';

    clarityBotAdded(-100501);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(-100501)
        ->and($this->tg->sent[0]['text'])->toContain('LexiFlow добавлен в группу «Class 1»')
        ->and($this->tg->sent[0]['text'])->toContain('Привяжите её из кабинета центра')->not->toContain('-100501')
        ->and($this->tg->sent[0]['text'])->not->toContain('Поддержка:');
});

it('adds the support contact to the welcome only when one is configured', function (): void {
    config(['billing.paywall.contact' => '@lexi_help']);

    clarityBotAdded(-100502);

    expect($this->tg->sent[0]['text'])->toContain('Поддержка: @lexi_help');
});

it('merges the admin warning into the welcome: no two messages in a row', function (): void {
    $this->tg->chatMemberStatus = 'member';

    clarityBotAdded(-100503);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('Привяжите её из кабинета центра')
        ->and($this->tg->sent[0]['text'])->toContain('Группа → Управление → Администраторы');
});

it('escapes the group title and falls back to a localized one when there is none', function (): void {
    clarityBotAdded(-100504, ['chat' => ['title' => 'A <b>&</b> B']]);
    clarityBotAdded(-100505, ['chat' => ['title' => null]]);

    expect($this->tg->sent[0]['text'])->toContain('«A &lt;b&gt;&amp;&lt;/b&gt; B»')
        ->and(TelegramGroup::query()->where('chat_id', -100505)->value('title'))->toBe('Группа без названия')
        ->and($this->tg->sent[1]['text'])->toContain('«Группа без названия»');
});

it('welcomes in the language of a pending group and does not welcome an active one again', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100506, 'status' => 'disabled', 'meta' => ['locale' => 'uz']]);
    TelegramGroup::factory()->create(['chat_id' => -100507, 'status' => 'active']);

    clarityBotAdded(-100507);
    expect($this->tg->sent)->toBe([]);

    TelegramGroup::query()->where('chat_id', -100506)->update(['status' => 'pending']);
    clarityBotAdded(-100506);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain("guruhiga qo'shildi");
});

it('answers a teacher command in a pending group once an hour and /help always, pointing to the cabinet', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100510, 'status' => 'pending']);

    clarityGroupText(-100510, 1, '/start_training');
    clarityGroupText(-100510, 1, '/start_exam');
    clarityGroupText(-100510, 2, '/close_exam');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Группа ещё не активирована, поэтому команды пока не работают.');

    clarityGroupText(-100510, 1, '/help');
    clarityGroupText(-100510, 1, '/help@LexiBot');

    expect($this->tg->sent)->toHaveCount(3)
        ->and($this->tg->sent[1]['text'])->toContain('Привяжите её из кабинета центра')
        ->and($this->tg->sent[2]['text'])->toContain('Привяжите её из кабинета центра')->not->toContain('-100510');

    // The flag is per group and lives an hour.
    TelegramGroup::factory()->create(['chat_id' => -100511, 'status' => 'pending']);
    clarityGroupText(-100511, 1, '/start_training');
    expect($this->tg->sent)->toHaveCount(4);

    $this->travel(61)->minutes();
    clarityGroupText(-100510, 1, '/start_training');
    expect($this->tg->sent)->toHaveCount(5);
});

it('stays silent in a disabled or unknown group, for other commands and for plain text', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100512, 'status' => 'disabled']);
    TelegramGroup::factory()->create(['chat_id' => -100513, 'status' => 'pending']);

    clarityGroupText(-100512, 1, '/start_training');
    clarityGroupText(-100513, 1, '/paysupport');
    clarityGroupText(-100513, 1, 'hello');
    expect($this->tg->sent)->toBe([]);

    clarityGroupText(-100999, 1, '/help');
    clarityGroupText(-100999, 1, '/start_exam');
    expect($this->tg->sent)->toBe([]);
});

it('adds the support contact to the pending-group answer when configured', function (): void {
    config(['billing.paywall.contact' => 'help@lexiflow.test']);
    TelegramGroup::factory()->create(['chat_id' => -100514, 'status' => 'pending']);

    clarityGroupText(-100514, 1, '/start_exam');

    expect($this->tg->sent[0]['text'])->toContain('Поддержка: help@lexiflow.test');
});

it('points /help of an active group to the cabinet for linking and ignores /helpme', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100515, 'status' => 'active']);

    clarityGroupText(-100515, 1, '/help');
    expect($this->tg->sent)->toHaveCount(1)->and($this->tg->sent[0]['text'])->toContain('Привяжите её из кабинета центра')->not->toContain('-100515');

    $handler = app(HelpCommandHandler::class);
    expect($handler->matches(['message' => ['text' => '/helpme']]))->toBeFalse()
        ->and($handler->matches(['message' => ['text' => '/help@LexiBot']]))->toBeTrue()
        ->and($handler->matches(['message' => ['text' => '/help']]))->toBeTrue();
});

it('explains a group that is not active when a teacher command reaches the services', function (): void {
    config(['billing.paywall.contact' => '@lexi_help']);
    $group = TelegramGroup::factory()->create(['chat_id' => -100516, 'status' => 'active']);
    clarityTeacher(900, $group);
    $group->update(['status' => 'pending']);

    // The dispatcher answers instead of the handler; the service-level text carries no /admin either.
    expect(app(BotSupportContact::class)->groupInactive('ru'))
        ->toBe("Группа ещё не активирована. Напишите в поддержку — активируем.\nПоддержка: @lexi_help")
        ->and(__('errors.group_inactive', ['support' => ''], 'uz'))->not->toContain('/admin');
});

// ---------------------------------------------------------------- 2. onboarding

it('greets a new person in both languages and counts the steps', function (): void {
    clarityDm(8001, '/start');

    $text = $this->tg->sent[0]['text'];
    expect($this->tg->sent)->toHaveCount(1)
        ->and($text)->toStartWith('👋 Привет! Я LexiFlow')
        ->and($text)->toContain('Salom! Men LexiFlow')
        ->and($text)->toContain("Шаг 1 из 5\nВыберите язык интерфейса");

    // The next step: no welcome, the counter moves.
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-1', 'from' => ['id' => 8001, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => 100, 'chat' => ['id' => 8001, 'type' => 'private']],
        'data' => 'onb1:locale:uz',
    ]]);

    $last = end($this->tg->sent);
    expect($last['text'])->toStartWith('2/5-qadam')->and($last['text'])->not->toContain('Salom!');
});

it('names the center or the person who invited a new learner', function (): void {
    $organization = Organization::factory()->create(['name' => 'Bright Center']);
    OrganizationInvite::factory()->create(['organization_id' => $organization->id, 'code' => 'BRIGHT1234']);
    Learner::factory()->create(['referral_code' => 'REFAB2XY', 'first_name' => 'Dilya']);

    clarityDm(8002, '/start org_BRIGHT1234');
    clarityDm(8003, '/start ref_REFAB2XY');
    clarityDm(8004, '/start org_NOSUCHCODE');

    expect($this->tg->sent[0]['text'])->toStartWith('Вас пригласил центр «Bright Center»'."\n\n".'👋')
        ->and($this->tg->sent[1]['text'])->toStartWith('Вас пригласил(а) Dilya'."\n\n".'👋')
        ->and($this->tg->sent[2]['text'])->not->toContain('Вас пригласил');
});

// ---------------------------------------------------------------- 3 + 4. teachers

it('greets a linked teacher with next steps and answers learner commands with a pointer to /help', function (): void {
    clarityTeacher(7001);

    clarityDm(7001, '/start');
    expect($this->tg->sent[0]['text'])->toContain('Ваш аккаунт учителя подключён')->toContain('/start_training')->toContain('/org');

    foreach (['/today', '/settings', '/invite', '/pro'] as $i => $command) {
        clarityDm(7001, $command);
        expect($this->tg->sent[$i + 1]['text'])->toBe('Эта команда — для учеников. Ваши команды учителя: /help');
    }

    // Free text is not answered.
    clarityDm(7001, 'hello');
    expect($this->tg->sent)->toHaveCount(5)
        ->and(Learner::query()->where('telegram_user_id', 7001)->exists())->toBeFalse();
});

it('answers the teacher in the language of Telegram', function (): void {
    clarityTeacher(7002);

    clarityDm(7002, '/today', 'uz');

    expect($this->tg->sent[0]['text'])->toBe("Bu buyruq — o'quvchilar uchun. O'qituvchi buyruqlaringiz: /help");
});

// ---------------------------------------------------------------- 5. exam picker

/** @return array{group: TelegramGroup, teacher: User} */
function clarityExamSetup(): array
{
    $group = TelegramGroup::factory()->create(['chat_id' => -100600, 'status' => 'active']);
    $teacher = clarityTeacher(6001, $group);
    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count(10)->for($lesson)->create();
    Word::factory()->count(20)->for(Lesson::factory()->for($stage)->create(['number' => 2]))->create();

    return compact('group', 'teacher');
}

function clarityTap(int $fromId, string $data, int $messageId = 55): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cq-'.md5($data.$fromId),
        'from' => ['id' => $fromId],
        'data' => $data,
        'message' => ['message_id' => $messageId, 'chat' => ['id' => -100600, 'type' => 'supergroup']],
    ]]);
}

it('shows the lesson picker for /start_exam without arguments and opens nothing yet', function (): void {
    clarityExamSetup();

    clarityGroupText(-100600, 6001, '/start_exam');

    $buttons = collect($this->tg->sent[0]['markup']['inline_keyboard'])->flatten(1);
    expect($this->tg->sent[0]['text'])->toBe('Выберите урок для экзамена:')
        ->and($buttons->pluck('callback_data')->all())->toContain('estart:1:1', 'estart:1:2')
        ->and(DB::table('exam_sessions')->count())->toBe(0);
});

it('asks the duration after the lesson, then opens the exam with the chosen minutes', function (): void {
    clarityExamSetup();

    clarityTap(6001, 'estart:1:1');

    $row = $this->tg->sent[0]['markup']['inline_keyboard'][0];
    expect(array_column($row, 'text'))->toBe(['5 мин', '10 мин', '15 мин'])
        ->and(array_column($row, 'callback_data'))->toBe(['estart:1:1:5', 'estart:1:1:10', 'estart:1:1:15'])
        ->and($this->tg->edits)->toHaveCount(1)
        ->and(DB::table('exam_sessions')->count())->toBe(0);

    clarityTap(6001, 'estart:1:1:15');

    $session = DB::table('exam_sessions')->first();
    expect($session)->not->toBeNull()
        ->and(json_decode((string) $session->config, true)['duration_minutes'])->toBe(15);
    $announce = end($this->tg->sent);
    expect($announce['text'])->toContain('Экзамен начался!')->toContain('15 мин')->toContain('На каждый вопрос — 10 секунд')
        ->and($announce['url'])->toStartWith('https://twa.test/twa/exam/');
});

it('keeps /start_exam with arguments as it was', function (): void {
    clarityExamSetup();

    clarityGroupText(-100600, 6001, '/start_exam 1 1 5');

    expect(DB::table('exam_sessions')->count())->toBe(1)
        ->and(json_decode((string) DB::table('exam_sessions')->value('config'), true)['duration_minutes'])->toBe(5);
});

it('does not let a stranger or a teacher of another group use the exam buttons', function (): void {
    clarityExamSetup();
    clarityTeacher(6002);
    $other = TelegramGroup::factory()->create(['chat_id' => -100601, 'status' => 'active']);
    clarityTeacher(6003, $other);

    foreach ([6002, 6003, 6999] as $stranger) {
        clarityTap($stranger, 'estart:1:1:10');
        clarityTap($stranger, 'estart:1:1');
    }
    clarityGroupText(-100600, 6003, '/start_exam');

    expect(DB::table('exam_sessions')->count())->toBe(0)
        ->and(collect($this->tg->sent)->contains(fn (array $m): bool => str_contains($m['text'], 'Сколько минут')))->toBeFalse()
        ->and(collect($this->tg->sent)->contains(fn (array $m): bool => $m['markup'] !== null))->toBeFalse();
});

it('rejects malformed exam callbacks and an invalid duration without opening anything', function (): void {
    clarityExamSetup();

    clarityTap(6001, 'estart:x:1');
    clarityTap(6001, 'estart:1:1:2:3');
    clarityTap(6001, 'estart:1');
    expect($this->tg->sent)->toBe([]);

    clarityTap(6001, 'estart:1:1:99');
    expect(DB::table('exam_sessions')->count())->toBe(0)
        ->and($this->tg->sent[0]['text'])->toContain('от 1 до 30');
});

it('answers a pending-group exam button tap with nothing', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100600, 'status' => 'pending']);

    clarityTap(6001, 'estart:1:1');

    expect($this->tg->sent)->toBe([]);
});

// ---------------------------------------------------------------- 6/7. errors and /help

it('tells a non-linked user in a group how to link and in DM how to connect Telegram', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -100700, 'status' => 'active']);

    clarityGroupText(-100700, 31, '/start_exam 1 1 5');
    expect($this->tg->sent[0]['text'])->toContain('Эта команда — для учителя группы')->toContain('Профиль')->not->toContain('/admin');

    clarityMessage(['chat' => ['id' => 31, 'type' => 'private'], 'from' => ['id' => 31, 'language_code' => 'ru'], 'text' => '/stats']);
    expect($this->tg->sent[1]['text'])->toContain('Отчёт доступен учителям')->toContain('Подключить Telegram')->toContain('<code>31</code>')->toContain('/today');
    expect($group->exists)->toBeTrue();
});

it('shows /stats accuracy as a dash when nobody answered and counts learners as "учеников"', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -100701, 'status' => 'active']);
    clarityTeacher(41, $group);

    clarityDm(41, '/stats');

    expect($this->tg->sent[0]['text'])->toContain('Активных учеников: 0')->toContain('Точность экзаменов: —')->not->toContain('0%');
    expect(ExamAnswer::query()->count())->toBe(0);
});

it('tells a teacher without groups where to turn instead of pointing to /admin', function (): void {
    clarityTeacher(42);

    clarityDm(42, '/stats');

    expect($this->tg->sent[0]['text'])->toContain('напишите в поддержку')->not->toContain('/admin');
});

it('shows /help in the language the learner chose and the teacher block only to linked teachers', function (): void {
    Learner::factory()->create(['telegram_user_id' => 51, 'interface_locale' => 'uz', 'language_code' => 'ru']);

    clarityDm(51, '/help', 'ru');
    expect($this->tg->sent[0]['text'])->toContain("Qanday shug'ullanish kerak")
        ->and($this->tg->sent[0]['text'])->not->toContain('/start_training')
        ->and($this->tg->sent[0]['text'])->not->toContain('учитель или владелец');

    // A contact makes the "write us" line appear; a stranger gets the learner help in Telegram's language.
    config(['billing.paywall.contact' => '@lexi_help']);
    clarityDm(52, '/help', 'ru');
    expect($this->tg->sent[1]['text'])->toContain('Как заниматься')
        ->and($this->tg->sent[1]['text'])->toContain('Вы учитель или владелец центра? Напишите нам: @lexi_help')
        ->and($this->tg->sent[1]['text'])->not->toContain('/start_training');

    clarityTeacher(53);
    clarityDm(53, '/help', 'ru');
    expect($this->tg->sent[2]['text'])->toContain('/start_training')->toContain('/org')->not->toContain('/today');
});

it('treats a deactivated teacher the same way in /help and /today: a teacher, not a learner', function (): void {
    $teacher = clarityTeacher(55);
    $teacher->forceFill(['is_active' => false])->save();

    clarityDm(55, '/help', 'ru');
    expect($this->tg->sent[0]['text'])->toContain('/start_training')->not->toContain('Как заниматься');

    $before = count($this->tg->sent);
    clarityDm(55, '/today', 'ru');
    // /today does not make a learner of them either: the teacher notice, no learner row.
    expect(count($this->tg->sent))->toBe($before + 1)
        ->and(Learner::query()->where('telegram_user_id', 55)->exists())->toBeFalse();
});

it('leaves out the "write us" line of /help when no contact is configured', function (): void {
    clarityDm(54, '/help', 'ru');

    expect($this->tg->sent[0]['text'])->not->toContain('Вы учитель или владелец');
});

// ---------------------------------------------------------------- 8-10. texts

it('announces a training and the reminders with the new wording, in both languages', function (): void {
    expect(__('training.started', ['stage' => 2, 'lesson' => 3], 'ru'))->toBe('📚 Тренировка: модуль 2, урок 3. Нажмите кнопку — LexiFlow откроется прямо в Telegram.')
        ->and(trans_choice('reminders.review', 5, ['count' => 5], 'ru'))->toEndWith('Изменить время или отключить: /settings')
        ->and(trans_choice('reminders.streak_at_risk', 1, ['count' => 1], 'ru'))->toEndWith('/settings')
        ->and(trans_choice('reminders.review', 5, ['count' => 5], 'uz'))->toEndWith('/settings')
        ->and(__('onboarding.done.button', [], 'ru'))->toContain('первое занятие');
});
