<?php

declare(strict_types=1);

use App\Domain\Administration\Exceptions\OrganizationGroupLinkException;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\OfferException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Exceptions\PaymentRefundException;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Services\OfferService;
use App\Domain\Billing\Services\PaymentRefundService;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Content\DTO\ImportReport;
use App\Domain\Content\Exceptions\CoursePublishException;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Filament\Pages\ImportLesson;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\OffersRelationManager;
use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\OrganizationResource\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Lang;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * The notifications of /admin say WHY an action failed in Russian: the domain exceptions keep their English message for
 * logs and API clients, the panel words the same error from the code and context (AdminErrors, lang/ru/admin.php →
 * `admin.<screen>.errors.<code>`). A code nobody has worded yet reads as a generic Russian sentence, never as a raw key
 * and never as the English message.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

/** What the actions have sent since the last call: title and body of every notification. */
function aet_sent(): array
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return array_map(
        static fn (array $notification): array => ['title' => $notification['title'] ?? null, 'body' => $notification['body'] ?? null],
        $mailbox->pull(),
    );
}

function aet_has_cyrillic(string $text): bool
{
    return preg_match('/\p{Cyrillic}/u', $text) === 1;
}

/**
 * Puts a stand-in for a final domain service into the container: `$method` throws `$exception`, as the real service does
 * when the state it locks and re-reads has changed since the button was shown. The button hides itself in that state, so
 * the panel can only meet the error in a race, which a test cannot reach through the real service.
 */
function aet_service_throws(string $service, string $method, Throwable $exception): void
{
    app()->instance($service, new class($method, $exception)
    {
        public function __construct(private readonly string $method, private readonly Throwable $exception) {}

        /** @param  array<int, mixed>  $arguments */
        public function __call(string $name, array $arguments): never
        {
            throw $name === $this->method ? $this->exception : new BadMethodCallException("Unexpected call of {$name}()");
        }
    });
}

// --- the screens: what the person actually reads ----------------------------------------------------------------------

it('words a refused course publication in Russian', function (): void {
    $course = Course::factory()->create(['status' => 'review']);

    livewire(ListCourses::class)->callTableAction('transition_published', $course);

    expect(aet_sent())->toBe([['title' => 'Статус не изменён', 'body' => 'В курсе нет ни одного урока.']])
        ->and($course->fresh()->status)->toBe('review');
});

it('words the translation threshold of a course with its numbers', function (): void {
    $course = Course::factory()->create(['status' => 'review']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    foreach (Word::factory()->count(10)->for($lesson)->create() as $i => $word) {
        WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru']);
        if ($i < 5) {
            WordTranslation::factory()->uz()->create(['word_id' => $word->id]);
        }
    }

    livewire(ListCourses::class)->callTableAction('transition_published', $course);

    $sent = aet_sent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['body'])->toBe('Переводы (RU, UZ) есть только для 5 из 10 слов (50%), а нужно не меньше 95%.');
});

it('words the title that is missing in a language, with the language as in the form captions', function (): void {
    $course = Course::factory()->create(['status' => 'review', 'title' => ['ru' => 'Курс', 'uz' => '']]);

    livewire(ListCourses::class)->callTableAction('transition_published', $course);

    expect(aet_sent()[0]['body'])->toBe('Название курса нужно заполнить на всех языках. Не заполнено: UZ.');
});

it('words a failed lesson import in Russian: the notification and the report on the page', function (): void {
    $file = UploadedFile::fake()->createWithContent('broken.json', 'not-json');

    livewire(ImportLesson::class)
        ->set('data.file', $file)
        ->call('submit')
        ->assertSee('Файл не удалось прочитать как JSON: ошибка в синтаксисе. Проверьте запятые, кавычки и скобки.')
        ->assertDontSee('Invalid JSON');

    expect(aet_sent())->toBe([[
        'title' => 'Импорт не выполнен — ничего не записано.',
        'body' => 'Файл не удалось прочитать как JSON: ошибка в синтаксисе. Проверьте запятые, кавычки и скобки.',
    ]]);
});

