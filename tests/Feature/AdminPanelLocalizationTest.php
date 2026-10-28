<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Learners\Services\OnboardingService;
use App\Filament\Pages\ImportLesson;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\ExamSessionResource;
use App\Filament\Resources\ExamSessionResource\Pages\ListExamSessions;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LessonResource;
use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Filament\Resources\OrganizationResource\RelationManagers\InvitesRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\PaymentOrderResource;
use App\Filament\Resources\PaymentOrderResource\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PromoCodeResource;
use App\Filament\Resources\PromoCodeResource\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodeResource\Pages\ListPromoCodes;
use App\Filament\Resources\ProviderTransactionResource;
use App\Filament\Resources\ProviderTransactionResource\Pages\ListProviderTransactions;
use App\Filament\Resources\StageResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\TelegramGroupResource;
use App\Filament\Resources\TelegramGroupResource\Pages\ListTelegramGroups;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\WordResource;
use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\ExamsLast30DaysWidget;
use App\Filament\Widgets\HardestWordsTableWidget;
use App\Filament\Widgets\LeagueGroupsTableWidget;
use App\Filament\Widgets\OnboardingFunnelWidget;
use App\Filament\Widgets\OrganizationSeatsTableWidget;
use App\Filament\Widgets\PlatformSubscriptionsWidget;
use App\Filament\Widgets\RevenueLast30DaysWidget;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Filament\Widgets\TopLearnersTableWidget;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Filament\Widgets\WebhookErrorsWidget;
use App\Models\Course;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\PromoCode;
use App\Models\ProviderTransaction;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * The admin panel `/admin` is Russian (docs/14 §7–§8, lang/ru/admin.php): our own captions of resources, fields, columns,
 * filters, actions, widgets and pages. Filament's own strings come from its ru translation. Only proper names stay Latin
 * (LexiFlow, Telegram, Stars, Payme, Click, IELTS, SAT, SM-2 / FSRS, Pro / Free ...).
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
});

function loc_user(string $role = 'admin'): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function loc_get(User $user, string $url): TestResponse
{
    return test()->actingAs($user)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])
        ->get($url);
}

/** What a person reads on the page: no scripts, styles, tags or markup entities. */
function loc_text(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html) ?? $html;

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) ?? '');
}

/** Names that stay Latin in the Russian interface; whatever Latin word is left after removing them is English. */
function loc_is_russian(string $caption): bool
{
    $rest = preg_replace('/\b(LexiFlow|Telegram|Stars|Payme|Click|IELTS|SAT|SM-2|FSRS(-5)?|Pro|Free|Mini App|Bot API|Username|Email|ID|TG|RU|UZ|ru|uz|UZS|XTR|JSON|slug|meta|CSV|twa\.authenticated)\b/u', '', $caption);

    return preg_match('/[A-Za-z]{2,}/', (string) $rest) !== 1;
}

/** @param  callable(): mixed  $resolve */
function loc_caption(callable $resolve): ?string
{
    try {
        $value = $resolve();
    } catch (Throwable) {
        return null; // a caption that needs a record is checked on the pages that have one
    }

    if ($value instanceof Htmlable) {
        $value = strip_tags($value->toHtml());
    }

    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * One record of every admin model: enough to open every list, edit and view page and every relation manager.
 *
 * @return array<class-string, mixed> record by resource class
 */
function loc_records(User $admin): array
{
    $course = Course::factory()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id]);
    $learner = Learner::factory()->create();

    return [
        CourseResource::class => $course,
        StageResource::class => $stage,
        LessonResource::class => $lesson,
        WordResource::class => Word::factory()->create(['lesson_id' => $lesson->id]),
        LearnerResource::class => $learner,
        TelegramGroupResource::class => TelegramGroup::factory()->create(),
        OrganizationResource::class => Organization::factory()->create(),
        ExamSessionResource::class => ExamSession::factory()->create(),
        SubscriptionResource::class => Subscription::factory()->create(['subscriber_id' => $learner->id]),
        PromoCodeResource::class => PromoCode::factory()->create(),
        PaymentOrderResource::class => PaymentOrder::factory()->create(),
        PaymentResource::class => Payment::factory()->create(),
        ProviderTransactionResource::class => ProviderTransaction::factory()->create(),
        UserResource::class => $admin,
    ];
}

