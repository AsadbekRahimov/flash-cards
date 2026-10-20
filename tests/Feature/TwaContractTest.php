<?php

declare(strict_types=1);

/*
 * JSON contract of the TWA API (resources/twa/src/api/types.ts).
 * Exact key structure, no `data` envelope. If a test here fails, the TWA
 * client breaks — change the client and docs/04_API_AND_BOT.md together.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Learning\DTO\ServedExamQuestion;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ExamQuestionResource;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\Support\FakeBillingProvider;

uses(RefreshDatabase::class);

const CONTRACT_PROGRESS = ['done', 'total'];
const CONTRACT_GOAL = ['target', 'done', 'met'];
const CONTRACT_STREAK = ['current', 'best', 'met_today', 'at_risk', 'freezes', 'frozen_yesterday'];
const CONTRACT_CARD = ['word_id', 'word', 'translation', 'translation_fallback', 'example', 'transcription', 'audio_url', 'card_kind', 'serve_token'];
const CONTRACT_EXAM_QUESTION = ['question_index', 'word_id', 'word', 'options', 'seconds_left'];
const CONTRACT_FORBIDDEN_EXAM_KEYS = ['correct_index', 'correct_translation', 'canonical_index'];

function contractHeaders(Learner $learner): array
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{learner: Learner, group: TelegramGroup, lesson: Lesson, session: TrainingSession}
 */
function contractTraining(int $words = 3): array
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    Word::factory()->count($words)->for($lesson)->create();
    $session = TrainingSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => User::factory()->create()->id,
        'status' => 'open',
    ]);

    return compact('learner', 'group', 'lesson', 'session');
}

/**
 * @return array{learner: Learner, group: TelegramGroup, session: ExamSession}
 */
function contractExam(int $questions = 4): array
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $session = ExamSession::factory()->withQuestions($questions)->create([
        'telegram_group_id' => $group->id,
        'started_at' => now()->subMinute(),
        'ends_at' => now()->addMinutes(5),
    ]);
    $learner = Learner::factory()->memberOf($group)->create();

    return compact('learner', 'group', 'session');
}

// --- auth / me ------------------------------------------------------------

it('POST /auth keeps its exact JSON shape', function (): void {
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
    Learner::factory()->create(['telegram_user_id' => 777]);

    $initData = InitDataValidator::sign('test-bot-token-1234567890', [
        'user' => json_encode(['id' => 777, 'first_name' => 'Alex']),
        'auth_date' => (string) time(),
    ]);

    $response = $this->postJson('/api/twa/auth', ['init_data' => $initData])
        ->assertOk()
        ->assertExactJsonStructure([
            'token',
            'expires_in',
            'learner' => ['id', 'first_name', 'locale', 'onboarding_completed'],
        ]);

    expect($response->json('expires_in'))->toBeInt()
        ->and($response->json('learner.onboarding_completed'))->toBeFalse();
});

it('GET /me keeps its exact JSON shape', function (): void {
    $s = contractTraining();

    $response = $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertExactJsonStructure([
            'learner' => ['id', 'first_name', 'username', 'locale', 'onboarding_completed'],
            'groups' => [['id', 'title']],
            'stats' => ['words_learned', 'words_due_today'],
            'entitlement' => ['plan', 'source', 'until', 'new_words_per_day', 'renewal', 'lapsed'],
            'paywall' => ['mode', 'contact'],
            'settings' => [
                'interface_locale', 'timezone', 'reminder_time', 'daily_goal', 'goal', 'level', 'timezone_change_available_at', 'timezone_undo_available_until', 'active_course',
                'options' => [
                    'locales' => ['*' => ['id', 'label']],
                    'timezones' => ['*' => ['id', 'label']],
                    'reminder_times',
                    'daily_goals',
                ],
            ],
        ]);

    expect($response->json('stats'))->toBe(['words_learned' => 0, 'words_due_today' => 0])
        ->and($response->json('groups.0.id'))->toBe($s['group']->id)
        ->and($response->json('learner.id'))->toBe($s['learner']->id);
});

