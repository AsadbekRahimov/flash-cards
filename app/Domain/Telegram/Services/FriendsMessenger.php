<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Engagement\Enums\ChallengeStatus;
use App\Domain\Engagement\Enums\FriendDmKind;
use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Domain\Engagement\Exceptions\ChallengeException;
use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\ChallengeService;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * Everything the bot says about friends and challenges (docs/13 §7.5): the "send X a friend
 * request?" question of an `f_<code>` link, the answers to its buttons, and the background DMs
 * (a friend request, a friend accepted, a challenge proposed / accepted / finished). Texts: `lang/{ru,uz}/friends.php`, in the
 * recipient's language. The rules are {@see FriendshipService} / {@see ChallengeService}; this class only talks
 * to Telegram.
 *
 * `callback_data` (≤ 64 bytes; the prefix carries the format version, as with `org1` / `onb1`):
 *   fr1:snd:<code> / fr1:no:<code> — the question of an `f_<code>` link (`<code>` = the link owner's referral code):
 *                                   send the request / not now;
 *   fr1:acc:<id> / fr1:dec:<id>    — a friend request (`<id>` = friendships.id): accept / decline;
 *   fr1:cacc:<id> / fr1:cdec:<id>  — challenge (`<id>` = challenges.id): accept / decline.
 * Nothing in it is trusted: the services look the id up among the sender's OWN rows only.
 *
 * A link opened BEFORE the onboarding is remembered (`learners.meta.pending_friend_invite`) and offered the same
 * way, with an "Add to friends" button, right after the last step ({@see offerPending()}).
 *
 * Consent: a public link is only an address. Opening it writes NOTHING; the opener is asked and, on "send",
 * `request(opener → inviter)` is created — the link owner then accepts or declines it himself (a DM with
 * buttons, and "Incoming" in the app). A `ref_<code>` link offers no friendship at all.
 */
final class FriendsMessenger
{
    public const PREFIX = 'fr1';

    public const NOTIFICATION_TYPE = 'friend_dm';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly FriendshipService $friendships,
        private readonly ChallengeService $challenges,
        private readonly OnboardingMessenger $onboardingMessenger,
        private readonly DmLearnerResolver $dm,
        private readonly OnboardingService $onboarding,
    ) {}

    /**
     * `/start f_<code>` of a learner who has finished the onboarding (or, with `$afterOnboarding`, the link
     * remembered from before it): asks "send X a friend request?". Writes nothing.
     */
    public function offer(Learner $learner, int|string $chatId, string $code, bool $afterOnboarding = false): void
    {
        $locale = $this->locales->forLearner($learner);
        $inviter = $this->friendships->ownerOfCode($code);
        if ($inviter === null) {
            $this->telegram->sendMessage($chatId, __('friends.errors.invalid_link', [], $locale));

            return;
        }
        if ($inviter->id === $learner->id) {
            $this->telegram->sendMessage($chatId, $this->errorText(FriendshipException::SELF, $locale));

            return;
        }

        $name = $this->nameOf($inviter, $locale);
        $existing = $this->friendships->between($learner, $inviter);

        if ($existing === null) {
            $this->telegram->sendMessage(
                $chatId,
                __('friends.offer.text', ['name' => $name], $locale),
                replyMarkup: ['inline_keyboard' => [[
                    ['text' => __($afterOnboarding ? 'friends.offer.add' : 'friends.offer.send', [], $locale), 'callback_data' => self::PREFIX.':snd:'.$code],
                    ['text' => __('friends.offer.skip', [], $locale), 'callback_data' => self::PREFIX.':no:'.$code],
                ]]],
            );

            return;
        }

        if ($existing->status === FriendshipStatus::Accepted->value) {
            $this->telegram->sendMessage($chatId, __('friends.already', ['name' => $name], $locale));
        } elseif ($existing->status !== FriendshipStatus::Pending->value) {
            // A block (either way) is never explained.
            $this->telegram->sendMessage($chatId, $this->errorText(FriendshipException::UNAVAILABLE, $locale));
        } elseif ($existing->requested_by_learner_id === $learner->id) {
            $this->telegram->sendMessage($chatId, __('friends.already_sent', ['name' => $name], $locale));
        } else {
            // The link owner has already asked this learner: the pending request is answered right here.
            $this->telegram->sendMessage($chatId, __('friends.incoming.text', ['name' => $name], $locale), replyMarkup: $this->answerKeyboard($existing->id, $locale));
        }
    }

    /** The end of the onboarding: offers the friendship of the remembered `f_<code>` link, once. */
    public function offerPending(Learner $learner, int|string $chatId): void
    {
        $code = $this->onboarding->pendingFriendInvite($learner);
        if ($code === null) {
            return;
        }

        $this->offer($learner, $chatId, $code, afterOnboarding: true);
        $this->onboarding->clearPendingFriendInvite($learner);
    }

    /** "Send": `request(learner → owner of the code)`; the owner is told and answers it himself. */
    public function sendRequest(Learner $learner, int|string $chatId, int $messageId, string $code): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);
        $locale = $this->locales->forLearner($learner);
        $inviter = $this->friendships->ownerOfCode($code);
        if ($inviter === null) {
            $this->telegram->sendMessage($chatId, __('friends.errors.invalid_link', [], $locale));

            return;
        }

        try {
            $friendship = $this->friendships->request($learner, $inviter);
        } catch (FriendshipException $e) {
            $this->telegram->sendMessage($chatId, $this->errorText($e->errorCode, $locale));

            return;
        }

        $name = $this->nameOf($inviter, $locale);
        $text = match (true) {
            $friendship->status === FriendshipStatus::Accepted->value => __('friends.accepted', ['name' => $name], $locale),
            $friendship->status === FriendshipStatus::Pending->value && $friendship->requested_by_learner_id === $learner->id => __('friends.request_sent', ['name' => $name], $locale),
            default => $this->errorText(FriendshipException::UNAVAILABLE, $locale),
        };
        $this->telegram->sendMessage($chatId, $text);
    }

    public function skipRequest(Learner $learner, int|string $chatId, int $messageId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);
        $this->telegram->sendMessage($chatId, __('friends.skipped', [], $this->locales->forLearner($learner)));
    }

    public function acceptFriend(Learner $learner, int|string $chatId, int $messageId, int $friendshipId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);
        $locale = $this->locales->forLearner($learner);

        try {
            $friendship = $this->friendships->accept($learner, $friendshipId);
        } catch (FriendshipException $e) {
            $this->telegram->sendMessage($chatId, $this->errorText($e->errorCode, $locale));

            return;
        }

        $other = Learner::query()->find($friendship->otherId($learner->id));
        $this->telegram->sendMessage($chatId, __('friends.accepted', ['name' => $other instanceof Learner ? $this->nameOf($other, $locale) : __('friends.anonymous', [], $locale)], $locale));
    }

    public function declineFriend(Learner $learner, int|string $chatId, int $messageId, int $friendshipId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);
        $locale = $this->locales->forLearner($learner);

        try {
            $this->friendships->decline($learner, $friendshipId);
        } catch (FriendshipException $e) {
            $this->telegram->sendMessage($chatId, $this->errorText($e->errorCode, $locale));

            return;
        }

        $this->telegram->sendMessage($chatId, __('friends.declined', [], $locale));
    }

    public function acceptChallenge(Learner $learner, int|string $chatId, int $messageId, int $challengeId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);
        $locale = $this->locales->forLearner($learner);

        try {
            $challenge = $this->challenges->accept($learner, $challengeId);
        } catch (ChallengeException $e) {
            $this->telegram->sendMessage($chatId, $this->challengeErrorText($e->errorCode, $locale));

            return;
        }

        $this->telegram->sendMessage($chatId, __('friends.challenge.accepted', [
            'starts' => $challenge->starts_on?->format('d.m') ?? '',
            'ends' => $challenge->ends_on?->format('d.m') ?? '',
        ], $locale));
    }

    public function declineChallenge(Learner $learner, int|string $chatId, int $messageId, int $challengeId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);
        $locale = $this->locales->forLearner($learner);

        try {
            $this->challenges->decline($learner, $challengeId);
        } catch (ChallengeException $e) {
            $this->telegram->sendMessage($chatId, $this->challengeErrorText($e->errorCode, $locale));

            return;
        }

        $this->telegram->sendMessage($chatId, __('friends.challenge.declined', [], $locale));
    }

    /**
     * Background DM (SendFriendDmJob). The row is read again: a state that no longer holds (declined in the
     * meantime, friendship ended) is not announced. A recipient who is blocked or closed the DM gets nothing;
     * a Telegram 403 closes the DM (`dm_blocked_at`, as with reminders) and ends the job.
     */
    public function notify(FriendDmKind $kind, int $id, ?string $side = null): void
    {
        match ($kind) {
            FriendDmKind::FriendRequested => $this->notifyFriendRequested($id),
            FriendDmKind::FriendAccepted => $this->notifyFriendAccepted($id),
            FriendDmKind::ChallengeProposed => $this->notifyChallengeProposed($id),
            FriendDmKind::ChallengeAccepted => $this->notifyChallengeAccepted($id),
            FriendDmKind::ChallengeFinished => $this->notifyChallengeFinished($id, $side),
        };
    }

    private function notifyFriendRequested(int $friendshipId): void
    {
        /** @var Friendship|null $friendship */
        $friendship = Friendship::query()->find($friendshipId);
        if ($friendship === null || $friendship->status !== FriendshipStatus::Pending->value) {
            return;
        }

        $recipient = $this->reachable($friendship->otherId($friendship->requested_by_learner_id));
        $requester = Learner::query()->find($friendship->requested_by_learner_id);
        if ($recipient === null || ! $requester instanceof Learner) {
            return;
        }

        $locale = $this->locales->forLearner($recipient);
        $this->deliver($recipient, FriendDmKind::FriendRequested, $friendship->id, null, fn () => $this->telegram->sendMessage(
            $recipient->telegram_user_id,
            __('friends.incoming.text', ['name' => $this->nameOf($requester, $locale)], $locale),
            replyMarkup: $this->answerKeyboard($friendship->id, $locale),
        ));
    }

    private function notifyFriendAccepted(int $friendshipId): void
    {
        /** @var Friendship|null $friendship */
        $friendship = Friendship::query()->find($friendshipId);
        if ($friendship === null || $friendship->status !== FriendshipStatus::Accepted->value) {
            return;
        }

        $recipient = $this->reachable($friendship->requested_by_learner_id);
        $accepter = Learner::query()->find($friendship->otherId($friendship->requested_by_learner_id));
        if ($recipient === null || ! $accepter instanceof Learner) {
            return;
        }

        $locale = $this->locales->forLearner($recipient);
        $this->deliver($recipient, FriendDmKind::FriendAccepted, $friendship->id, null, fn () => $this->telegram->sendMessage(
            $recipient->telegram_user_id,
            __('friends.dm.friend_accepted', ['name' => $this->nameOf($accepter, $locale)], $locale),
        ));
    }

    private function notifyChallengeProposed(int $challengeId): void
    {
        $challenge = $this->challenge($challengeId, ChallengeStatus::Pending);
        $recipient = $challenge === null ? null : $this->reachable($challenge->opponent_id);
        $challenger = $challenge === null ? null : Learner::query()->find($challenge->challenger_id);
        if ($challenge === null || $recipient === null || ! $challenger instanceof Learner) {
            return;
        }

        $locale = $this->locales->forLearner($recipient);
        $this->deliver($recipient, FriendDmKind::ChallengeProposed, $challenge->id, null, fn () => $this->telegram->sendMessage(
            $recipient->telegram_user_id,
            __('friends.dm.challenge_proposed', [
                'name' => $this->nameOf($challenger, $locale),
                'metric' => __('friends.metric.'.$challenge->metric, [], $locale),
                'days' => $challenge->days,
            ], $locale),
            replyMarkup: ['inline_keyboard' => [[
                ['text' => __('friends.challenge.accept', [], $locale), 'callback_data' => self::PREFIX.':cacc:'.$challenge->id],
                ['text' => __('friends.challenge.decline', [], $locale), 'callback_data' => self::PREFIX.':cdec:'.$challenge->id],
            ]]],
        ));
    }

    private function notifyChallengeAccepted(int $challengeId): void
    {
        $challenge = $this->challenge($challengeId, ChallengeStatus::Active);
        $recipient = $challenge === null ? null : $this->reachable($challenge->challenger_id);
        $opponent = $challenge === null ? null : Learner::query()->find($challenge->opponent_id);
        if ($challenge === null || $recipient === null || ! $opponent instanceof Learner) {
            return;
        }

        $locale = $this->locales->forLearner($recipient);
        $this->deliver($recipient, FriendDmKind::ChallengeAccepted, $challenge->id, null, fn () => $this->telegram->sendMessage(
            $recipient->telegram_user_id,
            __('friends.dm.challenge_accepted', [
                'name' => $this->nameOf($opponent, $locale),
                'starts' => $challenge->starts_on?->format('d.m') ?? '',
                'ends' => $challenge->ends_on?->format('d.m') ?? '',
            ], $locale),
        ));
    }

    private function notifyChallengeFinished(int $challengeId, ?string $side): void
    {
        $challenge = $this->challenge($challengeId, ChallengeStatus::Finished);
        if ($challenge === null || ! in_array($side, ['challenger', 'opponent'], true)) {
            return;
        }

        $isChallenger = $side === 'challenger';
        $recipientId = $isChallenger ? $challenge->challenger_id : $challenge->opponent_id;
        $otherId = $isChallenger ? $challenge->opponent_id : $challenge->challenger_id;
        $recipient = $this->reachable($recipientId);
        $other = Learner::query()->find($otherId);
        if ($recipient === null || ! $other instanceof Learner) {
            return;
        }

        $scores = $challenge->scores ?? [];
        $challengerScore = (int) ($scores['challenger'] ?? 0);
        $opponentScore = (int) ($scores['opponent'] ?? 0);
        $key = match (true) {
            $challenge->winner_learner_id === null => 'challenge_draw',
            $challenge->winner_learner_id === $recipientId => 'challenge_won',
            default => 'challenge_lost',
        };

        $locale = $this->locales->forLearner($recipient);
        $this->deliver($recipient, FriendDmKind::ChallengeFinished, $challenge->id, $side, fn () => $this->telegram->sendMessage(
            $recipient->telegram_user_id,
            __('friends.dm.'.$key, [
                'name' => $this->nameOf($other, $locale),
                'mine' => $isChallenger ? $challengerScore : $opponentScore,
                'theirs' => $isChallenger ? $opponentScore : $challengerScore,
            ], $locale),
        ));
    }

    private function challenge(int $id, ChallengeStatus $status): ?Challenge
    {
        /** @var Challenge|null $challenge */
        $challenge = Challenge::query()->find($id);

        return $challenge !== null && $challenge->status === $status->value ? $challenge : null;
    }

    /** The learner if a DM can reach them: exists, not blocked, DM not closed. */
    private function reachable(int $learnerId): ?Learner
    {
        $learner = Learner::query()->find($learnerId);

        return $learner instanceof Learner && $learner->blocked_at === null && $learner->dm_blocked_at === null ? $learner : null;
    }

    /**
     * At most once per (kind, id, side): a `notifications_log` row of type `friend_dm` is written BEFORE the
     * Telegram call (as {@see LeagueResultSender} does), so a job retried after the message was in fact
     * delivered (timeout, a worker killed after the send) only skips. A 403 deletes the row — nothing was
     * delivered — and means the person cannot be reached: they leave the DM selection (as with reminders)
     * and the job ends.
     */
    private function deliver(Learner $learner, FriendDmKind $kind, int $id, ?string $side, callable $send): void
    {
        $key = $kind->value.':'.$id.':'.($side ?? '-');

        $sent = NotificationLog::query()
            ->where('learner_id', $learner->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('payload->key', $key)
            ->exists();
        if ($sent) {
            return;
        }

        $log = NotificationLog::query()->create([
            'learner_id' => $learner->id,
            'type' => self::NOTIFICATION_TYPE,
            'payload' => ['key' => $key],
            'sent_at' => now(),
        ]);

        try {
            $send();
        } catch (TelegramForbiddenException $e) {
            $log->delete();
            $this->dm->markBlocked($learner->telegram_user_id);

            Log::warning('telegram.friends_dm_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);
        }
    }

    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    private function answerKeyboard(int $friendshipId, string $locale): array
    {
        return ['inline_keyboard' => [[
            ['text' => __('friends.incoming.accept', [], $locale), 'callback_data' => self::PREFIX.':acc:'.$friendshipId],
            ['text' => __('friends.incoming.decline', [], $locale), 'callback_data' => self::PREFIX.':dec:'.$friendshipId],
        ]]];
    }

    private function nameOf(Learner $learner, string $locale): string
    {
        $name = trim((string) $learner->first_name);

        return $name !== '' ? $name : __('friends.anonymous', [], $locale);
    }

    private function errorText(string $code, string $locale): string
    {
        $key = 'friends.errors.'.$code;

        return __(Lang::has($key, $locale) ? $key : 'friends.errors.generic', [], $locale);
    }

    private function challengeErrorText(string $code, string $locale): string
    {
        $key = 'friends.challenge.errors.'.$code;

        return __(Lang::has($key, $locale) ? $key : 'friends.challenge.errors.generic', [], $locale);
    }
}
