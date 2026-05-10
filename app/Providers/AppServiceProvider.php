<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Telegram\Bot\Api;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramClient::class, function (): TelegramClient {
            return new IrazasyedTelegramClient(
                new Api((string) config('telegram.bot_token')),
                $this->app->make(TelegramKeyboardFactory::class),
            );
        });

        $this->app->singleton(InitDataValidator::class, function (): InitDataValidator {
            return new InitDataValidator((string) config('telegram.bot_token'));
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
        RateLimiter::for('login', function (Request $request): Limit {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)
                ->by(Str::lower($email).'|'.$request->ip());
        });

        RateLimiter::for('twa-auth', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) $request->ip());
        });

        RateLimiter::for('twa-api', function (Request $request): Limit {
            /** @var array{student_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['student_id'] ?? $request->ip();

            return Limit::perMinute(60)->by('twa-api|'.$key);
        });

        RateLimiter::for('twa-review', function (Request $request): Limit {
            /** @var array{student_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['student_id'] ?? $request->ip();

            return Limit::perMinute(60)->by('twa-review|'.$key);
        });

        RateLimiter::for('twa-exam-answer', function (Request $request): Limit {
            /** @var array{student_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['student_id'] ?? $request->ip();

            // Exam pace cap: ~30 answers/min protects against script spam
            // while leaving headroom for 10-questions-in-2-minutes sprints.
            return Limit::perMinute(30)->by('twa-exam-answer|'.$key);
        });

        RateLimiter::for('twa-tts', function (Request $request): Limit {
            /** @var array{student_id:int}|null $ctx */
            $ctx = $request->attributes->get('twa');
            $key = $ctx['student_id'] ?? $request->ip();

            return Limit::perMinute(30)->by('twa-tts|'.$key);
        });
    }
}