it('GET /today keeps its exact JSON shape, with and without an active course', function (): void {
    $lesson = Lesson::factory()->for(Stage::factory()->for(Course::factory()->published())->create(['number' => 1]))->create(['number' => 1]);
    Word::factory()->for($lesson)->create();
    $learner = Learner::factory()->onboarded()->create(['active_course_id' => $lesson->stage?->course_id]);
    TrainingSession::factory()->personal($learner, 'daily_review')->create();

    $response = $this->withHeaders(contractHeaders($learner))
        ->getJson('/api/twa/today')
        ->assertOk()
        ->assertExactJsonStructure([
            'due_count',
            'new_words_remaining',
            'goal' => CONTRACT_GOAL,
            'streak' => CONTRACT_STREAK,
            'active_course' => [
                'id', 'slug', 'title',
                'progress' => ['learned', 'total'],
                'next_lesson' => ['id', 'stage', 'lesson', 'title'],
            ],
            'open_session_id',
            'entitlement' => ['plan', 'source', 'until'],
            'onboarding_completed',
        ]);

    expect($response->json('due_count'))->toBeInt()
        ->and($response->json('new_words_remaining'))->toBe(20)
        ->and($response->json('open_session_id'))->toBeInt()
        ->and($response->json('onboarding_completed'))->toBeTrue()
        ->and($response->json('entitlement'))->toBe(['plan' => 'free', 'source' => 'free', 'until' => null]);

    $bare = Learner::factory()->create();
    $this->withHeaders(contractHeaders($bare))
        ->getJson('/api/twa/today')
        ->assertOk()
        ->assertExactJsonStructure([
            'due_count', 'new_words_remaining', 'goal' => CONTRACT_GOAL, 'streak' => CONTRACT_STREAK,
            'active_course', 'open_session_id', 'entitlement' => ['plan', 'source', 'until'], 'onboarding_completed',
        ])
        ->assertJsonPath('active_course', null)
        ->assertJsonPath('open_session_id', null);
});

it('GET /me reports the Free entitlement and a paywall without contact while BILLING_SUPPORT_CONTACT is empty', function (): void {
    Config::set('billing.paywall.contact', '');
    $s = contractTraining();

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('entitlement', ['plan' => 'free', 'source' => 'free', 'until' => null, 'new_words_per_day' => 20, 'renewal' => null, 'lapsed' => null])
        ->assertJsonPath('paywall', ['mode' => 'coming_soon', 'contact' => null]);
});

it('GET /me reports Pro with its end (ISO 8601) and the configured contact', function (): void {
    Config::set('billing.paywall.contact', '@lexiflow_support');
    $s = contractTraining();
    $until = now()->addDays(10)->startOfSecond();
    Subscription::factory()->create([
        'subscriber_id' => $s['learner']->id,
        'provider' => 'promo',
        'current_period_ends_at' => $until,
    ]);

    $response = $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('entitlement.plan', 'pro')
        ->assertJsonPath('entitlement.source', 'subscription')
        ->assertJsonPath('entitlement.new_words_per_day', null)
        ->assertJsonPath('paywall', ['mode' => 'coming_soon', 'contact' => '@lexiflow_support']);

    expect(CarbonImmutable::parse((string) $response->json('entitlement.until'))->equalTo($until))->toBeTrue();
});

it('GET /me counts due graduated cards and abandoned (re)learning cards as due today', function (): void {
    $s = contractTraining(0);
    $words = Word::factory()->count(7)->for($s['lesson'])->create();
    $make = fn (Word $w, RepetitionState $state, int $reps, string $due) => WordRepetition::factory()->create([
        'learner_id' => $s['learner']->id,
        'word_id' => $w->id,
        'state' => $state->value,
        'repetitions' => $reps,
        'next_review_at' => match ($due) {
            'past' => now()->subHour(),
            'abandoned' => now()->subHours(13),
            default => now()->addDays(2),
        },
    ]);

    $make($words[0], RepetitionState::Review, 3, 'past');           // due review
    $make($words[1], RepetitionState::Review, 3, 'future');         // not yet due
    $make($words[2], RepetitionState::New, 0, 'past');              // just-shown new card
    $make($words[3], RepetitionState::Learning, 0, 'past');         // minute step elapsed an hour ago (session may still be on)
    $make($words[4], RepetitionState::Relearning, 1, 'past');       // lapsed, relearning, fresh
    $make($words[5], RepetitionState::Relearning, 0, 'abandoned');  // lapsed and left for > abandoned_after_hours
    $make($words[6], RepetitionState::New, 0, 'abandoned');         // shown long ago, never answered: not "to review"

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('stats.words_due_today', 2)
        ->assertJsonPath('stats.words_learned', 3);
});

