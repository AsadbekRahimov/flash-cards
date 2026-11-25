<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupAdminCheck;
use App\Domain\Cup\Services\CupCards;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Support\CupCallback;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The buttons of the friends' cup in a group (docs/33 §3.2, §3.3, docs/04 B.16): `cup:new` («Создать кубок друзей» under the welcome),
 * `cup:pub:{id}` («Опубликовать») and `cup:cancel:{id}` («Отменить») under the proposal.
 *
 * The data is matched strictly ({@see CupCallback}) and trusted for nothing: the cup must be one of THIS chat and the button must sit
 * on its card (`message_id = cups.card_message_id`) - anything else (another chat, an old proposal, a closed cup, a malformed
 * payload) gets one and the same toast «Эта кнопка больше не действует». Who may publish or cancel is decided by the core with a fresh
 * `getChatMember` of `callback_query.from.id` ({@see CupAdminCheck}) - the signed webhook says who tapped; a member who is not an
 * administrator gets a short toast and nothing is posted to the chat. Every tap is answered once (`answerCallbackQuery`); the toasts
 * are in the tapper's language, the messages in the chat in the cup's.
 *
 * - `cup:new` → {@see CupLifecycleService::propose()}: a new draft gets its proposal posted through the outbox (one row `card` per cup:
 *   two taps at once make one draft and one message); an open cup answers «В этом чате уже есть кубок».
 * - `cup:pub` → {@see CupLifecycleService::publish()}, then the proposal is edited into the card in place (the same message).
 * - `cup:cancel` → {@see CupLifecycleService::cancelByChatAdmin()}, then the card says «Кубок отменён» without buttons.
 *
 * Works in `pending` groups (friends' chats are never activated), so `requiresActiveGroup()` is false. With `CUP_ACCESS=off` (or a
 * group outside the pilot) the tap is only acknowledged - as an unknown button.
 */
