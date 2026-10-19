<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Course;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $from */
function onbSend(int $tgId, string $text, array $from = []): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru', ...$from],
        'text' => $text,
    ]]);
}

function onbTap(int $tgId, string $data, int $messageId = 100): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$messageId.'-'.md5($data),
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

function onbLearner(int $tgId): Learner
{
    return Learner::query()->where('telegram_user_id', $tgId)->firstOrFail();
}

/** Runs the five steps from `/start` to `done`. */
function onbComplete(int $tgId, string $locale = 'uz', string $goal = 'ielts', string $level = 'B1', string $zone = 'Asia/Almaty', string $reminder = '2100'): void
{
    onbTap($tgId, "onb1:locale:{$locale}", 1);
    onbTap($tgId, "onb1:goal:{$goal}", 2);
    onbTap($tgId, "onb1:level:{$level}", 3);
    onbTap($tgId, "onb1:timezone:{$zone}", 4);
    onbTap($tgId, "onb1:reminder:{$reminder}", 5);
}

function onbCourse(string $slug, ?string $tag, ?string $level = null, bool $featured = false, string $visibility = 'public'): Course
{
    return Course::factory()->published()->create([
        'slug' => $slug,
        'exam_tag' => $tag,
        'level' => $level,
        'is_featured' => $featured,
        'visibility' => $visibility,
        'title' => ['ru' => 'Курс '.$slug, 'uz' => 'Kurs '.$slug],
    ]);
}

/** @return list<string> */
function onbEvents(): array
{
    return ProductEvent::query()->orderBy('id')->pluck('name')->all();
}

// --- the happy path --------------------------------------------------------

it('walks a new learner through the five steps with five callbacks', function (): void {
    $course = onbCourse('ielts-academic', 'ielts', 'B1');
    onbCourse('general-a1', 'general', 'A1');

    onbSend(7001, '/start', ['language_code' => 'uz']);

    $learner = onbLearner(7001);
    expect($learner->onboarding_state)->toBe('locale')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Interfeys tilini tanlang')
        ->and($this->tg->lastCallbackData())->toBe(['onb1:locale:ru', 'onb1:locale:uz']);

    onbTap(7001, 'onb1:locale:uz', 1);
    expect(onbLearner(7001)->onboarding_state)->toBe('goal')
        ->and(end($this->tg->sent)['text'])->toBe("Ingliz tilini nima uchun o'rganmoqchisiz?")
        ->and($this->tg->lastCallbackData())->toBe(['onb1:goal:general', 'onb1:goal:ielts', 'onb1:goal:sat', 'onb1:goal:school']);

    onbTap(7001, 'onb1:goal:ielts', 2);
    expect($this->tg->lastCallbackData())->toBe(['onb1:level:A1', 'onb1:level:A2', 'onb1:level:B1', 'onb1:level:B2', 'onb1:level:unknown']);

    onbTap(7001, 'onb1:level:B1', 3);
    expect($this->tg->lastCallbackData())->toContain('onb1:timezone:Asia/Tashkent', 'onb1:timezone:Asia/Almaty');

    onbTap(7001, 'onb1:timezone:Asia/Almaty', 4);
    expect($this->tg->lastCallbackData())->toBe(['onb1:reminder:0800', 'onb1:reminder:1300', 'onb1:reminder:1900', 'onb1:reminder:2100', 'onb1:reminder:off']);

    onbTap(7001, 'onb1:reminder:2100', 5);

    $learner = onbLearner(7001);
    expect($learner->onboarding_state)->toBe('done')
        ->and($learner->onboarding_completed_at)->not->toBeNull()
        ->and($learner->interface_locale)->toBe('uz')
        ->and($learner->goal)->toBe('ielts')
        ->and($learner->level)->toBe('B1')
        ->and($learner->timezone)->toBe('Asia/Almaty')
        ->and(substr((string) DB::table('learners')->where('id', $learner->id)->value('reminder_time'), 0, 5))->toBe('21:00')
        ->and($learner->daily_goal)->toBe(20)
        ->and($learner->active_course_id)->toBe($course->id);

    // The last message: the TWA button, in the chosen language, naming the course.
    $last = end($this->tg->sent);
    expect($last['button'])->toBe("🎯 Birinchi mashg'ulotni boshlash")
        ->and($last['url'])->toBe('https://twa.test/twa/?from=onboarding')
        ->and($last['text'])->toContain('Kurs ielts-academic');

    // Every answered message lost its buttons, every query was answered.
    expect($this->tg->edits)->toHaveCount(5)
        ->and(array_column($this->tg->edits, 'markup'))->each->toBeNull()
        ->and(array_column($this->tg->edits, 'message_id'))->toBe([1, 2, 3, 4, 5])
        ->and($this->tg->answered)->toHaveCount(5);

    expect(onbEvents())->toBe([
        'onboarding.started',
        'onboarding.step_completed', 'onboarding.step_completed', 'onboarding.step_completed',
        'onboarding.step_completed', 'onboarding.step_completed',
        'onboarding.completed',
        'course.selected',
    ]);
    $completed = ProductEvent::query()->where('name', 'onboarding.completed')->firstOrFail();
    expect(ksorted($completed->props))->toBe(['goal' => 'ielts', 'level' => 'B1', 'locale' => 'uz'])
        ->and(ProductEvent::query()->where('name', 'onboarding.started')->firstOrFail()->props)->toBe(['source_kind' => 'direct'])
        ->and(ksorted(ProductEvent::query()->where('name', 'course.selected')->firstOrFail()->props))->toBe(['course_id' => $course->id, 'via' => 'onboarding']);
});

