<?php

declare(strict_types=1);

use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TeacherStatsReporter;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Filament\Resources\TelegramGroupResource\Pages\EditTelegramGroup;
use App\Jobs\PostLeaderboardJob;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.base_url', 'https://twa.test');

    // Records every outgoing message/button so tests can assert on the text.
    $this->api = new class implements TelegramClient
    {
        /** @var list<array<string, mixed>> */
        public array $log = [];

        public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): void
        {
            $this->log[] = ['chat_id' => $chatId, 'text' => $text, 'button' => null, 'markup' => $replyMarkup, 'parse_mode' => $parseMode];
        }

        public function sendWebAppButton(int|string $chatId, string $text, string $buttonText, string $url, ?string $parseMode = null, bool $immediate = false): void
        {
            $this->log[] = ['chat_id' => $chatId, 'text' => $text, 'button' => $buttonText, 'markup' => null, 'parse_mode' => $parseMode];
        }

        public function sendPhoto(int|string $chatId, string $photoPath, ?string $caption = null, ?array $replyMarkup = null): void {}

        public function answerCallbackQuery(string $callbackQueryId): void {}

        public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void {}

        public function getChatMemberStatus(int|string $chatId, int $userId): string
        {
            return 'administrator';
        }

        public function setWebhook(string $url, string $secretHeader): bool
        {
            return true;
        }

        public function deleteWebhook(): bool
        {
            return true;
        }

        public function createInvoiceLink(string $title, string $description, string $payload, string $currency, array $prices, ?int $subscriptionPeriod = null): string
        {
            return 'https://t.me/$test-invoice';
        }

        public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, ?string $errorMessage = null): void {}

        public function editUserStarSubscription(int $userId, string $telegramPaymentChargeId, bool $isCanceled): void {}

        public function refundStarPayment(int $userId, string $telegramPaymentChargeId): void {}

        public function getStarTransactions(int $offset = 0, int $limit = 100): array
        {
            return [];
        }

        public function setMyCommands(array $commands, array $scope, ?string $languageCode = null): bool
        {
            return true;
        }

        public function setChatMenuButton(array $menuButton): bool
        {
            return true;
        }
    };

    $this->app->instance(TelegramClient::class, $this->api);
});

/** @param array<string, mixed> $meta */
function locGroup(int $chatId, array $meta = [], string $status = 'active'): TelegramGroup
{
    return TelegramGroup::factory()->create(['chat_id' => $chatId, 'status' => $status, 'meta' => $meta]);
}

function locTeacher(TelegramGroup $group, int $tgId): User
{
    $teacher = User::factory()->create(['telegram_user_id' => $tgId]);
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $teacher;
}

function locSeedLessons(): void
{
    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count(10)->for($lesson)->create();
    $other = Lesson::factory()->for($stage)->create(['number' => 2]);
    Word::factory()->count(20)->for($other)->create();
}

/** @param array<string, mixed> $message */
function locDispatch(array $message): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => $message]);
}

// --- group messages follow telegram_groups.meta.locale ---------------------

it('answers /help in the group language', function (?string $locale, string $needle): void {
    locGroup(-5001, $locale === null ? [] : ['locale' => $locale]);

    locDispatch(['chat' => ['id' => -5001, 'type' => 'supergroup'], 'from' => ['id' => 1], 'text' => '/help']);

    expect($this->api->log)->toHaveCount(1)
        ->and($this->api->log[0]['text'])->toContain($needle);
})->with([
    'uz group' => ['uz', "O'qituvchi buyruqlari"],
    'ru group' => ['ru', 'Команды учителя'],
    'no locale -> default' => [null, 'Команды учителя'],
    'unsupported locale -> default' => ['de', 'Команды учителя'],
]);