/**
 * Every caption of a Livewire component with a table, a form or an infolist: title, header actions, columns, filters,
 * row / bulk actions, fields, entries, sections, hints and placeholders.
 *
 * @return array<string, string> "where" => caption
 */
function loc_component_captions(object $component, string $where): array
{
    $captions = [];
    $add = function (string $what, ?string $caption) use (&$captions, $where): void {
        if ($caption !== null) {
            $captions["{$where} {$what}"] = $caption;
        }
    };
    $addAction = function (Action|ActionGroup $action) use ($add, &$addAction): void {
        if ($action instanceof ActionGroup) {
            $add('group', loc_caption(fn () => $action->getLabel()));
            foreach ($action->getActions() as $inner) {
                $addAction($inner);
            }

            return;
        }

        $name = $action->getName();
        $add("action[{$name}]", loc_caption(fn () => $action->getLabel()));
        $add("action[{$name}] heading", loc_caption(fn () => $action->getModalHeading()));
        $add("action[{$name}] description", loc_caption(fn () => $action->getModalDescription()));
    };

    if (method_exists($component, 'getTitle')) {
        $add('title', loc_caption(fn () => $component->getTitle()));
    }

    if (method_exists($component, 'getCachedHeaderActions')) {
        foreach ($component->getCachedHeaderActions() as $action) {
            $addAction($action);
        }
    }

    if (method_exists($component, 'getTable')) {
        /** @var Table $table */
        $table = $component->getTable();

        $add('table heading', loc_caption(fn () => $table->getHeading()));
        $add('table empty state', loc_caption(fn () => $table->getEmptyStateHeading()));

        foreach ($table->getColumns() as $name => $column) {
            $add("column[{$name}]", loc_caption(fn () => $column->getLabel()));
        }
        foreach ($table->getFilters() as $name => $filter) {
            $add("filter[{$name}]", loc_caption(fn () => $filter->getLabel()));
            $add("filter[{$name}] placeholder", loc_caption(fn () => $filter->getPlaceholder()));
        }
        // Without the keys: a row action and a bulk action are both called "delete", and spreading by name would drop the first.
        foreach ([...array_values($table->getFlatRecordActions()), ...array_values($table->getHeaderActions()), ...array_values($table->getFlatBulkActions())] as $action) {
            $addAction($action);
        }
    }

    foreach (['form', 'infolist'] as $schemaName) {
        try {
            $schema = $component->getSchema($schemaName);
        } catch (Throwable) {
            continue;
        }

        if (! $schema instanceof Schema) {
            continue;
        }

        foreach ($schema->getFlatComponents(withHidden: true) as $field) {
            $name = method_exists($field, 'getName') ? (string) $field->getName() : class_basename($field);

            foreach (['getLabel' => 'label', 'getHeading' => 'heading', 'getPlaceholder' => 'placeholder', 'getHelperText' => 'hint'] as $method => $what) {
                if (method_exists($field, $method)) {
                    $add("{$schemaName} {$name} {$what}", loc_caption(fn () => $field->{$method}()));
                }
            }
        }
    }

    return $captions;
}

/**
 * Every action of a Livewire component: page header, table rows, table header and bulk actions, groups unfolded.
 *
 * @return list<Action>
 */
function loc_component_actions(object $component): array
{
    $actions = [];
    $collect = function (Action|ActionGroup $action) use (&$actions, &$collect): void {
        if ($action instanceof ActionGroup) {
            foreach ($action->getActions() as $inner) {
                $collect($inner);
            }

            return;
        }

        $actions[] = $action;
    };

    if (method_exists($component, 'getCachedHeaderActions')) {
        foreach ($component->getCachedHeaderActions() as $action) {
            $collect($action);
        }
    }

    if (method_exists($component, 'getTable')) {
        /** @var Table $table */
        $table = $component->getTable();

        // Without the keys: a row action and a bulk action are both called "delete", and spreading by name would drop the first.
        foreach ([...array_values($table->getFlatRecordActions()), ...array_values($table->getHeaderActions()), ...array_values($table->getFlatBulkActions())] as $action) {
            $collect($action);
        }
    }

    return $actions;
}

// --- navigation ------------------------------------------------------------------------------

