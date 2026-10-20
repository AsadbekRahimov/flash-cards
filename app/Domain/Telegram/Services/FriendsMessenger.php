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
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * Everything the bot says about friends and challenges (docs/13 §7.5): the "X invites you to be friends"
 * question of an `f_<code>` / `ref_<code>` link, the answers to its buttons, and the background DMs
 * (a friend accepted, a challenge proposed / accepted / finished). Texts: `lang/{ru,uz}/friends.php`, in the
 * recipient's language. The rules are {@see FriendshipService} / {@see ChallengeService}; this class only talks
 * to Telegram.
 *
 * `callback_data` (≤ 64 bytes; the prefix carries the format version, as with `org1` / `onb1`):
 *   fr1:acc:<id> / fr1:dec:<id>    — friend invite (`<id>` = friendships.id): accept / decline;
 *   fr1:cacc:<id> / fr1:cdec:<id>  — challenge (`<id>` = challenges.id): accept / decline.
 * Nothing in it is trusted: the services look the id up among the sender's OWN rows only.
 *
 * The invite link is the INVITER's request: opening it makes `request(inviter → opener)`, a pending row the
 * opener answers here or in the app; a repeated `/start` finds the same row.
 */
final class FriendsMessenger
{
    public const PREFIX = 'fr1';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly FriendshipService $friendships,
        private readonly ChallengeService $challenges,
        private readonly OnboardingMessenger $onboardingMessenger,
        private readonly DmLearnerResolver $dm,
    ) {}

    /** `/start f_<code>` of a learner who has finished the onboarding: asks "X invites you — accept?". */
    public function offer(Learner $learner, int|string $chatId, string $code): void
    {
        $locale = $this->locales->forLearner($learner);
        $inviter = $this->friendships->ownerOfCode($code);
        if ($inviter === null) {
            $this->telegram->sendMessage($chatId, __('friends.errors.invalid_link', [], $locale));

            return;
        }

        try {
            $friendship = $this->friendships->request($inviter, $learner);
        } catch (FriendshipException $e) {
            $this->telegram->sendMessage($chatId, $this->errorText($e->errorCode, $locale));

            return;
        }

        $name = $this->nameOf($inviter, $locale);

        if ($friendship->status === FriendshipStatus::Accepted->value) {
            $this->telegram->sendMessage($chatId, __('friends.already', ['name' => $name], $locale));

            return;
        }

        if ($friendship->status !== FriendshipStatus::Pending->value || $friendship->requested_by_learner_id !== $inviter->id) {
            // A block (either way) is never explained.
            $this->telegram->sendMessage($chatId, $this->errorText(FriendshipException::UNAVAILABLE, $locale));

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('friends.offer.text', ['name' => $name], $locale),
            replyMarkup: ['inline_keyboard' => [[
                ['text' => __('friends.offer.accept', [], $locale), 'callback_data' => self::PREFIX.':acc:'.$friendship->id],
                ['text' => __('friends.offer.decline', [], $locale), 'callback_data' => self::PREFIX.':dec:'.$friendship->id],
            ]]],
        );
    }

    /**
     * The same link for somebody who has not finished the onboarding: the request is recorded silently (it
     * waits in the app's list of requests), the onboarding goes on undisturbed.
     */
    public function rememberInvite(Learner $learner, string $code): void
    {
        $inviter = $this->friendships->ownerOfCode($code);
        if ($inviter === null) {
            return;
        }

        try {
            $this->friendships->request($inviter, $learner);
        } catch (FriendshipException) {
            // Own code, blocked, over the limit: nothing to remember.
        }
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
            FriendDmKind::FriendAccepted => $this->notifyFriendAccepted($id),
            FriendDmKind::ChallengeProposed => $this->notifyChallengeProposed($id),
            FriendDmKind::ChallengeAccepted => $this->notifyChallengeAccepted($id),
            FriendDmKind::ChallengeFinished => $this->notifyChallengeFinished($id, $side),
        };
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
        $this->deliver($recipient, fn () => $this->telegram->sendMessage(
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
        $this->deliver($recipient, fn () => $this->telegram->sendMessage(
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
        $this->deliver($recipient, fn () => $this->telegram->sendMessage(
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
        $this->deliver($recipient, fn () => $this->telegram->sendMessage(
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

    /** A 403 means the person cannot be reached: they leave the DM selection (as with reminders) and the job ends. */
    private function deliver(Learner $learner, callable $send): void
    {
        try {
            $send();
        } catch (TelegramForbiddenException $e) {
            $this->dm->markBlocked($learner->telegram_user_id);

            Log::warning('telegram.friends_dm_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);
        }
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