it('posts the exam start message and button in uz for a uz group', function (): void {
    $group = locGroup(-5002, ['locale' => 'uz']);
    locTeacher($group, 701);
    locSeedLessons();

    locDispatch(['chat' => ['id' => -5002, 'type' => 'supergroup'], 'from' => ['id' => 701], 'text' => '/start_exam 1 1 5']);

    expect($this->api->log)->toHaveCount(1)
        ->and($this->api->log[0]['text'])->toContain('Imtihon boshlandi')->toContain('1-modul · 1-dars · <b>5 daq</b>')
        ->and($this->api->log[0]['button'])->toBe('🏁 Imtihonni ochish')
        ->and($this->api->log[0]['parse_mode'])->toBe('HTML');
});

it('posts the training start message in uz and ru', function (string $locale, string $text, string $button): void {
    $group = locGroup(-5003, ['locale' => $locale]);
    locTeacher($group, 702);
    locSeedLessons();

    locDispatch(['chat' => ['id' => -5003, 'type' => 'supergroup'], 'from' => ['id' => 702], 'text' => '/start_training 1 1']);

    expect($this->api->log)->toHaveCount(1)
        ->and($this->api->log[0]['text'])->toContain($text)
        ->and($this->api->log[0]['button'])->toBe($button);
})->with([
    'uz' => ['uz', 'Trening boshlandi: 1-modul, 1-dars', '🎯 Treningni ochish'],
    'ru' => ['ru', 'Тренировка запущена: модуль 1, урок 1', '🎯 Открыть тренировку'],
]);