it('shows the Russian navigation groups and items on every kind of admin page', function (string $url): void {
    $text = loc_text(loc_get(loc_user(), $url)->assertOk()->getContent());

    foreach ([
        'Контент', 'Курсы', 'Модули', 'Уроки', 'Слова', 'Импорт урока',
        'Обучение', 'Экзамены',
        'Оплата', 'Подписки', 'Промокоды', 'Учебные центры', 'Платежи', 'Заказы', 'Транзакции провайдеров',
        'Администрирование', 'Сотрудники', 'Telegram-группы', 'Ученики',
    ] as $caption) {
        expect($text)->toContain($caption);
    }

    // The old English captions are gone: group names, resource names, "Create Stage", "Words" ...
    expect($text)->not->toMatch('/\b(Stages?|Words|Lessons?|Courses|Content|Learning|Administration|Billing|Learners?|Users|Telegram Groups|Exam Logs|Exam Sessions|Subscriptions|Promo Codes|Organizations|Payment Orders|Payments|Provider Transactions|Import Lesson)\b/u')
        // ... and no raw translation key is left on the page.
        ->and($text)->not->toMatch('/\badmin\.[a-z0-9_]+\.[a-z0-9_.]+/');
})->with([
    'dashboard' => '/admin',
    'lessons list' => '/admin/lessons',
    'stage form' => '/admin/stages/create',
    'import page' => '/admin/import-lesson',
    'organizations list' => '/admin/organizations',
]);

it('titles the lesson list and the stage form in Russian and offers "Создать" instead of "Create Stage"', function (): void {
    $lessons = loc_text(loc_get(loc_user(), '/admin/lessons')->assertOk()->getContent());
    expect($lessons)->toContain('Уроки', 'Создать урок', 'Модуль')
        ->and($lessons)->not->toContain('Stage', 'Words');

    $form = loc_text(loc_get(loc_user(), '/admin/stages/create')->assertOk()->getContent());
    expect($form)->toContain('Новый модуль', 'Курс', 'Номер', 'Название', 'Описание')
        ->and($form)->not->toContain('Create Stage', 'Создать Stage');
});

it('shows a teacher the Russian navigation too', function (): void {
    $teacher = loc_user('teacher');
    $teacher->telegramGroups()->attach(TelegramGroup::factory()->create()->id);

    $text = loc_text(loc_get($teacher, '/admin')->assertOk()->getContent());

    expect($text)->toContain('Контент', 'Обучение', 'Администрирование', 'Экзамены', 'Ученики', 'Telegram-группы', 'Уроки')
        ->and($text)->not->toMatch('/\b(Content|Learning|Administration|Learners|Exam Logs|Telegram Groups)\b/u');
});

it('has Russian navigation labels, model labels and groups on every admin resource', function (): void {
    $resources = Filament::getPanel('admin')->getResources();

    expect($resources)->not->toBeEmpty();

    foreach ($resources as $resource) {
        foreach ([
            'navigation label' => $resource::getNavigationLabel(),
            'navigation group' => $resource::getNavigationGroup(),
            'model label' => $resource::getModelLabel(),
            'plural label' => $resource::getPluralModelLabel(),
        ] as $what => $caption) {
            expect(is_string($caption) && loc_is_russian($caption) && $caption !== '')->toBeTrue("{$resource} {$what}: {$caption}");
        }
    }

    foreach (Filament::getPanel('admin')->getPages() as $page) {
        expect(loc_is_russian((string) $page::getNavigationLabel()))->toBeTrue("{$page} navigation label")
            ->and(loc_is_russian((string) $page::getNavigationGroup()))->toBeTrue("{$page} navigation group");
    }
});

// --- every caption of every screen ------------------------------------------------------------

