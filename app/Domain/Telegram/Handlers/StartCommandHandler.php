<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Enums\StartPayloadKind;
use App\Domain\Learners\Services\AccountRestoreNotice;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Enums\AccountLinkResult;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\FriendsMessenger;
use App\Domain\Telegram\Services\OnboardingMessenger;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Domain\Telegram\Services\TelegramAccountLinkService;
use App\Domain\Telegram\Services\TodayScreen;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * `/start [payload]` in a DM (docs/12 §7.1). A linked teacher gets the greeting as before; anyone
 * else is a learner: the deep-link payload is attributed (first touch wins), then the onboarding
 * starts — or, for a learner who has finished it, the "Today" screen is shown. An `org_<code>` link
 * (docs/13 §6.2) adds a "Join center X?" question: at once for a learner who has finished the
 * onboarding, after its last step otherwise; a code that cannot be used is explained and ignored.
 * A `ref_<code>` link (docs/13 §7.1) also links the referral when the learner is new. An `f_<code>` link, or a
 * `ref_<code>` link opened by somebody who is ALREADY a learner (docs/13 §7.5), is an invitation to be friends:
 * "X invites you" with Accept / Decline at once for a learner who has finished the onboarding, a silent pending
 * question after the last step (the code is remembered) for one who has not. A `link_<token>` link (docs/13 §6.2) is not a
 * learner's at all: it connects the sender's Telegram to the staff account that asked for it and creates no learner.
 */
final class StartCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $learners,
        private readonly LearnerRegistrar $registrar,
        private readonly OnboardingMessenger $messenger,
        private readonly TodayScreen $today,
        private readonly OrganizationMessenger $organizations,
        private readonly ReferralService $referrals,
        private readonly FriendsMessenger $friends,
        private readonly AccountRestoreNotice $restoreNotice,
        private readonly TelegramAccountLinkService $accountLinks,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $chatType = (string) ($message['chat']['type'] ?? '');
        $text = (string) ($message['text'] ?? '');

        return $chatType === 'private' && preg_match('~^/start(?:@\w+)?(\s|$)~', $text) === 1;
    }

    // Private-chat only command; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        /** @var array<string, mixed> $from */
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $tgUserId = isset($from['id']) ? (int) $from['id'] : 0;
        $chatId = (int) $message['chat']['id'];

        if ($tgUserId <= 0) {
            return;
        }

        // `link_<token>` (docs/13 §6.2, audit H1): a staff user connecting their Telegram. Before anything else — no learner row is
        // created for them, nothing is attributed, and the answer does not depend on whether they are a teacher already.
        $linkToken = StartPayload::parse($this->payloadFrom((string) ($message['text'] ?? '')))->accountLinkToken();
        if ($linkToken !== null) {
            $this->linkAccount($linkToken, $from, $chatId);

            return;
        }

        $user = User::where('telegram_user_id', $tgUserId)->first();

        if ($user !== null) {
            $user->update(['last_login_at' => now()]);

            $this->telegram->sendMessage(
                $chatId,
                __('bot.start.greeting', ['name' => $user->name], $this->locales->forTeacher()),
            );

            return;
        }

        $learner = $this->learners->registerLearner($from);

        // A blocked learner is told so and nothing else is recorded about them.
        if ($learner->blocked_at !== null) {
            $this->telegram->sendMessage($chatId, __('onboarding.blocked', [], $this->locales->forLearner($learner)));

            return;
        }

        $this->announceRestore($learner, $chatId);

        $payload = StartPayload::parse($this->payloadFrom((string) ($message['text'] ?? '')));
        $this->registrar->attributeAcquisition($learner, $payload);

        // The referral itself (docs/13 §7.1): only a learner CREATED by this /start can be somebody's
        // referral; the service records a rejected row for anybody else and a repeat is harmless.
        $referralCode = $payload->referralCode();
        if ($referralCode !== null) {
            $this->referrals->link($learner, $referralCode, CarbonImmutable::now());
        }

        // "Be my friend" (docs/13 §7.5): only an `f_<code>` link; a `ref_<code>` link never offers a friendship.
        $friendCode = $payload->friendCode();

        if ($learner->onboarding_state === OnboardingService::DONE) {
            if ($friendCode !== null) {
                // The invitation is why they came: the question replaces "Today".
                $this->friends->offer($learner, $chatId, $friendCode);

                return;
            }

            if ($payload->kind === StartPayloadKind::Org && $payload->value !== null) {
                // The invite link is why they came: the question replaces "Today".
                $this->organizations->offer($learner, $chatId, $payload->value);

                return;
            }

            $this->today->send($learner, $chatId);
            // A `c_<slug>` link of a finished learner does not change the active course; it opens the course.
            $this->messenger->sendCourseLink($learner, $chatId, $payload);

            return;
        }

        // `f_<code>` of somebody still in the onboarding writes no friendship (no consent yet); OnboardingService::start
        // remembers the code and FriendsMessenger::offerPending asks "Add to friends?" after the last step.

        // A code that cannot be used is explained now and not remembered for the end of the onboarding.
        if (! $this->organizations->acceptsPayload($learner, $chatId, $payload)) {
            $payload = StartPayload::none();
        }

        $this->messenger->resume($learner, $chatId, $payload, greet: true);
    }

    /**
     * R11b: a learner who came back within the 30 days is told so, once, before the usual answer. Only `/start` (and the
     * Mini App) spend the marker; whoever takes it first shows the notice, the other channel stays silent.
     */
    private function announceRestore(Learner $learner, int $chatId): void
    {
        if (! $this->restoreNotice->isPending($learner)) {
            return;
        }

        $canResume = $this->restoreNotice->proCanResume($learner);

        if (! $this->restoreNotice->consume($learner)) {
            return;
        }

        $locale = $this->locales->forLearner($learner);
        $text = __('bot.account.restored', [], $locale);
        if ($canResume) {
            $text .= "\n\n".__('bot.account.restored_pro', [], $locale);
        }

        $this->telegram->sendMessage($chatId, $text);
    }

    /**
     * `/start link_<token>`: connects the sender's Telegram to the staff account that asked for the link, and says so — in the
     * sender's Telegram language (they are staff, not a learner with a chosen interface language). Refusals are explained to the
     * sender only (they own the account in question); an unknown / dead token gets one answer.
     *
     * @param  array<string, mixed>  $from
     */
    private function linkAccount(string $token, array $from, int $chatId): void
    {
        $languageCode = $from['language_code'] ?? null;
        $locale = $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);
        $outcome = $this->accountLinks->linkFromStart($token, $from);

        $text = match ($outcome->result) {
            AccountLinkResult::Linked, AccountLinkResult::AlreadyLinked => __('bot.link.account_linked', ['name' => $outcome->user !== null ? $outcome->user->name : ''], $locale),
            AccountLinkResult::TelegramTaken => __('bot.link.account_taken', [], $locale),
            AccountLinkResult::TokenInvalid => __('bot.link.account_refused', [], $locale),
        };

        $this->telegram->sendMessage($chatId, $text);
    }

    /** The first word after `/start` (Telegram sends the deep-link parameter as `/start <parameter>`). */
    private function payloadFrom(string $text): ?string
    {
        return preg_match('~^/start(?:@\w+)?\s+(\S+)~', $text, $m) === 1 ? $m[1] : null;
    }
}