it('stores "I do not know" as A1, switches reminders off and falls back to the default course', function (): void {
    onbSend(7002, '/start');
    onbComplete(7002, 'ru', 'general', 'unknown', 'Asia/Tashkent', 'off');

    $learner = onbLearner(7002);
    expect($learner->onboarding_state)->toBe('done')
        ->and($learner->level)->toBe('A1')
        ->and($learner->reminder_time)->toBeNull()
        ->and($learner->next_reminder_at)->toBeNull()
        ->and($learner->active_course_id)->toBe(app(CourseCatalogService::class)->defaultCourse()->id);
});

it('keeps a course the learner already picked instead of recommending one', function (): void {
    $picked = onbCourse('picked', 'school');
    onbCourse('ielts-academic', 'ielts');

    onbSend(7003, '/start');
    onbLearner(7003)->update(['active_course_id' => $picked->id]);
    onbComplete(7003, 'ru', 'ielts');

    expect(onbLearner(7003)->active_course_id)->toBe($picked->id)
        ->and(onbEvents())->not->toContain('course.selected');
});

it('keeps every callback_data within the Telegram limit and the whitelists', function (): void {
    $service = app(OnboardingService::class);

    foreach (OnboardingService::STEPS as $step) {
        foreach ($service->options($step) as $option) {
            expect(strlen("onb1:{$step}:{$option->value}"))->toBeLessThanOrEqual(64);
        }
    }
});

it('recommends by goal, then level, then featured, and ignores private and draft courses', function (): void {
    $catalog = app(CourseCatalogService::class);
    $plain = onbCourse('sat-plain', 'sat');
    $featured = onbCourse('sat-featured', 'sat', featured: true);
    $levelled = onbCourse('sat-b2', 'sat', 'B2');
    onbCourse('sat-private', 'sat', 'B1', true, 'private');
    Course::factory()->create(['slug' => 'sat-draft', 'exam_tag' => 'sat', 'level' => 'B1']);

    expect($catalog->recommend('sat', 'B2')?->id)->toBe($levelled->id)
        ->and($catalog->recommend('sat', 'A1')?->id)->toBe($featured->id)
        ->and($catalog->recommend('sat', null)?->id)->toBe($featured->id)
        ->and($catalog->recommend('ielts', 'B2'))->toBeNull()
        ->and($catalog->recommend(null, 'B2'))->toBeNull()
        ->and($plain->id)->not->toBe($featured->id);
});

// --- idempotency and untrusted callback_data ---------------------------------

it('ignores a repeated tap on an old button and a tap on a step that is not current', function (): void {
    onbSend(7010, '/start');
    onbTap(7010, 'onb1:locale:uz', 1);
    $sentBefore = count($this->tg->sent);
    $eventsBefore = ProductEvent::query()->count();

    // Same button again (double tap / old message): answered, nothing changes, nothing is sent.
    onbTap(7010, 'onb1:locale:ru', 1);
    // A button of a step that has not been asked yet.
    onbTap(7010, 'onb1:reminder:off', 9);

    $learner = onbLearner(7010);
    expect($learner->onboarding_state)->toBe('goal')
        ->and($learner->interface_locale)->toBe('uz')
        ->and($learner->reminder_time)->not->toBeNull()
        ->and($this->tg->sent)->toHaveCount($sentBefore)
        ->and($this->tg->edits)->toHaveCount(1)
        ->and($this->tg->answered)->toHaveCount(3)
        ->and(ProductEvent::query()->count())->toBe($eventsBefore);
});