it('shows the lesson picker in the group language', function (): void {
    $group = locGroup(-5004, ['locale' => 'uz']);
    locTeacher($group, 703);
    locSeedLessons();

    locDispatch(['chat' => ['id' => -5004, 'type' => 'supergroup'], 'from' => ['id' => 703], 'text' => '/start_training']);

    expect($this->api->log[0]['text'])->toBe('Trening uchun darsni tanlang:')
        ->and($this->api->log[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('1-modul · 1-dars');
});

it('translates errors for non-teachers and unknown accounts in a uz group', function (): void {
    locGroup(-5005, ['locale' => 'uz']);

    locDispatch(['chat' => ['id' => -5005, 'type' => 'supergroup'], 'from' => ['id' => 99999], 'text' => '/start_exam']);
    locDispatch(['chat' => ['id' => -5005, 'type' => 'supergroup'], 'from' => ['id' => 99999], 'text' => '/close_exam']);

    expect($this->api->log[0]['text'])->toBe("Sizning Telegram akkauntingiz o'qituvchiga biriktirilmagan.")
        ->and($this->api->log[1]['text'])->toBe("Sizning Telegram akkauntingiz o'qituvchiga biriktirilmagan.");
});

it('translates exam domain errors', function (): void {
    $group = locGroup(-5006, ['locale' => 'uz']);
    locTeacher($group, 704);
    // no lessons seeded -> stage not found

    locDispatch(['chat' => ['id' => -5006, 'type' => 'supergroup'], 'from' => ['id' => 704], 'text' => '/start_exam 9 9 5']);
    locDispatch(['chat' => ['id' => -5006, 'type' => 'supergroup'], 'from' => ['id' => 704], 'text' => '/close_exam']);

    expect($this->api->log[0]['text'])->toBe("Ko'rsatilgan modul topilmadi.")
        ->and($this->api->log[1]['text'])->toBe("Hozir guruhda ochiq imtihon yo'q.");
});

it('keeps untranslated group in the default language for domain errors', function (): void {
    $group = locGroup(-5007);
    locTeacher($group, 705);

    locDispatch(['chat' => ['id' => -5007, 'type' => 'supergroup'], 'from' => ['id' => 705], 'text' => '/start_exam 9 9 5']);

    expect($this->api->log[0]['text'])->toBe('Указанный модуль не найден.');
});

// --- private chats ---------------------------------------------------------

it('answers private /help by the sender language code', function (?string $languageCode, string $needle): void {
    $from = ['id' => 42] + ($languageCode === null ? [] : ['language_code' => $languageCode]);

    locDispatch(['chat' => ['id' => 42, 'type' => 'private'], 'from' => $from, 'text' => '/help']);

    expect($this->api->log[0]['text'])->toContain($needle);
})->with([
    'uz' => ['uz', "Telegramda inglizcha so'zlar"],
    'uz-UZ' => ['uz-UZ', "Telegramda inglizcha so'zlar"],
    'ru' => ['ru', 'английские слова в Telegram'],
    'en -> default' => ['en', 'английские слова в Telegram'],
    'missing -> default' => [null, 'английские слова в Telegram'],
]);

it('lists the learner commands first and the teacher ones after them in the private /help', function (string $languageCode, string $teacherHeading): void {
    locDispatch(['chat' => ['id' => 43, 'type' => 'private'], 'from' => ['id' => 43, 'language_code' => $languageCode], 'text' => '/help']);

    [$learnerPart, $teacherPart] = explode($teacherHeading, $this->api->log[0]['text'], 2);

    expect($learnerPart)->toContain('«LexiFlow»')->toContain('/today')->toContain('/settings')->toContain('/invite')
        ->toContain('/pro —')->toContain('/promo')->toContain('/paysupport')
        ->not->toContain('/start_training')->not->toContain('/stats')
        ->and($teacherPart)->toContain('/start_training')->toContain('/start_exam')->toContain('/close_exam')->toContain('/stats')
        // Plain text: it goes out without a parse mode, so there is no markup in it.
        ->and($this->api->log[0]['parse_mode'])->toBeNull();
})->with([
    'ru' => ['ru', 'Для учителей:'],
    'uz' => ['uz', "O'qituvchilar uchun:"],
]);

it('names LexiFlow without the word "Bot" in the group /help', function (string $locale, string $firstLine): void {
    locGroup(-5020, ['locale' => $locale]);

    locDispatch(['chat' => ['id' => -5020, 'type' => 'supergroup'], 'from' => ['id' => 1], 'text' => '/help']);

    expect(explode("\n", $this->api->log[0]['text'])[0])->toBe($firstLine);
})->with([
    'ru' => ['ru', 'LexiFlow в группе.'],
    'uz' => ['uz', 'LexiFlow guruhda.'],
]);

it('greets a linked teacher in the default language regardless of Telegram language', function (): void {
    User::factory()->create(['telegram_user_id' => 43, 'name' => 'Aziz']);

    locDispatch(['chat' => ['id' => 43, 'type' => 'private'], 'from' => ['id' => 43, 'language_code' => 'uz'], 'text' => '/start']);

    expect($this->api->log[0]['text'])->toContain('Привет, Aziz!');
});

it('starts the onboarding of a /start sender in their Telegram language', function (string $languageCode, string $needle): void {
    locDispatch(['chat' => ['id' => 44, 'type' => 'private'], 'from' => ['id' => 44, 'language_code' => $languageCode], 'text' => '/start']);

    expect($this->api->log[0]['text'])->toContain($needle);
})->with([
    'uz' => ['uz', 'Interfeys tilini tanlang'],
    'fr -> default' => ['fr', 'Выберите язык интерфейса'],
]);

it('reports /stats to a teacher in the default language and to unlinked users in their language', function (): void {
    User::factory()->create(['telegram_user_id' => 45, 'is_active' => true]);

    locDispatch(['chat' => ['id' => 45, 'type' => 'private'], 'from' => ['id' => 45, 'language_code' => 'uz'], 'text' => '/stats']);
    locDispatch(['chat' => ['id' => 46, 'type' => 'private'], 'from' => ['id' => 46, 'language_code' => 'uz'], 'text' => '/stats']);

    expect($this->api->log[0]['text'])->toContain('У вас пока нет привязанных групп')
        ->and($this->api->log[1]['text'])->toContain("o'qituvchisiga biriktirilmagan")->toContain('46');
});

it('renders the teacher stats report from lang files', function (): void {
    $teacher = User::factory()->create();

    $ru = app(TeacherStatsReporter::class);
    $group = locGroup(-5008);
    locTeacher($group, 706);
    $teacher = User::query()->where('telegram_user_id', 706)->firstOrFail();

    expect($ru->build($teacher))->toContain('Статистика LexiFlow за 7 дней')->toContain('Групп: 1')
        ->and($ru->build($teacher, 'uz'))->toContain("LexiFlow statistikasi (so'nggi 7 kun)")->toContain('Guruhlar: 1')
        ->toContain('Imtihonlardagi aniqlik: 0%');
});

// --- review reminders (learner language) -----------------------------------

/** Gives the learner $due graduated due cards and runs the reminder sender (docs/12 §7.5). */
function locRunReminder(Learner $learner, int $due): void
{
    app()->instance(TelegramClient::class, test()->api);
    foreach (Word::factory()->count($due)->create() as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }

    app(ReminderSender::class)->send($learner->id, CarbonImmutable::now()->subMinutes(5));
}

it('sends the reminder in the learner interface language with correct number forms', function (string $interfaceLocale, int $due, string $expected, string $button): void {
    $learner = Learner::factory()->create([
        'telegram_user_id' => 880001,
        'interface_locale' => $interfaceLocale,
        // The Telegram language is only a fallback now: it must not override the chosen locale.
        'language_code' => 'ru']);

    locRunReminder($learner, $due);

    expect($this->api->log)->toHaveCount(1)
        ->and($this->api->log[0]['text'])->toBe($expected)
        ->and($this->api->log[0]['button'])->toBe($button);
})->with([
    'ru 1' => ['ru', 1, 'Пора повторить 1 слово в LexiFlow.', 'Открыть LexiFlow'],
    'ru 2' => ['ru', 2, 'Пора повторить 2 слова в LexiFlow.', 'Открыть LexiFlow'],
    'ru 5' => ['ru', 5, 'Пора повторить 5 слов в LexiFlow.', 'Открыть LexiFlow'],
    'ru 11' => ['ru', 11, 'Пора повторить 11 слов в LexiFlow.', 'Открыть LexiFlow'],
    'ru 21' => ['ru', 21, 'Пора повторить 21 слово в LexiFlow.', 'Открыть LexiFlow'],
    'ru 22' => ['ru', 22, 'Пора повторить 22 слова в LexiFlow.', 'Открыть LexiFlow'],
    'ru 25' => ['ru', 25, 'Пора повторить 25 слов в LexiFlow.', 'Открыть LexiFlow'],
    'uz 5' => ['uz', 5, "LexiFlow'da 5 ta so'zni takrorlash vaqti keldi.", "LexiFlow'ni ochish"],
    'uz 21' => ['uz', 21, "LexiFlow'da 21 ta so'zni takrorlash vaqti keldi.", "LexiFlow'ni ochish"],
    'unsupported locale -> default' => ['en', 2, 'Пора повторить 2 слова в LexiFlow.', 'Открыть LexiFlow'],
]);

it('falls back to the Telegram language when the stored interface locale is unsupported', function (): void {
    $learner = Learner::factory()->create([
        'interface_locale' => 'xx',
        'language_code' => 'uz-UZ',
    ]);

    locRunReminder($learner, 5);

    expect($this->api->log[0]['text'])->toBe("LexiFlow'da 5 ta so'zni takrorlash vaqti keldi.");
});

// --- leaderboard (group language, HTML escaping preserved) -----------------

function locClosedExamWithWinner(TelegramGroup $group, string $name): ExamSession
{
    $session = ExamSession::factory()->closed()->create(['telegram_group_id' => $group->id]);
    $learner = Learner::factory()->memberOf($group)->create(['first_name' => $name]);
    ExamResult::query()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $learner->id,
        'total_score' => 40,
        'correct_count' => 2,
        'total_count' => 2,
        'rank' => 1,
    ]);

    return $session;
}