it('words every refusal of a lesson file with the word and its position in the list', function (): void {
    $json = json_encode([
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => [
            ['word' => 'cat', 'translation' => 'кошка'],
            ['word' => 'Cat', 'translation' => 'кот'],
        ],
    ], JSON_THROW_ON_ERROR);

    livewire(ImportLesson::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('dup.json', $json))
        ->call('submit')
        ->assertSee('Слово «Cat» повторяется в файле (повтор — под номером 2).')
        ->assertDontSee('Duplicate word');

    expect(Word::count())->toBe(0);
});

it('words the unknown course and the missing translation of a lesson file', function (): void {
    $json = json_encode([
        'course' => 'no-such-course',
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => [['word' => 'cat', 'translation' => 'кошка']],
    ], JSON_THROW_ON_ERROR);
    livewire(ImportLesson::class)->set('data.file', UploadedFile::fake()->createWithContent('c.json', $json))->call('submit');

    expect(aet_sent()[0]['body'])->toBe('Курс «no-such-course» не найден: сначала создайте его в разделе «Курсы».');

    $json = json_encode([
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => [['word' => 'dog']],
    ], JSON_THROW_ON_ERROR);
    livewire(ImportLesson::class)->set('data.file', UploadedFile::fake()->createWithContent('d.json', $json))->call('submit');

    expect(aet_sent()[0]['body'])->toBe('Слово «dog» (№1): не указан русский перевод — заполните "translation" или "translations.ru".');
});

it('passes the schema errors of a lesson file on in Russian', function (): void {
    $json = json_encode(['stage' => ['number' => 0], 'lesson' => ['number' => 1], 'words' => [['word' => 'cat', 'translation' => 'кошка']]], JSON_THROW_ON_ERROR);

    livewire(ImportLesson::class)->set('data.file', UploadedFile::fake()->createWithContent('s.json', $json))->call('submit');

    $body = (string) aet_sent()[0]['body'];
    expect($body)->toContain('stage.number')
        ->and(aet_has_cyrillic($body))->toBeTrue();
});

it('words a refused refund in Russian: the provider cannot be asked', function (): void {
    $payment = Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 2_900_000]);

    livewire(ListPayments::class)->callTableAction('refund', $payment, ['note' => 'Просьба ученика', 'returned_by_hand' => false]);

    expect(aet_sent())->toBe([[
        'title' => 'Возврат не выполнен',
        'body' => 'Через панель нельзя вернуть деньги, оплаченные через Click. Верните их в кабинете провайдера, затем повторите действие и отметьте, что деньги возвращены вручную.',
    ]])->and($payment->fresh()->status)->toBe('succeeded');
});

it('words a refund of a payment of a provider the panel does not know', function (): void {
    $payment = Payment::factory()->create(['provider' => 'legacy_provider']);

    livewire(ListPayments::class)->callTableAction('refund', $payment, ['note' => 'Просьба ученика', 'returned_by_hand' => true]);

    expect(aet_sent()[0]['body'])->toBe('Вернуть можно только проведённую оплату или продление.');
});

it('words a refund of a payment with no charge id of the provider', function (): void {
    $payment = Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'provider_charge_id' => null]);

    livewire(ListPayments::class)->callTableAction('refund', $payment, ['note' => 'Просьба ученика', 'returned_by_hand' => true]);

    expect(aet_sent()[0]['body'])->toBe('У платежа нет ID списания у провайдера — вернуть его через панель нельзя.');
});

it('words a refund that another admin has already made', function (): void {
    $payment = Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS']);
    aet_service_throws(PaymentRefundService::class, 'refund', PaymentApplyException::alreadyRefunded($payment->id));

    livewire(ListPayments::class)->callTableAction('refund', $payment, ['note' => 'Просьба ученика', 'returned_by_hand' => true]);

    expect(aet_sent())->toBe([[
        'title' => 'Возврат не выполнен',
        'body' => "Платёж №{$payment->id} уже возвращён.",
    ]]);
});

it('words the annulment of a discount that has been used in the meantime', function (): void {
    $learner = Learner::factory()->create();
    $offer = Offer::factory()->create(['learner_id' => $learner->id]);
    aet_service_throws(OfferService::class, 'annul', OfferException::notOpen());

    livewire(OffersRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])
        ->callTableAction('annul', $offer);

    expect(aet_sent())->toBe([[
        'title' => 'Скидка не аннулирована',
        'body' => 'Скидка уже использована или истекла — аннулировать нечего.',
    ]]);
});