it('does not reopen a finished onboarding when an old button is tapped', function (): void {
    onbCourse('general-a1', 'general');
    onbSend(7011, '/start');
    onbComplete(7011);
    $before = onbLearner(7011)->only(['goal', 'level', 'timezone', 'interface_locale', 'reminder_time', 'active_course_id']);
    $sentBefore = count($this->tg->sent);
    $eventsBefore = ProductEvent::query()->count();

    onbTap(7011, 'onb1:reminder:off', 5);
    onbTap(7011, 'onb1:goal:school', 2);

    expect(onbLearner(7011)->only(['goal', 'level', 'timezone', 'interface_locale', 'reminder_time', 'active_course_id']))->toBe($before)
        ->and(onbLearner(7011)->onboarding_state)->toBe('done')
        ->and($this->tg->sent)->toHaveCount($sentBefore)
        ->and(ProductEvent::query()->count())->toBe($eventsBefore);
});

it('rejects values outside the whitelists and malformed callback_data without changing anything', function (string $step, string $data): void {
    // Walk to the step under test, then send the tampered data for it.
    onbSend(7012, '/start');
    $answers = ['locale' => 'onb1:locale:ru', 'goal' => 'onb1:goal:general', 'level' => 'onb1:level:A1', 'timezone' => 'onb1:timezone:Asia/Tashkent'];
    foreach ($answers as $name => $answer) {
        if ($name === $step) {
            break;
        }
        onbTap(7012, $answer);
    }
    $before = onbLearner(7012)->only(['onboarding_state', 'goal', 'level', 'timezone', 'interface_locale', 'reminder_time', 'meta']);
    $sentBefore = count($this->tg->sent);

    onbTap(7012, $data);

    expect(onbLearner(7012)->only(['onboarding_state', 'goal', 'level', 'timezone', 'interface_locale', 'reminder_time', 'meta']))->toBe($before)
        ->and($this->tg->sent)->toHaveCount($sentBefore);
})->with([
    'unknown locale' => ['locale', 'onb1:locale:de'],
    'locale with a path' => ['locale', 'onb1:locale:../etc'],
    'unknown goal' => ['goal', 'onb1:goal:hacker'],
    'goal in upper case' => ['goal', 'onb1:goal:IELTS'],
    'level C2' => ['level', 'onb1:level:C2'],
    'level lower case' => ['level', 'onb1:level:b1'],
    'valid IANA zone not offered' => ['timezone', 'onb1:timezone:Europe/Berlin'],
    'not a zone' => ['timezone', 'onb1:timezone:Mars/Base'],
    'zone with a dot-dot' => ['timezone', 'onb1:timezone:Asia/../Tashkent'],
    'reminder not offered' => ['reminder', 'onb1:reminder:0300'],
    'extra segment' => ['goal', 'onb1:goal:ielts:extra'],
    'no value' => ['goal', 'onb1:goal'],
    'empty value' => ['goal', 'onb1:goal:'],
    'unknown step' => ['goal', 'onb1:admin:yes'],
    'too long value' => ['goal', 'onb1:goal:'.str_repeat('a', 60)],
    'other version' => ['goal', 'onb2:goal:ielts'],
]);

it('treats the reminder step value as a whitelist key, not a free time', function (): void {
    onbSend(7013, '/start');
    onbTap(7013, 'onb1:locale:ru');
    onbTap(7013, 'onb1:goal:general');
    onbTap(7013, 'onb1:level:A1');
    onbTap(7013, 'onb1:timezone:Asia/Tashkent');

    onbTap(7013, 'onb1:reminder:25:00');
    onbTap(7013, 'onb1:reminder:1900;DROP');

    expect(onbLearner(7013)->onboarding_state)->toBe('reminder');
});

it('survives Telegram refusing to edit the answered message', function (): void {
    $this->tg->failEdits = true;

    onbSend(7014, '/start');
    onbTap(7014, 'onb1:locale:uz');

    expect(onbLearner(7014)->onboarding_state)->toBe('goal')
        ->and($this->tg->sent)->toHaveCount(2);
});