function locRunLeaderboard(ExamSession $session): void
{
    (new PostLeaderboardJob($session->id))->handle(
        test()->api,
        app(LeaderboardBuilder::class),
        app(ProductEventRecorder::class),
        app(LocaleResolver::class),
    );
}

it('posts the leaderboard in the group language and still escapes names', function (string $locale, string $title): void {
    $group = locGroup(-5010, ['locale' => $locale]);
    $session = locClosedExamWithWinner($group, '<script>alert(1)</script>');

    locRunLeaderboard($session);

    expect($this->api->log)->toHaveCount(1)
        ->and($this->api->log[0]['parse_mode'])->toBe('HTML')
        ->and($this->api->log[0]['text'])->toContain($title)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>')
        ->toContain('🥇');
})->with([
    'uz' => ['uz', '<b>Imtihon natijalari</b>'],
    'ru' => ['ru', '<b>Итоги экзамена</b>'],
]);

it('posts the empty leaderboard message in uz', function (): void {
    $group = locGroup(-5011, ['locale' => 'uz']);
    $session = ExamSession::factory()->closed()->create(['telegram_group_id' => $group->id]);

    locRunLeaderboard($session);

    expect($this->api->log[0]['text'])->toContain('Hech kim javob bermadi');
});

// --- process locale is never switched --------------------------------------