final class CupCallbackHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CupLifecycleService $lifecycle,
        private readonly CupOutbox $outbox,
        private readonly CupCards $cards,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $data = $update['callback_query']['data'] ?? null;

        return is_string($data) && ($data === CupCallback::PREFIX || str_starts_with($data, CupCallback::PREFIX.':'));
    }

    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $cq */
        $cq = $update['callback_query'];
        $queryId = (string) ($cq['id'] ?? '');
        $fromId = (int) ($cq['from']['id'] ?? 0);
        $locale = $this->locales->fromLanguageCode(is_string($cq['from']['language_code'] ?? null) ? $cq['from']['language_code'] : null);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if ((string) config('cup.access', 'off') === 'off') {
            $this->answer($queryId, null);

            return;
        }

        $parsed = CupCallback::parse((string) ($cq['data'] ?? ''));
        if ($parsed === null
            || $fromId <= 0
            || $chatId === 0
            || ! in_array((string) ($message['chat']['type'] ?? ''), ['group', 'supergroup'], true)
            // An InaccessibleMessage (`date` = 0): nothing to tie the tap to.
            || (int) ($message['date'] ?? 1) === 0
        ) {
            $this->answer($queryId, 'cup.toast.stale', $locale);

            return;
        }

        $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null) {
            $this->answer($queryId, 'cup.toast.stale', $locale);

            return;
        }
        if (! CupAccess::allowsGroup($group)) {
            $this->answer($queryId, null);

            return;
        }

        $now = CarbonImmutable::now();
        if ($parsed['action'] === CupCallback::NEW) {
            $this->create($queryId, $group, $fromId, $this->threadOf($message), $locale, $now);

            return;
        }

        /** @var Cup|null $cup */
        $cup = Cup::query()->whereKey((int) $parsed['cup_id'])->where('telegram_group_id', $group->id)->first();
        if ($cup === null || $messageId <= 0 || $cup->card_message_id !== $messageId) {
            $this->answer($queryId, 'cup.toast.stale', $locale);

            return;
        }
        $cup->setRelation('telegramGroup', $group);

        $parsed['action'] === CupCallback::PUBLISH
            ? $this->publish($queryId, $cup, $fromId, $locale, $now)
            : $this->cancel($queryId, $cup, $fromId, $locale, $now);
    }

    private function create(string $queryId, TelegramGroup $group, int $fromId, ?int $threadId, string $locale, CarbonImmutable $now): void
    {
        try {
            $proposal = $this->lifecycle->propose($group, $fromId, $threadId, $now);
        } catch (CupException $e) {
            $this->answer($queryId, $this->refusal($e), $locale);

            return;
        }

        if (! $proposal->created) {
            $this->answer($queryId, 'cup.toast.exists', $locale);

            return;
        }

        $this->answer($queryId, null);
        $this->outbox->enqueue($proposal->cup->id, CupOutbox::CARD_KEY, CupMessageKind::Card, $now);
    }

    private function publish(string $queryId, Cup $cup, int $fromId, string $locale, CarbonImmutable $now): void
    {
        if ($cup->status !== CupStatus::Draft && $cup->status !== CupStatus::Scheduled && $cup->status !== CupStatus::Running) {
            $this->answer($queryId, 'cup.toast.stale', $locale);

            return;
        }

        try {
            $published = $this->lifecycle->publish($cup, $fromId, null, null, $now);
        } catch (CupException $e) {
            $this->answer($queryId, $this->refusal($e), $locale);

            return;
        }

        $this->answer($queryId, 'cup.toast.published', $locale);
        // The proposal becomes the card: the same message, edited in place (docs/33 §3.1).
        $published->setRelation('telegramGroup', $cup->telegramGroup);
        $this->cards->refresh($published, $now);
    }

    private function cancel(string $queryId, Cup $cup, int $fromId, string $locale, CarbonImmutable $now): void
    {
        try {
            $canceled = $this->lifecycle->cancelByChatAdmin($cup, $fromId, $now);
        } catch (CupException $e) {
            $this->answer($queryId, $this->refusal($e), $locale);

            return;
        }

        if (! $canceled) {
            $this->answer($queryId, 'cup.toast.stale', $locale);

            return;
        }

        $this->answer($queryId, 'cup.toast.canceled', $locale);
        $fresh = Cup::query()->findOrFail($cup->id);
        $fresh->setRelation('telegramGroup', $cup->telegramGroup);
        $this->cards->refresh($fresh, $now);
    }

    /** The toast for a refusal of the core. */
    private function refusal(CupException $e): string
    {
        return match ($e->errorCode) {
            CupException::NOT_CHAT_ADMIN => 'cup.toast.not_admin',
            CupException::BOT_NOT_ADMIN => 'cup.toast.bot_not_admin',
            CupException::UNVERIFIABLE => ($e->details['reason'] ?? null) === CupException::REASON_BOT_NOT_ADMIN ? 'cup.toast.bot_not_admin' : 'cup.toast.unverifiable',
            CupException::PAUSED, CupException::UNAVAILABLE => 'cup.toast.paused',
            CupException::MATERIAL_UNAVAILABLE, CupException::LEVEL_INVALID => 'cup.toast.material',
            CupException::CLOSED, CupException::ALREADY_PUBLISHED => 'cup.toast.stale',
            default => 'cup.toast.failed',
        };
    }

    /**
     * The topic of a forum the button was tapped in (`message_thread_id` of a topic message only - in an ordinary supergroup the field
     * may point to a reply thread, not a topic).
     *
     * @param  array<string, mixed>  $message
     */
    private function threadOf(array $message): ?int
    {
        return ($message['is_topic_message'] ?? false) === true && is_int($message['message_thread_id'] ?? null) ? $message['message_thread_id'] : null;
    }

    /** One answer per tap: a short toast for the tapper (not an alert, nothing in the chat), or just the end of the spinner. */
    private function answer(string $queryId, ?string $key, string $locale = 'ru'): void
    {
        if ($queryId === '') {
            return;
        }

        try {
            $key === null
                ? $this->telegram->answerCallbackQuery($queryId)
                : $this->telegram->answerCallbackQuery($queryId, mb_substr(__($key, [], $locale), 0, 200));
        } catch (TelegramApiException $e) {
            Log::warning('telegram.callback_ack_failed', ['code' => $e->getCode()]);
        }
    }
}