it('applies only the current step in the service, under any order of calls', function (): void {
    $service = app(OnboardingService::class);
    $learner = Learner::factory()->create(['onboarding_state' => 'goal']);

    expect(fn () => $service->apply($learner, 'level', 'A1'))->toThrow(OnboardingException::class);
    expect(fn () => $service->apply($learner, 'goal', 'nope'))->toThrow(OnboardingException::class);
    expect(fn () => $service->apply($learner, 'bogus', 'x'))->toThrow(OnboardingException::class);

    $next = $service->apply($learner, 'goal', 'sat');
    expect($next->state)->toBe('level')
        ->and($learner->onboarding_state)->toBe('level')
        ->and($learner->goal)->toBe('sat');

    // The same answer once more is stale now.
    expect(fn () => $service->apply($learner, 'goal', 'sat'))->toThrow(OnboardingException::class);
});

it('clears next_reminder_at when the time zone or the reminder time changes', function (): void {
    $service = app(OnboardingService::class);
    $learner = Learner::factory()->onboarded()->create(['next_reminder_at' => now()->addHour()]);

    $service->applySetting($learner, 'goal', 'sat');
    expect($learner->fresh()->next_reminder_at)->not->toBeNull();

    $service->applySetting($learner, 'timezone', 'Asia/Bishkek');
    expect($learner->fresh()->next_reminder_at)->toBeNull();

    $learner->forceFill(['next_reminder_at' => now()->addHour()])->save();
    $service->applySetting($learner, 'reminder', '0800');
    expect($learner->fresh()->next_reminder_at)->toBeNull();
});

it('skips the onboarding to done without choosing a course', function (): void {
    $learner = Learner::factory()->create(['onboarding_state' => 'level']);

    app(OnboardingService::class)->skipToDone($learner);

    expect($learner->fresh()->onboarding_state)->toBe('done')
        ->and($learner->fresh()->onboarding_completed_at)->not->toBeNull()
        ->and($learner->fresh()->active_course_id)->toBeNull();
});

// --- /start payloads -------------------------------------------------------

it('records a ref_ payload with the referrer and keeps the onboarding ordinary', function (): void {
    $referrer = Learner::factory()->create();

    onbSend(7020, '/start ref_'.$referrer->id);

    $learner = onbLearner(7020);
    expect($learner->acquisition_source)->toBe('ref')
        ->and($learner->start_payload)->toBe('ref_'.$referrer->id)
        ->and($learner->referrer_learner_id)->toBe($referrer->id)
        ->and($learner->onboarding_state)->toBe('locale')
        ->and(ProductEvent::query()->where('name', 'onboarding.started')->firstOrFail()->props)->toBe(['source_kind' => 'ref']);
});

it('records src_ and org_ payloads', function (): void {
    onbSend(7021, '/start src_blogger_1');
    onbSend(7022, '/start org_Center-7');

    expect(onbLearner(7021)->acquisition_source)->toBe('src:blogger_1')
        ->and(onbLearner(7021)->start_payload)->toBe('src_blogger_1')
        ->and(onbLearner(7022)->acquisition_source)->toBe('org')
        ->and(onbLearner(7022)->start_payload)->toBe('org_Center-7');
});

it('records a c_ payload and uses the course when the onboarding ends', function (): void {
    $linked = onbCourse('english-a1', 'general', 'A1', visibility: 'unlisted');
    onbCourse('ielts-academic', 'ielts');

    onbSend(7023, '/start@LexiFlowBot c_english-a1');
    expect(onbLearner(7023)->acquisition_source)->toBe('course:english-a1');

    onbComplete(7023, 'ru', 'ielts');

    expect(onbLearner(7023)->active_course_id)->toBe($linked->id)
        ->and(onbLearner(7023)->meta)->not->toHaveKey('pending_course')
        ->and(ProductEvent::query()->where('name', 'course.selected')->firstOrFail()->props)->toBe(['course_id' => $linked->id, 'via' => 'deeplink']);
});

