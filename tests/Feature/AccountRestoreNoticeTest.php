<?php

declare(strict_types=1);

use App\Domain\Learners\Services\AccountRestoreNotice;
use App\Domain\Learners\Services\LearnerDeletionService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;

/*
 * R11b: "the account is restored" once, in the bot (/start) or in the Mini App (/me), whichever is first.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function restoreHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** A learner who deleted the account by themselves; the token is issued before, as the Mini App holds one. */
function deletedLearner(int $tgId, string $locale = 'ru', array $meta = []): Learner
{
    $learner = Learner::factory()->create(['telegram_user_id' => $tgId, 'interface_locale' => $locale, 'onboarding_state' => 'done', 'meta' => $meta]);
    app(LearnerDeletionService::class)->delete($learner);

    return $learner;
}

function restoreStart(int $tgId, string $lang = 'ru'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => $lang],
        'text' => '/start',
    ]]);
}

function comeBack(Learner $learner): Learner
{
    return app(LearnerRegistrar::class)->fromTelegramUser(['id' => $learner->telegram_user_id, 'first_name' => 'Ali']);
}

function restoredNotices(): int
{
    return AuditLog::query()->where('action', 'learner.restored')->count();
}

it('marks the revived learner, keeps the other meta keys and writes one audit record without PII', function (): void {
    $learner = deletedLearner(881_001, meta: ['locale_chosen' => true]);

    $back = comeBack($learner);

    $fresh = Learner::query()->findOrFail($learner->id);
    $audit = AuditLog::query()->where('action', 'learner.restored')->sole();
    expect($back->id)->toBe($learner->id)
        ->and($fresh->meta)->toHaveKey('restored_notice_at')->toHaveKey('locale_chosen')
        ->and($fresh->meta['locale_chosen'])->toBeTrue()
        ->and(app(AccountRestoreNotice::class)->isPending($back))->toBeTrue()
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->user_id)->toBeNull()
        ->and($audit->meta)->toBe(['by' => 'self']);
});

it('leaves no marker for a new learner, for a passive event and for a row purged meanwhile', function (): void {
    $new = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 881_002, 'first_name' => 'New']);
    expect(app(AccountRestoreNotice::class)->isPending($new->fresh()))->toBeFalse();

    $deleted = deletedLearner(881_003);
    expect(app(LearnerRegistrar::class)->observeTelegramUser(['id' => 881_003, 'first_name' => 'X']))->toBeNull()
        ->and(Learner::withTrashed()->find($deleted->id)->trashed())->toBeTrue()
        ->and(Learner::withTrashed()->find($deleted->id)->meta)->not->toHaveKey('restored_notice_at');

    $purged = deletedLearner(881_004);
    Learner::withTrashed()->whereKey($purged->id)->forceDelete();
    $again = comeBack($purged);

    expect($again->id)->not->toBe($purged->id)
        ->and(app(AccountRestoreNotice::class)->isPending($again->fresh()))->toBeFalse()
        ->and(restoredNotices())->toBe(0);
});

it('consume takes the marker once and is idempotent', function (): void {
    $back = comeBack(deletedLearner(881_005));
    $notice = app(AccountRestoreNotice::class);

    expect($notice->consume($back))->toBeTrue()
        ->and($notice->isPending($back))->toBeFalse()
        ->and($notice->consume($back))->toBeFalse()
        ->and($notice->consume(Learner::query()->findOrFail($back->id)))->toBeFalse()
        ->and(Learner::query()->findOrFail($back->id)->meta)->toBe([]);
});

it('GET /me carries account_restored after the return and null after seen; seen is idempotent', function (): void {
    $learner = deletedLearner(881_006);
    comeBack($learner);

    $this->withHeaders(restoreHeaders($learner))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('account_restored', ['pro_can_resume' => false]);

    $this->withHeaders(restoreHeaders($learner))->postJson('/api/twa/me/account-restored/seen')->assertNoContent();
    $this->withHeaders(restoreHeaders($learner))->getJson('/api/twa/me')->assertOk()->assertJsonPath('account_restored', null);
    $this->withHeaders(restoreHeaders($learner))->postJson('/api/twa/me/account-restored/seen')->assertNoContent();
});

it('GET /me tells whether the Pro auto-renewal can be switched on again', function (): void {
    $learner = deletedLearner(881_007);
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'canceled', 'provider' => 'telegram_stars']);
    comeBack($learner);

    $this->withHeaders(restoreHeaders($learner))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('account_restored.pro_can_resume', true);
});

it('GET /me does not spend a query on the notice while there is none', function (): void {
    $learner = Learner::factory()->create();
    $headers = restoreHeaders($learner);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk();

    DB::enableQueryLog();
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    $without = count(DB::getQueryLog());
    DB::flushQueryLog();

    $other = deletedLearner(881_008);
    comeBack($other);
    $withHeaders = restoreHeaders($other);
    $this->withHeaders($withHeaders)->getJson('/api/twa/me')->assertOk();
    DB::flushQueryLog();
    $this->withHeaders($withHeaders)->getJson('/api/twa/me')->assertOk();
    $with = count(DB::getQueryLog());

    // Only the subscription lookup on top, and the authentication of a learner already known is the same.
    expect($with - $without)->toBeLessThanOrEqual(1);
});

it('/start tells about the return once, in the learner language', function (string $lang, string $needle): void {
    $learner = deletedLearner(881_010, $lang);

    restoreStart(881_010, $lang);
    $first = collect($this->tg->sent)->pluck('text')->all();
    $count = count($this->tg->sent);
    restoreStart(881_010, $lang);

    expect($first[0])->toContain($needle)->not->toContain('Автопродление')->not->toContain('avtomatik uzaytirilishi')
        ->and(collect($this->tg->sent)->slice($count)->pluck('text')->implode("\n"))->not->toContain($needle)
        ->and(Learner::query()->findOrFail($learner->id)->meta)->not->toHaveKey('restored_notice_at')
        ->and(restoredNotices())->toBe(1);
})->with([
    'ru' => ['ru', 'Аккаунт восстановлен'],
    'uz' => ['uz', 'Hisobingiz tiklandi'],
]);

it('/start adds the auto-renewal line only when it can be switched on again', function (): void {
    $learner = deletedLearner(881_011);
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'canceled', 'provider' => 'telegram_stars']);

    restoreStart(881_011);

    expect($this->tg->sent[0]['text'])->toContain('Аккаунт восстановлен')->toContain('Автопродление Pro было выключено');
});

it('the first channel takes the notice: the Mini App silences /start, /start silences the Mini App', function (): void {
    $a = deletedLearner(881_012);
    comeBack($a);
    $this->withHeaders(restoreHeaders($a))->postJson('/api/twa/me/account-restored/seen')->assertNoContent();
    restoreStart(881_012);
    expect(collect($this->tg->sent)->pluck('text')->implode("\n"))->not->toContain('Аккаунт восстановлен');

    $b = deletedLearner(881_013);
    restoreStart(881_013);
    expect(collect($this->tg->sent)->pluck('text')->implode("\n"))->toContain('Аккаунт восстановлен');
    $this->withHeaders(restoreHeaders($b))->getJson('/api/twa/me')->assertOk()->assertJsonPath('account_restored', null);
});

it('other commands do not spend the marker', function (): void {
    $learner = deletedLearner(881_014);
    comeBack($learner);

    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => 881_014, 'type' => 'private'],
        'from' => ['id' => 881_014, 'first_name' => 'Ali'],
        'text' => '/pro',
    ]]);

    expect(app(AccountRestoreNotice::class)->isPending(Learner::query()->findOrFail($learner->id)))->toBeTrue();
});