// --- billing (docs/04 A.8) -------------------------------------------------

const CONTRACT_SUBSCRIPTION_SUMMARY = ['status', 'provider', 'plan', 'ends_at', 'auto_renew', 'can_cancel', 'can_resume'];
const CONTRACT_RENEWAL = ['auto', 'provider', 'ends_at', 'reminder_sent'];

it('GET /billing/catalog keeps its exact JSON shape', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $s = contractTraining();

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/billing/catalog')
        ->assertOk()
        ->assertExactJsonStructure([
            'items' => ['*' => ['plan', 'tier', 'period', 'prices' => ['XTR'], 'list_prices' => ['XTR']]],
            'providers',
            'offer',
            'subscription',
        ]);
});

it('GET /billing/catalog carries the open offer with its period and the discounted prices', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $s = contractTraining();
    Offer::factory()->create(['learner_id' => $s['learner']->id, 'discount_pct' => 30, 'period' => 'month']);

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/billing/catalog')
        ->assertOk()
        ->assertExactJsonStructure([
            'items' => ['*' => ['plan', 'tier', 'period', 'prices' => ['XTR'], 'list_prices' => ['XTR']]],
            'providers',
            'offer' => ['kind', 'discount_pct', 'period', 'expires_at'],
            'subscription',
        ])
        ->assertJsonPath('offer.period', 'month')
        ->assertJsonPath('items.0.prices.XTR', 105)
        ->assertJsonPath('items.0.list_prices.XTR', 150);
});

it('POST /billing/checkout and GET /billing/orders/{token} keep their exact JSON shape; paid orders carry the subscription summary', function (): void {
    $stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $s = contractTraining();

    $token = (string) $this->withHeaders(contractHeaders($s['learner']))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars'])
        ->assertCreated()
        ->assertExactJsonStructure(['order' => ['id', 'token', 'status', 'amount', 'currency', 'expires_at'], 'checkout' => ['url', 'kind']])
        ->json('order.token');

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson("/api/twa/billing/orders/{$token}")
        ->assertOk()
        ->assertExactJsonStructure(['status', 'paid_at', 'reason', 'replaced_by', 'subscription']);

    $order = app(CheckoutService::class)->findByToken($token);
    app(PaymentApplier::class)->applyCharge($stars->emitCharge($order));

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson("/api/twa/billing/orders/{$token}")
        ->assertOk()
        ->assertExactJsonStructure(['status', 'paid_at', 'reason', 'replaced_by', 'subscription' => CONTRACT_SUBSCRIPTION_SUMMARY]);
});