it('falls back to the recommendation when the linked course is not available', function (string $state): void {
    $ielts = onbCourse('ielts-academic', 'ielts');
    match ($state) {
        'draft' => Course::factory()->create(['slug' => 'linked']),
        'private' => onbCourse('linked', 'general', visibility: 'private'),
        'missing' => null,
    };

    onbSend(7024, '/start c_linked');
    onbComplete(7024, 'ru', 'ielts');

    expect(onbLearner(7024)->active_course_id)->toBe($ielts->id);
})->with(['draft', 'private', 'missing']);

it('records nothing for an invalid payload and runs the ordinary onboarding', function (string $payload): void {
    onbSend(7025, '/start '.$payload);

    $learner = onbLearner(7025);
    expect($learner->acquisition_source)->toBeNull()
        ->and($learner->start_payload)->toBeNull()
        ->and($learner->referrer_learner_id)->toBeNull()
        ->and($learner->onboarding_state)->toBe('locale')
        ->and($this->tg->sent)->toHaveCount(1);
})->with([
    'unknown prefix' => ['promo_xyz'],
    'ref with letters' => ['ref_abc'],
    'ref with zero' => ['ref_0'],
    'ref too long' => ['ref_1234567890123456'],
    'src in upper case' => ['src_Blogger'],
    'src too long' => ['src_'.str_repeat('a', 33)],
    'course in upper case' => ['c_English'],
    'course too long' => ['c_'.str_repeat('a', 63)],
    'whole payload too long' => ['src_'.str_repeat('a', 61)],
    'foreign characters' => ['src_a.b'],
    'prefix only' => ['ref_'],
    'html' => ['<b>'],
]);

it('ignores a ref_ payload pointing at an unknown or deleted learner', function (): void {
    $deleted = Learner::factory()->create();
    $deleted->delete();

    onbSend(7026, '/start ref_99999999');
    onbSend(7027, '/start ref_'.$deleted->id);

    expect(onbLearner(7026)->acquisition_source)->toBeNull()
        ->and(onbLearner(7027)->acquisition_source)->toBeNull()
        ->and(onbLearner(7027)->referrer_learner_id)->toBeNull();
});

it('ignores self-referral', function (): void {
    $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 7028, 'first_name' => 'Self']);

    onbSend(7028, '/start ref_'.$learner->id);

    $learner->refresh();
    expect($learner->acquisition_source)->toBeNull()
        ->and($learner->referrer_learner_id)->toBeNull()
        ->and($learner->start_payload)->toBeNull();
});

it('attributes first touch to a learner created earlier without a source, and never overwrites it', function (): void {
    $referrer = Learner::factory()->create();
    // Created by /auth in the TWA: no source.
    app(LearnerRegistrar::class)->fromTelegramUser(['id' => 7029, 'first_name' => 'Early']);

    onbSend(7029, '/start src_first');
    expect(onbLearner(7029)->acquisition_source)->toBe('src:first')
        ->and(onbLearner(7029)->start_payload)->toBe('src_first');

    // A later link does not overwrite it, and neither does it set a referrer.
    onbSend(7029, '/start ref_'.$referrer->id);
    $learner = onbLearner(7029);
    expect($learner->acquisition_source)->toBe('src:first')
        ->and($learner->start_payload)->toBe('src_first')
        ->and($learner->referrer_learner_id)->toBeNull();
});

it('does not overwrite the source of a learner who came from a group', function (): void {
    $referrer = Learner::factory()->create();
    app(LearnerRegistrar::class)->observeTelegramUser(['id' => 7030, 'first_name' => 'Grp'], 'group');

    onbSend(7030, '/start ref_'.$referrer->id);

    $learner = onbLearner(7030);
    expect($learner->acquisition_source)->toBe('group')
        ->and($learner->start_payload)->toBeNull()
        ->and($learner->referrer_learner_id)->toBeNull()
        // ...but the onboarding itself starts as usual.
        ->and($learner->onboarding_state)->toBe('locale');
});

it('applies a c_ link to a learner who already has a source', function (): void {
    $linked = onbCourse('english-a1', 'general');
    app(LearnerRegistrar::class)->observeTelegramUser(['id' => 7031, 'first_name' => 'Grp'], 'group');

    onbSend(7031, '/start c_english-a1');
    onbComplete(7031, 'ru', 'sat');

    expect(onbLearner(7031)->acquisition_source)->toBe('group')
        ->and(onbLearner(7031)->active_course_id)->toBe($linked->id);
});

it('attributes first touch for a done learner too', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 7032]);

    onbSend(7032, '/start src_late');

    expect($learner->fresh()->acquisition_source)->toBe('src:late');
});

