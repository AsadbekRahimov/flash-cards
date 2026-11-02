<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Administration\Services\PanelRequestMemo;
use App\Domain\Billing\Providers\Click\ClickSignature;
use App\Domain\Billing\Providers\ClickProvider;
use App\Domain\Billing\Providers\Payme\PaymeAuth;
use App\Domain\Billing\Providers\PaymeProvider;
use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\NewWordQuota;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\Support\FsrsParameters;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Services\OrgRequestMemo;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\ProviderTransaction;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\WordTranslation;
use App\Observers\CourseTreeObserver;
use App\Observers\MoneyWidgetsObserver;
use App\Observers\OrganizationObserver;
use App\Observers\OrganizationUserObserver;
use App\Observers\TelegramGroupObserver;
use App\Observers\WordTranslationObserver;
use App\Policies\Org\OrgPolicyResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
                globalPerSecond: max(0, (int) config('telegram.outbound.global_per_second', 25)),
            );
        });

        // The pure cache store (docs/19 §4): PureCache::store() resolves it here, so a test can swap it with instance().
        // Not a singleton: the cache manager already keeps one repository per store name.
        $this->app->bind(PureCache::CONTAINER_KEY, fn (): Repository => PureCache::resolve());

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
            $registry->register($this->app->make(PaymeProvider::class));
            $registry->register($this->app->make(ClickProvider::class));

            return $registry;
        });

        // The access checks of the panels (the role and the visible groups in `/org`, the groups of a teacher in
        // `/admin`) are read once per request or queued job: a scoped binding is forgotten by the queue worker before
        // each job and by Octane between requests (docs/20 F4, A11).
        $this->app->scoped(OrgRequestMemo::class);
        $this->app->scoped(PanelRequestMemo::class);

        // The words whose cached translations an open transaction has changed are gathered by the observer and dropped with ONE call
        // after the commit (docs/19 K4). Eloquent makes the observer anew for every event, so the rows of an import share the state
        // only through a binding: one instance for the request or the job, forgotten with it.
        $this->app->scoped(WordTranslationObserver::class);

        // The same for the members of a group that is being deleted: the observer enumerates them in `deleting` (the cascade is about to
        // remove their rows) and uses them in `deleted`, which is a different event and, without a binding, a different observer.
        $this->app->scoped(TelegramGroupObserver::class);

        // Keys are read per request (not a singleton): a rotated PAYME_KEY / PAYME_TEST_KEY applies at once in tests and after config:cache.
        $this->app->bind(PaymeAuth::class, static fn (): PaymeAuth => PaymeAuth::fromConfig());
        $this->app->bind(ClickSignature::class, static fn (): ClickSignature => ClickSignature::fromConfig());

        $this->app->singleton(LearningPolicy::class, function (): LearningPolicy {
            /** @var array<string, mixed> $config */
            $config = (array) config('learning', []);

            return LearningPolicy::fromConfig($config);
        });

        $this->app->singleton(FsrsParameters::class, function (): FsrsParameters {
            /** @var array<string, mixed> $config */
            $config = (array) config('learning.fsrs', []);

            return FsrsParameters::fromConfig($config);
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
        $this->app->make(FsrsParameters::class);

        // `subscriptions.subscriber_type` stores 'learner' / 'organization' (docs/12 §1.9).
        Relation::morphMap(['learner' => Learner::class, 'organization' => Organization::class]);

        // Entitlement cache invalidation on the inputs that live outside the
        // billing services: a group's organization link / status and an
        // organization's status (docs/12 §5.2).
        TelegramGroup::observe(TelegramGroupObserver::class);
        Organization::observe(OrganizationObserver::class);

        // The money widgets of the admin dashboard are cached; a payment, a refund or a provider's cancel drops them (docs/19 K1).
        Payment::observe(MoneyWidgetsObserver::class);
        ProviderTransaction::observe(MoneyWidgetsObserver::class);

        // Content caches (docs/19 K3, K4): the tree of a course follows its modules and lessons, the translation rows of a word
        // follow its `word_translations`; the words of a course and the statuses are dropped by CoursePublisher::recount()/transition().
        Stage::observe(CourseTreeObserver::class);
        Lesson::observe(CourseTreeObserver::class);
        WordTranslation::observe(WordTranslationObserver::class);

        // `/org` cabinet: tenant-aware policies replace the platform ones while that panel is served (docs/13 §6.3).
        OrgPolicyResolver::register();

        // A staff row written in this request changes the role its policies see: drop the per-request memory.
        OrganizationUser::observe(OrganizationUserObserver::class);

        // A process that handles many requests without Octane (the test suite) would carry the memories of the
        // panels from one request into the next; FPM and Octane do not need this, it costs them nothing.
        Event::listen(RequestHandled::class, function (): void {
            foreach ([OrgRequestMemo::class, PanelRequestMemo::class] as $memo) {
                if ($this->app->resolved($memo)) {
                    $this->app->make($memo)->flush();
                }
            }
        });

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

        // Cancel / resume of auto-renewal (a provider call each for Stars): 6/min per learner, shared by both.
        RateLimiter::for('twa-subscription', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(6)->by('twa-subscription|'.$key);
        });

        // Payment provider webhooks (docs/13 §4): 120/min per caller IP; the provider retries on 429 like on any non-200.
        RateLimiter::for('billing-webhook', function (Request $request): Limit {
            return Limit::perMinute(120)->by('billing-webhook|ip|'.$request->ip());
        });

        // The public return page after a Payme / Click checkout: read-only, by order token.
        RateLimiter::for('billing-return', function (Request $request): Limit {
            return Limit::perMinute(30)->by('billing-return|ip|'.$request->ip());
        });

        RateLimiter::for('twa-organizations', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(10)->by('twa-organizations|'.$key);
        });

        // Friend requests, answers and challenges (docs/13 §7.5): a few taps a minute are normal, a script is not.
        RateLimiter::for('twa-friends', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(30)->by('twa-friends|'.$key);
        });

        RateLimiter::for('twa-tts', function (Request $request): Limit {
            /** @var array{learner_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['learner_id'] ?? $request->ip();

            return Limit::perMinute(30)->by('twa-tts|'.$key);
        });

        // Practice and battle (docs/16 §7.5), all keyed by the learner. `twa-practice-answer` and the creation limiters sit
        // on top of `twa-api`; the audio and the battle poll are OUTSIDE it, with a bucket of their own.
        foreach ([
            'twa-practice-open' => 10,   // a session = a snapshot + the queries of the material
            'twa-practice-answer' => 90, // a quick learner matches ~40 pairs a minute; `twa-api` (60) applies too
            'twa-practice-audio' => 60,  // 5 clips at once when a room is entered
            'twa-battle-invite' => 5,    // an invitation = a snapshot, rows, a DM to the friend
            'twa-battle-action' => 60,   // accept / decline / cancel / ready / surrender / answer / audio-failed
            'twa-battle-poll' => 90,     // a poll every 2 s is 30 a minute, two tabs 60: a bucket of its own, not "no limit"
        ] as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($name, $perMinute): Limit {
                /** @var array{learner_id:int}|null $ctx */
                $ctx = $request->attributes->get('twa');

                return Limit::perMinute($perMinute)->by($name.'|'.($ctx['learner_id'] ?? $request->ip()));
            });
        }
    }
}
