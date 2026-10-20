<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Billing\Providers\StarsPreCheckoutGuard;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The synchronous branch of the Telegram webhook (docs/13 §0, §4.1): a `pre_checkout_query` must be
 * answered within 10 seconds, which the queue cannot promise, so WebhookController calls this
 * directly instead of dispatching HandleTelegramUpdate. Reads only (StarsPreCheckoutGuard), one
 * Bot API call, never throws: an internal failure refuses the payment with a generic text, a
 * failed answer is logged (Telegram shows its own "try again" to the payer).
 */
final class PreCheckoutResponder
{
    public function __construct(
        private readonly StarsPreCheckoutGuard $guard,
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $update */
    public static function applies(array $update): bool
    {
        return is_array($update['pre_checkout_query'] ?? null);
    }

    /** @param array<string, mixed> $update */
    public function respond(array $update): void
    {
        /** @var array<string, mixed> $query */
        $query = $update['pre_checkout_query'];
        $queryId = (string) ($query['id'] ?? '');

        if ($queryId === '') {
            return;
        }

        try {
            $verdict = $this->guard->check($query);
            $ok = $verdict->ok;
            $errorMessage = $verdict->errorMessage;
        } catch (Throwable $e) {
            Log::error('billing.stars.pre_checkout_failed', ['update_id' => $update['update_id'] ?? null, 'exception' => $e::class]);
            $languageCode = $query['from']['language_code'] ?? null;
            $ok = false;
            $errorMessage = (string) __('billing.stars.precheckout.unavailable', [], $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null));
        }

        try {
            $this->telegram->answerPreCheckoutQuery($queryId, $ok, $errorMessage);
        } catch (TelegramApiException $e) {
            Log::warning('billing.stars.pre_checkout_unanswered', ['update_id' => $update['update_id'] ?? null, 'ok' => $ok, 'error' => $e->getMessage()]);
        }
    }
}