it('words the refusal to add a learner to a suspended center', function (): void {
    $organization = Organization::factory()->suspended()->create();
    $learner = Learner::factory()->create();

    livewire(MembersRelationManager::class, ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class])
        ->callTableAction('addMember', data: ['learner_id' => $learner->id]);

    expect(aet_sent())->toBe([[
        'title' => 'Ученик не добавлен',
        'body' => 'Учебный центр приостановлен — новые ученики не добавляются.',
    ]]);
});

it('words the removal of a learner who has already left the center', function (): void {
    $organization = Organization::factory()->create();
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id]);
    aet_service_throws(OrganizationMembershipService::class, 'leave', OrganizationMembershipException::notAMember($member->learner_id, $organization->id));

    livewire(MembersRelationManager::class, ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class])
        ->callTableAction('removeMember', $member);

    expect(aet_sent())->toBe([[
        'title' => 'Ученик уже удалён',
        'body' => 'Ученик уже не состоит в центре.',
    ]]);
});

it('words the linking of a group that belongs to another center', function (): void {
    $organization = Organization::factory()->create();
    $group = TelegramGroup::factory()->create();

    $manager = livewire(GroupsRelationManager::class, ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class])
        ->mountTableAction('linkGroup');

    // Taken by another center after the list of free groups was shown.
    $group->update(['organization_id' => Organization::factory()->create()->id]);
    $manager->setTableActionData(['telegram_group_id' => $group->id])->callMountedTableAction();

    expect(aet_sent())->toBe([[
        'title' => 'Группа не привязана',
        'body' => 'Группа уже привязана к другому учебному центру. Сначала отвяжите её там.',
    ]])->and($group->fresh()->organization_id)->not->toBe($organization->id);
});

it('words the revocation of a subscription that is no longer live', function (): void {
    $subscription = Subscription::factory()->create();
    aet_service_throws(SubscriptionService::class, 'revoke', SubscriptionException::notLive($subscription->id));

    livewire(ListSubscriptions::class)->callTableAction('revoke', $subscription, ['note' => 'Ошибочная выдача']);

    expect(aet_sent())->toBe([[
        'title' => 'Не выполнено',
        'body' => "Подписка №{$subscription->id} уже не действует, отзывать нечего.",
    ]]);
});

it('words a grant that ends right now: the form lets it through, the service wants a future date', function (): void {
    $this->travelTo('2026-10-28 09:27:19');
    $learner = Learner::factory()->create();

    // The picker takes the local time of config('learners.default_timezone'): 09:27:19 UTC is 14:27:19 in Tashkent.
    livewire(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $learner->id, 'perpetual' => false, 'until' => '2026-10-28 14:27:19', 'note' => 'Подарок'])
        ->assertHasNoActionErrors();

    expect(aet_sent())->toBe([[
        'title' => 'Не выполнено',
        'body' => 'Дата окончания должна быть в будущем.',
    ]])->and(Subscription::query()->count())->toBe(0);
});

// --- the wording itself: context, causes, the fallback ----------------------------------------------------------------

it('fills the placeholders from the exception context and shows providers and statuses by their captions', function (): void {
    expect(AdminErrors::for('payments', PaymentRefundException::providerRefundFailed('telegram_stars')))
        ->toBe('Провайдер (Telegram Stars) не подтвердил возврат. Ничего не изменено: проверьте кабинет провайдера и попробуйте позже.')
        ->and(AdminErrors::for('courses', CoursePublishException::invalidTransition('draft', 'published')))
        ->toBe('Курс нельзя перевести из статуса «Черновик» в статус «Опубликован».')
        ->and(AdminErrors::for('subscriptions', SubscriptionException::subscriberNotFound('organization', 7)))
        ->toBe('Получатель подписки не найден: Учебный центр №7.')
        ->and(AdminErrors::for('subscriptions', SubscriptionException::planNotGrantable('free')))
        ->toBe('Тариф «Free» нельзя выдать как подписку: выдать можно только платный тариф.')
        ->and(AdminErrors::for('subscriptions', SubscriptionException::tierUnknown('center_xl')))
        ->toBe('Неизвестный тариф центра: «center_xl».');
});