it('GET /me and the cancel / resume answers carry entitlement.renewal for a paying learner', function (): void {
    $stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $s = contractTraining();
    $order = app(CheckoutService::class)->create($s['learner'], Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    app(PaymentApplier::class)->applyCharge($stars->emitCharge($order));

    $me = [
        'learner' => ['id', 'first_name', 'username', 'locale', 'onboarding_completed'],
        'groups' => [['id', 'title']],
        'stats' => ['words_learned', 'words_due_today'],
        'entitlement' => ['plan', 'source', 'until', 'new_words_per_day', 'renewal' => CONTRACT_RENEWAL, 'lapsed'],
        'paywall' => ['mode', 'contact'],
        'settings' => [
            'interface_locale', 'timezone', 'reminder_time', 'daily_goal', 'goal', 'level', 'timezone_change_available_at', 'timezone_undo_available_until', 'active_course',
            'options' => ['locales' => ['*' => ['id', 'label']], 'timezones' => ['*' => ['id', 'label']], 'reminder_times', 'daily_goals'],
        ],
    ];

    $this->withHeaders(contractHeaders($s['learner']))->getJson('/api/twa/me')->assertOk()->assertExactJsonStructure($me);
    $this->withHeaders(contractHeaders($s['learner']))->postJson('/api/twa/billing/subscription/cancel')->assertOk()->assertExactJsonStructure($me);
    $this->withHeaders(contractHeaders($s['learner']))->postJson('/api/twa/billing/subscription/resume')->assertOk()->assertExactJsonStructure($me);
});

it('billing errors use the ApiErrorResource envelope', function (): void {
    $s = contractTraining();

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/billing/orders/nope')
        ->assertNotFound()
        ->assertExactJsonStructure(['error' => ['code', 'message']]);

    $this->withHeaders(contractHeaders($s['learner']))
        ->postJson('/api/twa/billing/subscription/cancel')
        ->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
});

// --- courses --------------------------------------------------------------

const CONTRACT_QUOTA = ['new_words_remaining'];

const CONTRACT_COURSE = ['slug', 'title', 'description', 'level', 'exam_tag', 'words_count', 'is_featured', 'is_active', 'progress' => CONTRACT_PROGRESS];

/** @return array{learner: Learner, course: Course} */
function contractCourse(): array
{
    $course = Course::factory()->published()->create(['slug' => 'contract-course', 'level' => 'A2', 'description' => ['ru' => 'Описание']]);
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    Word::factory()->count(2)->for(Lesson::factory()->for($stage)->create())->create();

    return ['learner' => Learner::factory()->create(), 'course' => $course];
}

it('GET /courses keeps its exact JSON shape', function (): void {
    $s = contractCourse();

    $response = $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/courses')
        ->assertOk()
        ->assertExactJsonStructure(['courses' => [CONTRACT_COURSE]]);

    expect($response->json('courses.0'))->toMatchArray([
        'slug' => 'contract-course',
        'title' => $s['course']->title['ru'],
        'description' => 'Описание',
        'level' => 'A2',
        'exam_tag' => null,
        'is_active' => false,
        'progress' => ['done' => 0, 'total' => 2],
    ]);
});

it('GET /courses/{slug} keeps its exact JSON shape', function (): void {
    $s = contractCourse();

    $this->withHeaders(contractHeaders($s['learner']))
        ->getJson('/api/twa/courses/contract-course')
        ->assertOk()
        ->assertExactJsonStructure([
            'slug', 'title', 'description', 'level', 'exam_tag', 'words_count', 'is_active',
            'progress' => CONTRACT_PROGRESS,
            'stages' => [[
                'number', 'title', 'progress' => CONTRACT_PROGRESS,
                'lessons' => [['id', 'number', 'title', 'progress' => CONTRACT_PROGRESS]],
            ]],
        ]);
});

it('POST /me/course keeps its exact JSON shape (a course row) and 404 error envelope', function (): void {
    $s = contractCourse();
    $headers = contractHeaders($s['learner']);

    $this->withHeaders($headers)
        ->postJson('/api/twa/me/course', ['slug' => 'contract-course'])
        ->assertOk()
        ->assertExactJsonStructure(CONTRACT_COURSE)
        ->assertJsonPath('is_active', true);

    $this->withHeaders($headers)
        ->postJson('/api/twa/me/course', ['slug' => 'missing'])
        ->assertNotFound()
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
});

// --- training -------------------------------------------------------------

it('POST /training/start keeps its exact JSON shape', function (): void {
    $s = contractTraining();

    $this->withHeaders(contractHeaders($s['learner']))
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/start")
        ->assertOk()
        ->assertExactJsonStructure([
            'session_id',
            'mode',
            'lesson' => ['stage', 'lesson', 'title'],
            'total_words',
            'card' => CONTRACT_CARD,
            'progress' => CONTRACT_PROGRESS,
            'done_reason',
            'quota' => CONTRACT_QUOTA,
        ])
        ->assertJsonPath('mode', 'group_lesson')
        ->assertJsonPath('card.card_kind', 'new')
        ->assertJsonPath('done_reason', null);
});

it('POST /training/sessions keeps its exact JSON shape, for a lesson and for a daily review', function (): void {
    $course = Course::factory()->published()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    Word::factory()->count(2)->for($lesson)->create();
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    $headers = contractHeaders($learner);

    $this->withHeaders($headers)
        ->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id, 'source' => 'picker'])
        ->assertOk()
        ->assertExactJsonStructure([
            'session_id',
            'mode',
            'lesson' => ['stage', 'lesson', 'title'],
            'total_words',
            'card' => CONTRACT_CARD,
            'progress' => CONTRACT_PROGRESS,
            'done_reason',
            'quota' => CONTRACT_QUOTA,
        ]);

    $this->withHeaders($headers)
        ->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])
        ->assertOk()
        ->assertExactJsonStructure([
            'session_id',
            'mode',
            'lesson',
            'total_words',
            'card' => CONTRACT_CARD,
            'progress' => CONTRACT_PROGRESS,
            'done_reason',
            'quota' => CONTRACT_QUOTA,
        ])
        ->assertJsonPath('lesson', null);

    $this->withHeaders($headers)
        ->postJson('/api/twa/training/sessions', ['mode' => 'lesson'])
        ->assertStatus(422)
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
});