it('has an explicit Russian caption on every column, filter, action, field, entry and tab of the admin resources', function (): void {
    $admin = loc_user();
    $this->actingAs($admin);
    $records = loc_records($admin);
    $captions = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! array_key_exists($resource, $records)) {
            $this->fail("Add a record of {$resource} to loc_records().");
        }

        $record = $records[$resource];
        $ownerPage = null;

        foreach ($resource::getPages() as $name => $registration) {
            $page = $registration->getPage();
            $params = in_array($name, ['edit', 'view'], true) ? ['record' => $record->getKey()] : [];
            $captions += loc_component_captions(Livewire::test($page, $params)->instance(), "{$resource}/{$name}");

            if (in_array($name, ['edit', 'view'], true)) {
                $ownerPage = $page;
            }
        }

        foreach ($resource::getRelations() as $manager) {
            /** @var class-string<RelationManager> $manager */
            $where = "{$resource} tab ".class_basename($manager);
            $captions["{$where} title"] = $manager::getTitle($record, (string) $ownerPage);
            $captions += loc_component_captions(
                Livewire::test($manager, ['ownerRecord' => $record, 'pageClass' => $ownerPage])->instance(),
                $where,
            );
        }
    }

    // Guards the guard: the walk really reached the tables, forms and tabs.
    expect(count($captions))->toBeGreaterThan(400);

    $english = array_filter($captions, fn (string $caption): bool => ! loc_is_russian($caption));

    expect($english)->toBe([]);
});

it('has Russian captions in the custom dialogs of the admin actions', function (): void {
    $admin = loc_user();
    $this->actingAs($admin);
    $learner = Learner::factory()->create();
    $org = Organization::factory()->create();
    $payment = Payment::factory()->create(['provider' => 'click']);
    $dialogs = [
        'grant Pro' => [fn () => livewire(ListSubscriptions::class)->mountAction('grantPro')->setActionData(['subscriber_type' => 'organization', 'tier' => 'center_l']), 'Выдать Pro вручную', ['Тариф центра', 'Причина', 'Без срока']],
        'refund' => [fn () => livewire(ListPayments::class)->mountTableAction('refund', $payment), 'Вернуть платёж', ['Причина', 'Деньги уже возвращены вручную в кабинете провайдера']],
        'scheduler' => [fn () => livewire(ListLearners::class)->mountTableAction('switchScheduler', $learner), 'Алгоритм повторений', ['Алгоритм']],
        'create invite' => [fn () => Livewire::test(InvitesRelationManager::class, ['ownerRecord' => $org, 'pageClass' => EditOrganization::class])->mountTableAction('createInvite'), 'Создать приглашение', ['Метка', 'Максимум использований', 'Пусто — бессрочно.']],
        'add member' => [fn () => Livewire::test(MembersRelationManager::class, ['ownerRecord' => $org, 'pageClass' => EditOrganization::class])->mountTableAction('addMember'), 'Добавить ученика', ['Ученик', 'Поиск по имени, @username или Telegram ID.']],
    ];

    foreach ($dialogs as $name => [$mount, $heading, $captions]) {
        /** @var Testable $dialog */
        $dialog = $mount();
        $dialog->assertMountedActionModalSee([$heading, ...$captions]);

        $text = loc_text($dialog->getMountedActionModalHtml());
        // Filament's own accessibility strings that its ru translation lacks are not ours: drop their raw keys first.
        $text = preg_replace('/\bfilament[\w-]*::[\w.]+/', '', $text) ?? $text;
        // The select widget ships its Alpine config (JS) in the dialog: keep the words a person reads only.
        $text = preg_replace('/\{.*\}/s', '', $text) ?? $text;

        expect(preg_match('/\b(Grant|Revoke|Refund|Reason|Learner|Organization|Algorithm|Invite|Label|Tier|Seats)\b/', $text))->toBe(0, "{$name} still has an English caption");
    }
});

// --- dialogs: «Удалить сотрудника», not «Удалить Сотрудник» -------------------------------------------

it('words the dialogs that delete a user, a promo code or an organization in lower case and in the accusative', function (): void {
    $this->actingAs(loc_user());
    $teacher = loc_user('teacher');
    $promo = PromoCode::factory()->create();
    $organization = Organization::factory()->create();

    // Filament's own «Удалить :label» would put the label as it is named in the list: «Удалить Сотрудник».
    $heading = static fn (Testable $component): string => (string) $component->instance()->getMountedAction()?->getModalHeading();

    expect($heading(livewire(ListUsers::class)->mountTableAction('delete', $teacher)))->toBe('Удалить сотрудника')
        ->and($heading(livewire(EditUser::class, ['record' => $teacher->getKey()])->mountAction('delete')))->toBe('Удалить сотрудника')
        ->and((string) livewire(ListUsers::class)->instance()->getTable()->getFlatBulkActions()['delete']->getModalHeading())->toBe('Удалить выбранных сотрудников')
        ->and($heading(livewire(ListPromoCodes::class)->mountTableAction('delete', $promo)))->toBe('Удалить промокод')
        ->and($heading(livewire(EditPromoCode::class, ['record' => $promo->getKey()])->mountAction('delete')))->toBe('Удалить промокод')
        ->and($heading(livewire(ListOrganizations::class)->mountTableAction('delete', $organization)))->toBe('Удалить учебный центр')
        ->and($heading(livewire(EditOrganization::class, ['record' => $organization->getKey()])->mountAction('delete')))->toBe('Удалить учебный центр');
});