// --- teachers, blocked learners, done learners --------------------------------

it('does not put a linked teacher into the onboarding', function (): void {
    User::factory()->create(['telegram_user_id' => 7040, 'name' => 'Aziz']);

    onbSend(7040, '/start ref_1');
    onbSend(7040, 'hello there');
    onbSend(7040, '/today');
    onbSend(7040, '/settings');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('Привет, Aziz!')
        ->and(Learner::query()->where('telegram_user_id', 7040)->exists())->toBeFalse()
        ->and(ProductEvent::query()->count())->toBe(0);
});

it('shows the Today screen to a learner whose onboarding is done', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 7041, 'interface_locale' => 'ru']);
    WordRepetition::factory()->due()->count(3)->create(['learner_id' => $learner->id, 'word_id' => Word::factory()]);

    onbSend(7041, '/start');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Сегодня к повторению: 3 слова.')
        ->and($this->tg->sent[0]['button'])->toBe('Открыть LexiFlow')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=today')
        ->and($learner->fresh()->onboarding_state)->toBe('done')
        ->and(ProductEvent::query()->count())->toBe(0);
});

it('answers /today for a learner, in their language, also when nothing is due', function (): void {
    Learner::factory()->onboarded()->create(['telegram_user_id' => 7042, 'interface_locale' => 'uz']);
    $busy = Learner::factory()->onboarded()->create(['telegram_user_id' => 7043, 'interface_locale' => 'uz']);
    WordRepetition::factory()->due()->count(5)->create(['learner_id' => $busy->id, 'word_id' => Word::factory()]);

    onbSend(7042, '/today');
    onbSend(7043, '/today@LexiFlowBot');

    expect($this->tg->sent[0]['text'])->toBe("Bugun takrorlanadigan so'z yo'q. Yangi so'zlarni o'rganish uchun LexiFlow'ni oching.")
        ->and($this->tg->sent[1]['text'])->toBe("Bugun takrorlash uchun: 5 ta so'z.")
        ->and($this->tg->sent[1]['button'])->toBe("LexiFlow'ni ochish");
});

it('stays silent for /today in a group', function (): void {
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => -100500, 'type' => 'supergroup'],
        'from' => ['id' => 7044],
        'text' => '/today',
    ]]);

    expect($this->tg->sent)->toBe([])
        ->and(Learner::query()->where('telegram_user_id', 7044)->exists())->toBeFalse();
});

it('tells a blocked learner that access is restricted and starts nothing', function (): void {
    Learner::factory()->create(['telegram_user_id' => 7045, 'blocked_at' => now()]);

    onbSend(7045, '/start');
    onbSend(7045, 'hi');
    onbTap(7045, 'onb1:locale:uz');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Доступ к аккаунту ограничен. Обратитесь в поддержку.')
        ->and(onbLearner(7045)->onboarding_state)->toBe('new');
});

it('revives a soft-deleted learner on /start and clears dm_blocked_at on any incoming message', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7046, 'dm_blocked_at' => now()->subDay()]);
    $learner->delete();

    onbSend(7046, '/start');
    expect($learner->fresh()->trashed())->toBeFalse()
        ->and($learner->fresh()->dm_blocked_at)->toBeNull();

    $learner->forceFill(['dm_blocked_at' => now()])->save();
    onbSend(7046, 'any text');
    expect($learner->fresh()->dm_blocked_at)->toBeNull();

    $learner->forceFill(['dm_blocked_at' => now()])->save();
    onbTap(7046, 'onb1:locale:uz');
    expect($learner->fresh()->dm_blocked_at)->toBeNull();
});

// --- the DM catch-all ----------------------------------------------------------

it('repeats the current step for any message while the onboarding is not finished', function (): void {
    onbSend(7050, '/start');
    onbTap(7050, 'onb1:locale:ru');
    onbTap(7050, 'onb1:goal:sat');

    onbSend(7050, 'what is this?');

    $last = end($this->tg->sent);
    expect($last['text'])->toContain('Какой у вас уровень?')
        ->and($this->tg->lastCallbackData())->toContain('onb1:level:B2')
        ->and(onbLearner(7050)->onboarding_state)->toBe('level')
        // Repeating a step is not a new start.
        ->and(ProductEvent::query()->where('name', 'onboarding.started')->count())->toBe(1);
});