it('writes a fraction with a decimal comma and drops a zero one', function (): void {
    expect(AdminErrors::for('courses', CoursePublishException::translationsBelowThreshold(159, 200, 0.955, ['uz'])))
        ->toBe('Переводы (UZ) есть только для 159 из 200 слов (79,5%), а нужно не меньше 95,5%.')
        ->and(AdminErrors::for('courses', CoursePublishException::translationsBelowThreshold(5, 10, 0.95, ['ru', 'uz'])))
        ->toBe('Переводы (RU, UZ) есть только для 5 из 10 слов (50%), а нужно не меньше 95%.');
});

it('agrees the noun after «из» with the number of words: из 1 слова, из 21 слова, из 22 слов', function (int $total, string $words, string $locale): void {
    // The text is Russian whatever the locale of the request: English would pick "слова" for 21 and "слов" for 1.
    app()->setLocale($locale);

    expect(AdminErrors::for('courses', CoursePublishException::translationsBelowThreshold(0, $total, 0.95, ['uz'])))
        ->toBe("Переводы (UZ) есть только для 0 из {$total} {$words} (0%), а нужно не меньше 95%.");
})->with([
    'one' => [1, 'слова'],
    'two' => [2, 'слов'],
    'four' => [4, 'слов'],
    'five' => [5, 'слов'],
    'eleven' => [11, 'слов'],
    'twenty one' => [21, 'слова'],
    'twenty two' => [22, 'слов'],
    'a hundred and eleven' => [111, 'слов'],
    'a hundred and twenty one' => [121, 'слова'],
    'two hundred' => [200, 'слов'],
])->with(['ru', 'en']);

it('picks the sentence of the cause of a refusal and falls back to the general one', function (): void {
    expect(AdminErrors::for('payments', PaymentRefundException::notRefundable('x', PaymentRefundException::CAUSE_STATE)))
        ->toBe('Вернуть можно только проведённую оплату или продление.')
        ->and(AdminErrors::for('payments', PaymentRefundException::notRefundable('x', PaymentRefundException::CAUSE_NO_CHARGE_ID)))
        ->toBe('У платежа нет ID списания у провайдера — вернуть его через панель нельзя.')
        ->and(AdminErrors::for('payments', PaymentRefundException::notRefundable('x', 'a cause nobody worded')))
        ->toBe('Этот платёж нельзя вернуть.');
});

it('shares the sentence of an error several screens raise', function (): void {
    $forbidden = __('admin.common.errors.forbidden');

    expect(AdminErrors::for('courses', CoursePublishException::forbidden()))->toBe($forbidden)
        ->and(AdminErrors::for('payments', PaymentRefundException::forbidden()))->toBe($forbidden)
        ->and(AdminErrors::for('learners.offers', OfferException::forbidden()))->toBe($forbidden);
});

it('never shows a raw key or the English message for an error that has no text', function (): void {
    $generic = __('admin.common.errors.unknown');
    $english = CheckoutException::alreadyPerpetual();

    expect(aet_has_cyrillic($generic))->toBeTrue()
        // a billing error of another screen, a code made up now, a plain exception, an empty code
        ->and(AdminErrors::for('payments', $english))->toBe($generic)
        ->and(AdminErrors::for('payments', new RuntimeException('Something broke in English')))->toBe($generic)
        ->and(AdminErrors::for('courses', new CoursePublishException('brand_new_reason', 'English message')))->toBe($generic)
        ->and(AdminErrors::text('payments', 'brand_new_code'))->toBe($generic)
        ->and(AdminErrors::text('payments', ''))->toBe($generic)
        ->and(AdminErrors::text('no.such.screen', 'not_open'))->toBe($generic);

    foreach ([AdminErrors::for('payments', $english), AdminErrors::text('payments', 'brand_new_code')] as $shown) {
        expect($shown)->not->toContain('admin.')->not->toContain('brand_new_code')->not->toContain($english->getMessage());
    }
});