it('puts no resource label in the nominative with a capital letter into the heading of any dialog of the admin panel', function (): void {
    $admin = loc_user();
    $this->actingAs($admin);
    $records = loc_records($admin);
    $checked = [];
    $wrong = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $record = $records[$resource];
        $ownerPage = null;
        $components = [];

        foreach ($resource::getPages() as $name => $registration) {
            $page = $registration->getPage();
            $components["{$resource}/{$name}"] = Livewire::test($page, in_array($name, ['edit', 'view'], true) ? ['record' => $record->getKey()] : [])->instance();

            if (in_array($name, ['edit', 'view'], true)) {
                $ownerPage = $page;
            }
        }

        foreach ($resource::getRelations() as $manager) {
            $components["{$resource} tab ".class_basename($manager)] = Livewire::test($manager, ['ownerRecord' => $record, 'pageClass' => $ownerPage])->instance();
        }

        foreach ($components as $where => $component) {
            foreach (loc_component_actions($component) as $action) {
                // An action that goes to a page opens no dialog, so nobody reads its heading.
                try {
                    $navigates = $action->getUrl() !== null;
                } catch (Throwable) {
                    $navigates = true; // the address needs the row
                }

                $heading = $navigates ? null : loc_caption(fn () => $action->getModalHeading());

                if ($heading === null) {
                    continue;
                }

                $key = "{$where} ".($action instanceof BulkAction ? 'bulk ' : '').$action->getName();
                $checked[$key] = $heading;

                // «Удалить :label», «Создать :label», «Восстановить :label»... take the label of the list: «Сотрудник», «Урок».
                foreach (array_filter([$action->getModelLabel(), $action->getPluralModelLabel()]) as $label) {
                    foreach (array_unique([Str::ucfirst($label), Str::ucwords($label)]) as $capitalised) {
                        if (mb_strpos($heading, $capitalised, 1) !== false) {
                            $wrong[$key] = $heading;
                        }
                    }
                }
            }
        }
    }

    // Guards the guard: the walk reached the delete and the create dialogs, not only the custom ones.
    expect(array_filter(array_keys($checked), fn (string $key): bool => str_ends_with($key, ' delete')))->toHaveCount(8)
        ->and($checked)->toHaveKey(StageResource::class.' tab LessonsRelationManager create')
        ->and($wrong)->toBe([]);
});

// --- widgets ----------------------------------------------------------------------------------

it('titles every dashboard widget in Russian', function (string $widget, string $russian, string $english): void {
    $this->actingAs(loc_user());

    Livewire::test($widget)->assertOk()->assertSee($russian)->assertDontSee($english);
})->with([
    [TotalLearnersWidget::class, 'Всего учеников', 'Total learners'],
    [ExamsLast30DaysWidget::class, 'Экзаменов за 30 дней', 'Exams last 30 days'],
    [ActivityChartWidget::class, 'Активность тренировок за 30 дней', 'Training activity'],
    [TopLearnersTableWidget::class, 'Лучшие ученики за 30 дней', 'Top learners'],
    [HardestWordsTableWidget::class, 'Самые трудные слова за 30 дней', 'Hardest words'],
    [PlatformSubscriptionsWidget::class, 'Подписки Pro', 'Pro subscriptions'],
    [OnboardingFunnelWidget::class, 'Первичная настройка — 7 дней', 'Onboarding funnel'],
    [SchedulerComparisonWidget::class, 'SM-2 и FSRS за последние 28 дней', 'last 28 days'],
    [OrganizationSeatsTableWidget::class, 'Учебные центры — места и очередь', 'seats / waiting'],
    [LeagueGroupsTableWidget::class, 'Лиги — текущая неделя: групп пока нет', 'Leagues'],
    [RevenueLast30DaysWidget::class, 'Выручка за 30 дней', 'Revenue, last 30 days'],
    [WebhookErrorsWidget::class, 'Ошибки вебхуков оплаты за 24 часа', 'Payment webhook errors'],
]);