it('POST /training/finish keeps its exact JSON shape', function (): void {
    $learner = Learner::factory()->create();
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create();

    $response = $this->withHeaders(contractHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/finish")
        ->assertOk()
        ->assertExactJsonStructure([
            'reviewed', 'correct', 'new_words',
            'goal' => CONTRACT_GOAL,
            'streak' => CONTRACT_STREAK,
        ]);

    expect($response->json('reviewed'))->toBeInt()
        ->and($response->json('correct'))->toBeInt()
        ->and($response->json('new_words'))->toBeInt()
        ->and($response->json('goal.target'))->toBeInt()
        ->and($response->json('goal.met'))->toBeBool()
        ->and($response->json('streak.at_risk'))->toBeBool();
});

it('GET /training/next keeps its exact JSON shape, with and without a card', function (): void {
    $s = contractTraining(1);
    $headers = contractHeaders($s['learner']);

    $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->assertExactJsonStructure(['card' => CONTRACT_CARD, 'progress' => CONTRACT_PROGRESS, 'done_reason', 'quota' => CONTRACT_QUOTA]);

    // Pretend the only card was already graduated and is not due: nothing to show.
    WordRepetition::query()->where('learner_id', $s['learner']->id)->update([
        'state' => RepetitionState::Review->value,
        'repetitions' => 1,
        'next_review_at' => now()->addDays(3),
    ]);

    $response = $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->assertOk()
        ->assertExactJsonStructure(['card', 'progress' => CONTRACT_PROGRESS, 'done_reason', 'quota' => CONTRACT_QUOTA]);

    expect($response->json('card'))->toBeNull()
        ->and($response->json('done_reason'))->toBe('lesson_complete');
});

it('POST /training/review keeps its exact JSON shape', function (): void {
    $s = contractTraining();
    $headers = contractHeaders($s['learner']);

    $card = $this->withHeaders($headers)
        ->getJson("/api/twa/training/sessions/{$s['session']->id}/next")
        ->json('card');

    $response = $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$s['session']->id}/review", [
            'word_id' => $card['word_id'],
            'quality' => 5,
            'time_spent_ms' => 1200,
        ])
        ->assertOk()
        ->assertExactJsonStructure([
            'next_review_at',
            'new_interval_days',
            'easiness_factor',
            'card' => CONTRACT_CARD,
            'progress' => CONTRACT_PROGRESS,
            'done_reason',
            'quota' => CONTRACT_QUOTA,
        ]);

    expect($response->json('new_interval_days'))->toBeInt()
        ->and($response->json('easiness_factor'))->toBeFloat()
        ->and($response->json('next_review_at'))->toBeString();
});