it('starts the onboarding when a new learner writes anything first', function (): void {
    onbSend(7051, 'hello');

    expect(onbLearner(7051)->onboarding_state)->toBe('locale')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and(ProductEvent::query()->where('name', 'onboarding.started')->firstOrFail()->props)->toBe(['source_kind' => 'direct']);
});

it('answers a finished learner with a short menu', function (): void {
    Learner::factory()->onboarded()->create(['telegram_user_id' => 7052, 'interface_locale' => 'uz']);

    onbSend(7052, 'salom');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('/today')->toContain('/settings')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=menu');
});

it('keeps /help, /promo and group commands away from the catch-all', function (): void {
    onbSend(7053, '/help');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('LexiFlow')
        ->and(Learner::query()->where('telegram_user_id', 7053)->exists())->toBeFalse();
});

// --- /settings -----------------------------------------------------------------

it('lets a finished learner change one value at a time through /settings', function (): void {
    $learner = Learner::factory()->onboarded()->create([
        'telegram_user_id' => 7060, 'interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent', 'goal' => 'general', 'level' => 'A1',
    ]);

    onbSend(7060, '/settings');
    expect($this->tg->lastCallbackData())->toBe(['set1:locale', 'set1:goal', 'set1:level', 'set1:timezone', 'set1:reminder']);

    onbTap(7060, 'set1:timezone', 10);
    expect($this->tg->sent[1]['text'])->toContain('часовом поясе')
        ->and($this->tg->lastCallbackData())->toContain('set1:timezone:Asia/Bishkek');

    onbTap(7060, 'set1:timezone:Asia/Bishkek', 11);
    expect($learner->fresh()->timezone)->toBe('Asia/Bishkek')
        ->and(end($this->tg->sent)['text'])->toBe('Сохранено.')
        ->and($this->tg->edits)->toHaveCount(1);

    // The confirmation follows the new language.
    onbTap(7060, 'set1:locale:uz', 12);
    expect($learner->fresh()->interface_locale)->toBe('uz')
        ->and(end($this->tg->sent)['text'])->toBe('Saqlandi.')
        ->and($learner->fresh()->onboarding_state)->toBe('done');
});

it('validates /settings values against the same whitelists', function (string $data): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 7061]);
    $before = $learner->only(['goal', 'level', 'timezone', 'interface_locale', 'reminder_time']);

    onbTap(7061, $data);

    expect($learner->fresh()->only(['goal', 'level', 'timezone', 'interface_locale', 'reminder_time']))->toBe($before)
        ->and($this->tg->sent)->toBe([]);
})->with([
    'zone not offered' => ['set1:timezone:Europe/Berlin'],
    'unknown goal' => ['set1:goal:hacker'],
    'unknown step' => ['set1:role:admin'],
    'level C2' => ['set1:level:C2'],
]);

it('sends a learner who has not finished the onboarding back to it on /settings and ignores set1 buttons', function (): void {
    onbSend(7062, '/start');

    onbSend(7062, '/settings');
    expect(end($this->tg->sent)['text'])->toBe('Выберите язык интерфейса');

    $sentBefore = count($this->tg->sent);
    onbTap(7062, 'set1:locale:uz');

    expect(onbLearner(7062)->interface_locale)->toBe('ru')
        ->and($this->tg->sent)->toHaveCount($sentBefore);
});

// --- events --------------------------------------------------------------------

it('names the group as the source of the onboarding when no payload is given', function (): void {
    app(LearnerRegistrar::class)->observeTelegramUser(['id' => 7070, 'first_name' => 'G'], 'group');

    onbSend(7070, '/start');

    expect(ProductEvent::query()->where('name', ProductEventName::OnboardingStarted->value)->firstOrFail()->props)->toBe(['source_kind' => 'group']);
});

// --- StartPayload used through the service ---------------------------------------

it('lets a fresh c_ link replace a pending one before the onboarding ends', function (): void {
    $first = onbCourse('first', 'general');
    $second = onbCourse('second', 'general');
    $learner = Learner::factory()->create(['onboarding_state' => 'new']);
    $service = app(OnboardingService::class);

    $service->start($learner, StartPayload::parse('c_first'));
    $service->start($learner, StartPayload::parse('c_second'));

    expect($learner->fresh()->meta['pending_course'])->toBe('second')
        ->and($first->id)->not->toBe($second->id);
});