it('keeps the English message and the code of the domain errors untouched', function (): void {
    $exception = PaymentRefundException::notRefundable('it has no provider charge id', PaymentRefundException::CAUSE_NO_CHARGE_ID);

    expect($exception->getMessage())->toBe('This payment cannot be refunded: it has no provider charge id.')
        ->and($exception->errorCode)->toBe('not_refundable')
        ->and($exception->httpStatus())->toBe(409)
        ->and(CoursePublishException::noLessons()->getMessage())->toBe('The course has no lessons.')
        ->and(OrganizationGroupLinkException::groupTaken())->toBeInstanceOf(DomainException::class)
        ->and(OrganizationGroupLinkException::groupTaken()->getMessage())->toBe('The group already belongs to another organization.');
});

it('keeps the English errors of an import report for the audit record next to the coded issues', function (): void {
    $report = (new ImportReport);
    $report->abort(ImportIssue::DUPLICATE_WORD, "Duplicate word 'cat' at index 1.", ['word' => 'cat', 'position' => 2]);

    expect($report->errors)->toBe(["Duplicate word 'cat' at index 1."])
        ->and($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('duplicate_word')
        ->and($report->aborted)->toBeTrue()
        ->and($report->ok())->toBeFalse()
        ->and($report->toArray()['errors'])->toBe(["Duplicate word 'cat' at index 1."])
        ->and(AdminErrors::importIssue($report->issues[0]))->toBe('Слово «cat» повторяется в файле (повтор — под номером 2).');
});

/** Codes of the issues the importer reports for a raw file; each issue keeps the English text that `errors` lists. */
function aet_import_codes(string $raw, ?User $by = null): array
{
    $report = app(VocabularyImporter::class)->import($raw, $by ?? test()->admin);

    expect($report->aborted)->toBeTrue()
        ->and(count($report->errors))->toBe(count($report->issues))
        ->and(array_map(fn (ImportIssue $issue): string => $issue->message, $report->issues))->toBe($report->errors);

    return array_map(fn (ImportIssue $issue): string => $issue->code, $report->issues);
}

it('gives every refusal of the importer a code, and the values behind its English text', function (): void {
    $file = fn (array $words, array $extra = []): string => json_encode($extra + ['stage' => ['number' => 1], 'lesson' => ['number' => 1], 'words' => $words], JSON_THROW_ON_ERROR);

    expect(aet_import_codes('5'))->toBe(['root_not_object'])
        ->and(aet_import_codes(str_repeat(' ', VocabularyImporter::MAX_BYTES + 1)))->toBe(['file_too_large'])
        ->and(aet_import_codes($file([['word' => 'cat', 'translation' => 'кошка']], ['course' => 'nope'])))->toBe(['course_not_found'])
        ->and(aet_import_codes($file([['word' => 'cat', 'translation' => 'кошка'], ['word' => 'CAT', 'translation' => 'кот']])))->toBe(['duplicate_word'])
        ->and(aet_import_codes($file([['word' => 'cat']])))->toBe(['ru_required'])
        ->and(aet_import_codes($file([['word' => 'cat', 'translation' => 'кошка', 'translations' => ['ru' => 'кот']]])))->toBe(['translation_conflict'])
        ->and(aet_import_codes($file([['word' => 'cat', 'translations' => ['ru' => 'кошка', 'de' => 'Katze']]])))->toBe(['unsupported_locale'])
        ->and(aet_import_codes($file([['word' => 'cat', 'translations' => ['ru' => 'кошка'], 'example_translations' => ['uz' => 'mushuk']]])))->toBe(['example_translation_orphan'])
        ->and(aet_import_codes($file([['word' => 'cat', 'translation' => 'кошка']], ['stage' => ['number' => 0]])))->toBe(['validation']);

    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    expect(aet_import_codes($file([['word' => 'cat', 'translation' => 'кошка']]), $teacher))->toBe(['not_allowed']);
});

it('tells the few JSON mistakes a person can fix from the rest', function (): void {
    $cause = function (string $raw): ?string {
        $report = app(VocabularyImporter::class)->import($raw, test()->admin);

        return $report->issues[0]->context['cause'] ?? null;
    };

    expect($cause('not-json'))->toBe(ImportIssue::CAUSE_SYNTAX)
        ->and($cause("{\"stage\": \"\xB1\"}"))->toBe(ImportIssue::CAUSE_ENCODING)
        ->and($cause(str_repeat('[', 40).str_repeat(']', 40)))->toBe(ImportIssue::CAUSE_DEPTH);
});

// --- nothing is left without a Russian text ----------------------------------------------------------------------------

/**
 * Every code a domain error class can carry, in the order [class, screen, extra codes raised from outside the class].
 * A code added to one of these classes without a Russian text fails here, not on a teacher's screen.
 */
dataset('admin error codes', [
    'course status moves' => [CoursePublishException::class, 'courses', []],
    'subscriptions' => [SubscriptionException::class, 'subscriptions', []],
    'refunds' => [PaymentRefundException::class, 'payments', [PaymentApplyException::ALREADY_REFUNDED, PaymentApplyException::PAYMENT_NOT_FOUND]],
    'discounts' => [OfferException::class, 'learners.offers', []],
    'membership' => [OrganizationMembershipException::class, 'organizations.members', []],
    'group linking' => [OrganizationGroupLinkException::class, 'organizations.groups', []],
    'lesson import' => [ImportIssue::class, 'pages.import_lesson', []],
]);

it('has a Russian text for every code of the domain error classes the panel catches', function (string $class, string $scope, array $extra): void {
    $codes = collect((new ReflectionClass($class))->getConstants())
        ->reject(fn (mixed $value, string $name): bool => ! is_string($value) || str_starts_with($name, 'CAUSE_'))
        ->values()
        ->merge($extra);

    expect($codes)->not->toBeEmpty();

    $missing = $codes->reject(function (string $code) use ($scope): bool {
        foreach (["admin.{$scope}.errors.{$code}", "admin.common.errors.{$code}"] as $key) {
            if (Lang::has($key) && is_string($text = Lang::get($key)) && aet_has_cyrillic($text)) {
                return true;
            }
        }

        return false;
    });

    expect($missing->all())->toBe([]);
})->with('admin error codes');

it('has a Russian sentence for every cause that narrows a refusal down', function (): void {
    $causes = [
        'payments' => ['not_refundable' => [PaymentRefundException::CAUSE_STATE, PaymentRefundException::CAUSE_NO_CHARGE_ID]],
        'pages.import_lesson' => [
            'invalid_json' => [ImportIssue::CAUSE_SYNTAX, ImportIssue::CAUSE_ENCODING, ImportIssue::CAUSE_DEPTH],
        ],
    ];

    foreach ($causes as $scope => $codes) {
        foreach ($codes as $code => $list) {
            foreach ($list as $cause) {
                expect(Lang::has("admin.{$scope}.errors.{$code}_{$cause}"))->toBeTrue("admin.{$scope}.errors.{$code}_{$cause}");
            }
        }
    }
});

/** @return array<string, string> path of every PHP file of the admin panel's code (the center cabinet `Org` is lang/ru/org.php) => its source */
function aet_admin_sources(): array
{
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Org'.DIRECTORY_SEPARATOR)) {
            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

it('has an errors block in lang/ru/admin.php for every screen that words a domain error', function (): void {
    $scopes = [];

    foreach (aet_admin_sources() as $path => $source) {
        preg_match_all("/AdminErrors::(?:for|text)\\(\\s*'([a-z_.]+)'/", $source, $matches);

        foreach ($matches[1] as $scope) {
            $scopes[$scope] = basename($path);
        }
    }

    // Guards the guard: the scan found the six screens (the import page words its issues through importIssue()), not nothing.
    expect(array_keys($scopes))->toEqualCanonicalizing(['courses', 'subscriptions', 'payments', 'learners.offers', 'organizations.members', 'organizations.groups']);

    foreach ($scopes as $scope => $file) {
        expect(Lang::has("admin.{$scope}.errors", 'ru', false))->toBeTrue("{$file} words errors of admin.{$scope}.errors");
    }
});

it('never shows the message of an exception in the admin panel', function (): void {
    $offenders = [];

    foreach (aet_admin_sources() as $path => $source) {
        if (str_contains($source, 'getMessage()')) {
            $offenders[] = basename($path);
        }
    }

    expect(count(aet_admin_sources()))->toBeGreaterThan(80)
        ->and($offenders)->toBe([]);
});