it('wraps every API error in the same envelope', function (): void {
    $s = contractTraining();
    $headers = contractHeaders($s['learner']);
    $openExam = contractExam();
    $closedExam = contractExam();
    $closedExam['session']->update(['status' => 'closed', 'closed_at' => now()]);

    $errors = [
        401 => $this->getJson('/api/twa/me'),
        404 => $this->withHeaders($headers)->postJson('/api/twa/training/sessions/999999/start'),
        410 => $this->withHeaders(contractHeaders($closedExam['learner']))
            ->postJson("/api/twa/exam/sessions/{$closedExam['session']->id}/join"),
        409 => $this->withHeaders(contractHeaders($openExam['learner']))
            ->getJson("/api/twa/exam/sessions/{$openExam['session']->id}/result"),
    ];

    foreach ($errors as $status => $response) {
        $response->assertStatus($status)->assertExactJsonStructure(['error' => ['code', 'message']]);
    }
});

it('ApiErrorResource builds the envelope without a data wrapper', function (): void {
    $response = ApiErrorResource::respond(418, 'teapot', 'Short and stout.');

    expect($response->getStatusCode())->toBe(418)
        ->and($response->getData(true))->toBe(['error' => ['code' => 'teapot', 'message' => 'Short and stout.']]);
});

// --- exam -----------------------------------------------------------------

it('POST /exam/join keeps its exact JSON shape and hides the answer key', function (): void {
    $s = contractExam();

    $response = $this->withHeaders(contractHeaders($s['learner']))
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/join")
        ->assertOk()
        ->assertExactJsonStructure([
            'session_id',
            'ends_at',
            'total_questions',
            'seconds_per_question',
            'question' => CONTRACT_EXAM_QUESTION,
        ]);

    foreach (CONTRACT_FORBIDDEN_EXAM_KEYS as $key) {
        expect($response->getContent())->not->toContain($key);
    }
    expect($response->json('total_questions'))->toBe(4)
        ->and($response->json('seconds_per_question'))->toBe(10);
});

