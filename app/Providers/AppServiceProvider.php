<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\NewWordQuota;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Observers\OrganizationObserver;
use App\Observers\TelegramGroupObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Telegram\Bot\Api;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IrazasyedTelegramClient::class, function (): IrazasyedTelegramClient {
            return new IrazasyedTelegramClient(
                new Api((string) config('telegram.bot_token')),
                $this->app->make(TelegramKeyboardFactory::class),
                $this->app->make(TelegramMessageRateLimiter::class),
            );
        });
        $this->app->alias(IrazasyedTelegramClient::class, TelegramClient::class);

        $this->app->singleton(TelegramMessageRateLimiter::class, function (): TelegramMessageRateLimiter {
            return new TelegramMessageRateLimiter(
                maxMessages: max(1, (int) config('telegram.outbound_rate_limit.max_messages', 20)),
                windowSeconds: max(1, (int) config('telegram.outbound_rate_limit.window_seconds', 60)),
            );
        });

        $this->app->singleton(InitDataValidator::class, function (): InitDataValidator {
            return new InitDataValidator((string) config('telegram.bot_token'));
        });

        // New-card budget for CardPicker: the plan's daily limit of new words (Free 20, Pro unlimited; docs/12 §5.3).
        $this->app->bind(NewCardBudgetResolver::class, NewWordQuota::class);

        // Payment providers register themselves here as they land (2A.3 Stars, 2A.4 Payme,
        // 2A.5 Click); whether one is sellable is `billing.providers.<key>.enabled` (docs/13 §2.2).
        $this->app->singleton(BillingProviderRegistry::class, function (): BillingProviderRegistry {
            $registry = new BillingProviderRegistry;
            $registry->register($this->app->make(TelegramStarsProvider::class));

            return $registry;
        });

        $this->app->singleton(LearningPolicy::class, function (): LearningPolicy {
            /** @var array<string, mixed> $config */
            $config = (array) config('learning', []);

            return LearningPolicy::fromConfig($config);
        });

        $this->app->singleton(JwtService::class, function (): JwtService {
            return new JwtService(
                secret: (string) config('twa.jwt.secret'),
                ttl: (int) config('twa.jwt.ttl', 900),
                alg: (string) config('twa.jwt.alg', 'HS256'),
                issuer: (string) config('twa.jwt.iss', 'lexiflow'),
            );
        });
    }

    public function boot(): void
    {
        // There is no lang/en: a key missing in uz must resolve to the ru text, not
        // render raw. config/localization.php is the single source of truth (this also
        // overrides a stale APP_FALLBACK_LOCALE=en copied from an old .env.example).
        $this->app->setFallbackLocale($this->app->make(LocaleResolver::class)->fallback());

        // Fail fast on an inconsistent SRS configuration (e.g. learn-ahead window
        // shorter than the longest learning step): LearningPolicy throws with an
        // actionable message, so `artisan config:cache` in the container
        // entrypoint and the health check fail at deploy time rather than the
        // first learner request silently ending sessions early (docs/06 §3.1).
        $this->app->make(LearningPolicy::class);

        // `subscriptions.subscriber_type` stores 'learner' / 'organization' (docs/12 §1.9).
        Relation::morphMap(['learner' => Learner::class, 'organization' => Organization::class]);

        // Entitlement cache invalidation on the inputs that live outside the
        // billing services: a group's organization link / status and an
        // organization's status (docs/12 §5.2).
        TelegramGroup::observe(TelegramGroupObserver::class);
        Organization::observe(OrganizationObserver::class);

        RateLimiter::for('login', function (Request $request): Limit {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)
                ->by(Str::lower($email).'|'.$request->ip());
        });

        // 60/min per IP: a mobile carrier's CGNAT address serves whole classrooms. The per-person
        // cap (10/min per Telegram user) can only be applied after the initData HMAC check, so it
        // lives in AuthController ('twa-auth-user|{telegram_user_id}'), docs/12 §8.
        RateLimiter::for('twa-auth', function (Request $request): Limit {
            return Limit::perMinute(60)->by((string) $request->ip());
        });

        RateLimiter::for('twa-api', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(60)->by('twa-api|'.$key);
        });

        RateLimiter::for('twa-review', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(60)->by('twa-review|'.$key);
        });

        RateLimiter::for('twa-session-create', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            // Opening a personal session is idempotent; the cap only stops scripts from hammering it.
            return Limit::perMinute(10)->by('twa-session-create|'.$key);
        });

        RateLimiter::for('twa-exam-answer', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            // Exam pace cap: ~30 answers/min protects against script spam
            // while leaving headroom for 10-questions-in-2-minutes sprints.
            return Limit::perMinute(30)->by('twa-exam-answer|'.$key);
        });

        RateLimiter::for('twa-decks', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(30)->by('twa-decks|'.$key);
        });

        RateLimiter::for('twa-deck-import', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            // One import is up to 500 lines of inserts: a human pastes a few lists a minute.
            return Limit::perMinute(10)->by('twa-deck-import|'.$key);
        });

        RateLimiter::for('twa-promo', function (Request $request): array {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');

            // Guessing codes: 5/min per learner; 60/min per IP — mobile carriers put
            // whole classrooms behind one CGNAT address, so the IP cap only has to stop
            // scripted guessing from many accounts, not legitimate bursts (docs/12 §8).
            return [
                Limit::perMinute(5)->by('twa-promo|learner|'.($ctx['learner_id'] ?? $request->ip())),
                Limit::perMinute(60)->by('twa-promo|ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('twa-checkout', function (Request $request): array {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');

            // Every checkout is a row in payment_orders and a call to the payment provider:
            // 10/min per learner; the IP cap is wide because carriers share addresses (docs/13 §5).
            return [
                Limit::perMinute(10)->by('twa-checkout|learner|'.($ctx['learner_id'] ?? $request->ip())),
                Limit::perMinute(120)->by('twa-checkout|ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('twa-organizations', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(10)->by('twa-organizations|'.$key);
        });

        RateLimiter::for('twa-tts', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(30)->by('twa-tts|'.$key);
        });
    }
}