it('lists every widget of the panel in the dataset above', function (): void {
    expect(Filament::getPanel('admin')->getWidgets())->toHaveCount(12);
});

// --- values -----------------------------------------------------------------------------------

it('shows stored statuses and kinds as Russian captions and leaves the stored values alone', function (): void {
    $this->actingAs(loc_user());

    $course = Course::factory()->published()->create(['visibility' => 'unlisted', 'exam_tag' => 'ielts']);
    $group = TelegramGroup::factory()->create(['status' => 'pending']);
    $organization = Organization::factory()->suspended()->create();
    $exam = ExamSession::factory()->create(['status' => 'open']);
    $subscription = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'past_due']);
    $order = PaymentOrder::factory()->create(['status' => 'paid', 'provider' => 'payme', 'period' => 'year', 'created_via' => 'org_panel']);
    $payment = Payment::factory()->create(['kind' => 'renewal', 'status' => 'refunded', 'provider' => 'click']);
    $transaction = ProviderTransaction::factory()->canceled()->create(['provider' => 'click']);

    livewire(ListCourses::class)
        ->assertTableColumnFormattedStateSet('status', 'Опубликован', $course)
        ->assertTableColumnFormattedStateSet('visibility', 'По ссылке или из группы', $course)
        ->assertTableColumnFormattedStateSet('exam_tag', 'IELTS', $course);
    livewire(ListTelegramGroups::class)->assertTableColumnFormattedStateSet('status', 'Ожидает активации', $group);
    livewire(ListOrganizations::class)
        ->assertTableColumnFormattedStateSet('status', 'Приостановлен', $organization)
        ->assertTableColumnFormattedStateSet('type', 'Учебный центр', $organization);
    livewire(ListExamSessions::class)->assertTableColumnFormattedStateSet('status', 'Открыт', $exam);
    livewire(ListSubscriptions::class)
        ->assertTableColumnFormattedStateSet('status', 'Ожидает оплаты', $subscription)
        ->assertTableColumnFormattedStateSet('provider', 'Telegram Stars', $subscription)
        ->assertTableColumnFormattedStateSet('plan', 'Pro', $subscription)
        ->assertTableColumnFormattedStateSet('subscriber_type', 'Ученик', $subscription);
    livewire(ListPaymentOrders::class)
        ->assertTableColumnFormattedStateSet('status', 'Оплачен', $order)
        ->assertTableColumnFormattedStateSet('provider', 'Payme', $order)
        ->assertTableColumnFormattedStateSet('period', 'Год', $order)
        ->assertTableColumnFormattedStateSet('created_via', 'Кабинет центра', $order);
    livewire(ListPayments::class)
        ->assertTableColumnFormattedStateSet('kind', 'Продление', $payment)
        ->assertTableColumnFormattedStateSet('status', 'Возвращён', $payment)
        ->assertTableColumnFormattedStateSet('provider', 'Click', $payment);
    livewire(ListProviderTransactions::class)->assertTableColumnFormattedStateSet('state', 'Отменена', $transaction);

    expect($course->fresh()?->status)->toBe('published')
        ->and($course->fresh()?->visibility)->toBe('unlisted')
        ->and($group->fresh()?->status)->toBe('pending')
        ->and($exam->fresh()?->status)->toBe('open')
        ->and($subscription->fresh()?->status)->toBe('past_due')
        ->and($order->fresh()?->status)->toBe('paid')
        ->and($payment->fresh()?->kind)->toBe('renewal');
});

it('shows the roles of staff users in Russian and keeps the role names', function (): void {
    $admin = loc_user();
    $teacher = loc_user('teacher');
    $this->actingAs($admin);

    livewire(ListUsers::class)
        ->assertCanSeeTableRecords([$admin, $teacher])
        ->assertSee('Администратор')
        ->assertSee('Учитель')
        ->assertDontSee('>admin<', false)
        ->assertDontSee('>teacher<', false);

    expect($teacher->fresh()?->hasRole('teacher'))->toBeTrue();
});