it('GET /exam/question keeps its exact JSON shape and hides the answer key', function (): void {
    $s = contractExam();

    $response = $this->withHeaders(contractHeaders($s['learner']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=1")
        ->assertOk()
        ->assertExactJsonStructure(CONTRACT_EXAM_QUESTION);

    foreach (CONTRACT_FORBIDDEN_EXAM_KEYS as $key) {
        expect($response->getContent())->not->toContain($key);
    }
    expect($response->json('question_index'))->toBe(1)
        ->and($response->json('seconds_left'))->toBeInt();
});

it('POST /exam/answer keeps its exact JSON shape', function (): void {
    $s = contractExam();
    $headers = contractHeaders($s['learner']);

    $question = $this->withHeaders($headers)
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/question?index=0")
        ->json();

    $response = $this->withHeaders($headers)
        ->postJson("/api/twa/exam/sessions/{$s['session']->id}/answer", [
            'question_index' => 0,
            'word_id' => $question['word_id'],
            'selected_option_index' => 0,
            'time_spent_ms' => 500,
        ])
        ->assertOk()
        ->assertExactJsonStructure([
            'is_correct',
            'correct_option',
            'score_earned',
            'total_score',
            'has_next',
            'next_question',
        ]);

    expect($response->json('correct_option'))->toBeInt()
        ->and($response->json('has_next'))->toBeTrue()
        ->and($response->json('next_question'))->toBeNull();
});

it('GET /exam/result keeps its exact JSON shape', function (): void {
    $s = contractExam();
    $question = app(ExamQuestionPersonalizer::class)->questionAt($s['session'], $s['learner'], 0);

    ExamAnswer::query()->create([
        'exam_session_id' => $s['session']->id,
        'learner_id' => $s['learner']->id,
        'word_id' => $question['word_id'],
        'selected_translation' => $question['correct_translation'],
        'is_correct' => true,
        'score' => 18,
        'time_spent_ms' => 900,
        'answered_at' => now(),
    ]);
    $s['session']->update(['status' => 'closed', 'closed_at' => now()]);

    $response = $this->withHeaders(contractHeaders($s['learner']))
        ->getJson("/api/twa/exam/sessions/{$s['session']->id}/result")
        ->assertOk()
        ->assertExactJsonStructure([
            'learner_score',
            'rank',
            'total_participants',
            'leaderboard' => ['*' => ['rank', 'name', 'score']],
        ]);

    expect($response->json('learner_score'))->toBe(18)
        ->and($response->json('rank'))->toBe(1)
        ->and($response->json('leaderboard'))->toBeArray()->toHaveCount(1);
});

it('ExamQuestionResource exposes only the allow-listed fields of the personalizer view', function (): void {
    $s = contractExam();
    $question = app(ExamQuestionPersonalizer::class)->questionAt($s['session'], $s['learner'], 0);

    // The raw view really does carry the answer key ...
    expect($question)->toHaveKeys(CONTRACT_FORBIDDEN_EXAM_KEYS);

    $payload = (new ExamQuestionResource(new ServedExamQuestion($question, 7)))->toArray(Request::create('/'));

    // ... and the resource drops it.
    expect(array_keys($payload))->toBe(CONTRACT_EXAM_QUESTION)
        ->and($payload['seconds_left'])->toBe(7);
});

it('GET /assignments keeps its exact JSON shape (docs/04 A.9)', function (): void {
    $lesson = Lesson::factory()->for(Stage::factory()->for(Course::factory()->published())->create(['number' => 2]))->create(['number' => 3]);
    $org = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id, 'course_id' => $lesson->stage?->course_id]);
    $learner = Learner::factory()->memberOf($group)->create();
    Assignment::factory()->create(['organization_id' => $org->id, 'telegram_group_id' => $group->id, 'lesson_id' => $lesson->id]);

    $response = $this->withHeaders(contractHeaders($learner))
        ->getJson('/api/twa/assignments')
        ->assertOk()
        ->assertExactJsonStructure([
            'assignments' => [[
                'id', 'title', 'group', 'lesson_id', 'stage', 'lesson', 'due_at', 'overdue',
                'progress_pct', 'completed', 'completed_at', 'late',
            ]],
        ]);

    expect($response->json('assignments.0.lesson_id'))->toBe($lesson->id)
        ->and($response->json('assignments.0.stage'))->toBe(2)
        ->and($response->json('assignments.0.lesson'))->toBe(3)
        ->and($response->json('assignments.0.overdue'))->toBeFalse()
        ->and($response->json('assignments.0.progress_pct'))->toBe(0)
        ->and($response->json('assignments.0.completed'))->toBeFalse()
        ->and($response->json('assignments.0.completed_at'))->toBeNull()
        ->and($response->json('assignments.0.late'))->toBeFalse();

    $bare = Learner::factory()->create();
    $this->withHeaders(contractHeaders($bare))->getJson('/api/twa/assignments')->assertOk()->assertExactJson(['assignments' => []]);
});

it('GET /referrals keeps its exact JSON shape (docs/04 A.12)', function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot']);
    $learner = Learner::factory()->create();
    $friend = Learner::factory()->create(['first_name' => 'Bek']);
    Referral::factory()->create(['referrer_learner_id' => $learner->id, 'referee_learner_id' => $friend->id]);

    $response = $this->withHeaders(contractHeaders($learner))
        ->getJson('/api/twa/referrals')
        ->assertOk()
        ->assertExactJsonStructure([
            'code', 'link', 'reward_days',
            'activation' => ['days', 'reviews_per_day', 'window_days'],
            'counts' => ['invited', 'activated', 'rewarded'],
            'referrals' => [['first_name', 'status', 'linked_at']],
        ]);

    expect($response->json('code'))->toMatch('/^[A-Z2-9]{8}$/')
        ->and($response->json('link'))->toBe('https://t.me/LexiFlowBot?start=ref_'.$response->json('code'))
        ->and($response->json('referrals.0.first_name'))->toBe('Bek')
        ->and($response->json('referrals.0.status'))->toBe('pending');
});