it('leaves the application locale untouched after handling updates and jobs', function (): void {
    $before = app()->getLocale();

    $group = locGroup(-5012, ['locale' => 'uz']);
    locDispatch(['chat' => ['id' => -5012, 'type' => 'supergroup'], 'from' => ['id' => 1], 'text' => '/help']);
    locRunLeaderboard(ExamSession::factory()->closed()->create(['telegram_group_id' => $group->id]));
    locRunReminder(Learner::factory()->memberOf($group)->create(['interface_locale' => 'uz']), 3);

    expect(app()->getLocale())->toBe($before);
});

// --- group meta.locale management -----------------------------------------

it('keeps meta.locale when the bot is re-added to a known group', function (): void {
    locGroup(-5013, ['locale' => 'uz', 'type' => 'supergroup'], 'disabled');

    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -5013, 'type' => 'supergroup', 'title' => 'Renamed'],
            'old_chat_member' => ['status' => 'left'],
            'new_chat_member' => ['status' => 'member'],
        ],
    ]);

    $meta = TelegramGroup::query()->where('chat_id', -5013)->firstOrFail()->meta;
    expect($meta['locale'])->toBe('uz')->and($meta['type'])->toBe('supergroup');
});

it('lets an admin pick the bot language of a group in Filament', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $group = locGroup(-5014, ['type' => 'supergroup', 'member_count' => 12]);

    livewire(EditTelegramGroup::class, ['record' => $group->getKey()])
        ->assertFormSet(['locale' => null])
        ->fillForm(['locale' => 'uz'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ksorted($group->fresh()->meta))->toBe(ksorted(['type' => 'supergroup', 'member_count' => 12, 'locale' => 'uz']));

    livewire(EditTelegramGroup::class, ['record' => $group->getKey()])
        ->assertFormSet(['locale' => 'uz'])
        ->fillForm(['locale' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ksorted($group->fresh()->meta))->toBe(ksorted(['type' => 'supergroup', 'member_count' => 12]));
});

// --- 2FA pages (teacher/admin => default language) -------------------------

it('renders the 2FA setup page and validation error in the default language', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $this->get(route('2fa.setup'))
        ->assertOk()
        ->assertSee('<html lang="ru">', false)
        ->assertSee('Включите двухфакторную аутентификацию');

    $this->from(route('2fa.setup'))
        ->post(route('2fa.confirm'), ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Неверный код. Попробуйте ещё раз.']);
});