it('names the lesson of an exam as "Модуль N · Урок M"', function (): void {
    $this->actingAs(loc_user());
    $stage = Stage::factory()->create(['number' => 2]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 4]);
    $exam = ExamSession::factory()->create(['lesson_id' => $lesson->id]);

    livewire(ListExamSessions::class)->assertTableColumnFormattedStateSet('lesson.number', 'Модуль 2 · Урок 4', $exam);
});

it('captions the plan of a learner as Free, Pro or Pro (от центра)', function (): void {
    expect(LearnerResource::planLabel(Plan::Free, false))->toBe('Free')
        ->and(LearnerResource::planLabel(Plan::Pro, false))->toBe('Pro')
        ->and(LearnerResource::planLabel(Plan::Pro, true))->toBe('Pro (от центра)');
});

it('captions every onboarding state of a learner in Russian, also "new" that a learner has until the first answer', function (): void {
    $this->actingAs(loc_user());

    // A new learner is `new` (LearnerRegistrar), then the step being answered, then `done`.
    foreach (['new', ...OnboardingService::STEPS, OnboardingService::DONE] as $state) {
        $caption = LearnerResource::onboardingLabel($state);

        expect(loc_is_russian($caption))->toBeTrue("{$state}: {$caption}")
            ->and($caption)->not->toContain($state);

        livewire(ViewLearner::class, ['record' => Learner::factory()->create(['onboarding_state' => $state])->getKey()])
            ->assertSee($caption)
            ->assertDontSee("шаг: {$state}");
    }

    expect(LearnerResource::onboardingLabel('new'))->toBe('Не начата')
        ->and(LearnerResource::onboardingLabel('goal'))->toBe('В процессе (шаг: цель)')
        ->and(LearnerResource::onboardingLabel('done'))->toBe('Пройдена');
});

it('shows the badges of payment orders in Russian', function (): void {
    $this->actingAs(loc_user());
    $flagged = PaymentOrder::factory()->create(['meta' => ['paid_after_cancel' => true, 'canceled_reason' => 'tier_changed', 'tier_mismatch' => true, 'tier_mismatch_live_tier' => 'tutor']]);

    livewire(ListPaymentOrders::class)
        ->assertTableColumnStateSet('paid_after_cancel', 'оплачен после отмены', $flagged)
        ->assertTableColumnFormattedStateSet('canceled_reason', 'Сменился тариф центра', $flagged)
        ->assertTableColumnStateSet('tier_mismatch', 'тариф не совпал (действует: Репетитор)', $flagged);
});

// --- the language setup -----------------------------------------------------------------------

it('serves lang/ru/admin.php to every locale through the ru fallback', function (): void {
    app()->setFallbackLocale('ru');

    foreach (['ru', 'uz', 'en'] as $locale) {
        app()->setLocale($locale);

        expect(__('admin.nav.content'))->toBe('Контент')
            ->and(__('admin.stages.plural'))->toBe('Модули');
    }
});

it('has no admin.php of its own in the other locales', function (): void {
    expect(file_exists(lang_path('uz/admin.php')))->toBeFalse()
        ->and(file_exists(lang_path('en/admin.php')))->toBeFalse();
});

it('defines every admin.* key the admin code asks for', function (): void {
    $files = [resource_path('views/filament/pages/import-lesson.blade.php')];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        // `app/Filament/Org` is the center cabinet: its captions are lang/ru/org.php.
        if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Org'.DIRECTORY_SEPARATOR)) {
            $files[] = $file->getPathname();
        }
    }

    $missing = [];
    foreach ($files as $path) {
        preg_match_all("/__\\(\\s*'(admin\\.[a-z0-9_.]+)'\\s*[,)]/i", (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $key) {
            if (! Lang::has($key, 'ru', false)) {
                $missing[$key] = basename($path);
            }
        }
    }

    expect(count($files))->toBeGreaterThan(80)
        ->and($missing)->toBe([]);
});

it('translates the import page of the lesson importer', function (): void {
    $this->actingAs(loc_user());

    livewire(ImportLesson::class)
        ->assertSee('Скачать пример')
        ->assertSee('Файл урока (JSON)')
        ->assertSee('Импортировать')
        ->assertDontSee('Download sample')
        ->assertDontSee('Lesson JSON');

    expect(ImportLesson::getNavigationLabel())->toBe('Импорт урока')
        ->and(ImportLesson::getNavigationGroup())->toBe('Контент');
});
