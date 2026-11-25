<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Models\AuditLog;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What an administrator of the PLATFORM does to cups in `/admin` (docs/33 §8, K9): cancel a cup (with a reason), reset an offensive
 * name in a tournament, forbid cups in a group (`telegram_groups.meta.cups_blocked` - an open cup is paused, docs/33 §3.1), put a group
 * into the pilot (`meta.cup_pilot`, read when `CUP_ACCESS=pilot`).
 *
 * The panel checks the role (`CupPolicy`) on every action; this service checks it AGAIN - a Livewire call that went around the button
 * finds the same door. One `audit_logs` record per real change, written with it; a repeated click changes nothing and writes nothing.
 * The cancel and the name are the core's ({@see CupLifecycleService::cancelByPlatform()},
 * {@see CupParticipationService::resetDisplayName()}); the flags of the group are written here under the lock of its row, so two
 * clicks cannot both log a change and a concurrent edit of another key of `meta` is not lost.
 */
final class CupPlatformAdmin
{
    public const ACTION_CANCELED = 'cup.canceled';

    public const ACTION_RENAMED = 'cup.participant_renamed';

    public const ACTION_BLOCKED = 'cup.group_blocked';

    public const ACTION_UNBLOCKED = 'cup.group_unblocked';

    public const ACTION_PILOT_ON = 'cup.group_pilot_on';

    public const ACTION_PILOT_OFF = 'cup.group_pilot_off';

    public const REASON_MAX = 500;

    public function __construct(
        private readonly CupLifecycleService $lifecycle,
        private readonly CupParticipationService $participation,
    ) {}

    public static function isAdmin(?User $user): bool
    {
        return $user instanceof User && $user->is_active === true && $user->hasRole('admin');
    }

    /**
     * @return bool whether this call canceled the cup (false: it was over already)
     *
     * @throws AuthorizationException not an active administrator
     * @throws InvalidArgumentException an empty or too long reason
     */
    public function cancel(Cup $cup, User $actor, string $reason, ?string $ip = null): bool
    {
        $this->authorize($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException('The reason of the cancel is required (1-'.self::REASON_MAX.' characters).');
        }

        $from = $cup->status->value;
        if (! $this->lifecycle->cancelByPlatform($cup)) {
            return false;
        }

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => self::ACTION_CANCELED,
            'entity_type' => Cup::class,
            'entity_id' => $cup->id,
            'ip' => $ip,
            'meta' => ['reason' => $reason, 'from' => $from, 'telegram_group_id' => $cup->telegram_group_id],
        ]);

        return true;
    }

    /**
     * Back to the Telegram first name; no rename of the participant is spent.
     *
     * @return bool whether the name changed
     *
     * @throws AuthorizationException not an active administrator
     */
    public function resetName(CupParticipant $participant, User $actor, ?string $ip = null): bool
    {
        $this->authorize($actor);
        $from = $participant->display_name;
        $reset = $this->participation->resetDisplayName($participant);
        if ($reset->display_name === $from) {
            return false;
        }

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => self::ACTION_RENAMED,
            'entity_type' => CupParticipant::class,
            'entity_id' => $participant->id,
            'ip' => $ip,
            'meta' => ['cup_id' => $participant->cup_id, 'from' => $from, 'to' => $reset->display_name],
        ]);

        return true;
    }

    /**
     * @return bool whether the flag changed
     *
     * @throws AuthorizationException not an active administrator
     */
    public function setCupsBlocked(TelegramGroup $group, bool $blocked, User $actor, ?string $ip = null): bool
    {
        return $this->setFlag($group, 'cups_blocked', $blocked, $blocked ? self::ACTION_BLOCKED : self::ACTION_UNBLOCKED, $actor, $ip);
    }

    /**
     * @return bool whether the flag changed
     *
     * @throws AuthorizationException not an active administrator
     */
    public function setPilot(TelegramGroup $group, bool $pilot, User $actor, ?string $ip = null): bool
    {
        return $this->setFlag($group, 'cup_pilot', $pilot, $pilot ? self::ACTION_PILOT_ON : self::ACTION_PILOT_OFF, $actor, $ip);
    }

    /** @throws AuthorizationException */
    private function setFlag(TelegramGroup $group, string $key, bool $on, string $action, User $actor, ?string $ip): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($group, $key, $on, $action, $actor, $ip): bool {
            /** @var TelegramGroup|null $current */
            $current = TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->first();
            if ($current === null) {
                return false;
            }
            $meta = $current->meta;
            if ((($meta[$key] ?? false) === true) === $on) {
                $group->setRawAttributes($current->getAttributes(), true);

                return false;
            }

            // Off is the absence of the key: `CupAccess` reads `=== true`, and the form of the group shows fewer service keys.
            if ($on) {
                $meta[$key] = true;
            } else {
                unset($meta[$key]);
            }
            $current->forceFill(['meta' => $meta])->save();

            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => $action,
                'entity_type' => TelegramGroup::class,
                'entity_id' => $current->id,
                'ip' => $ip,
                'meta' => ['key' => $key, 'value' => $on],
            ]);
            $group->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /** @throws AuthorizationException */
    private function authorize(User $actor): void
    {
        if (! self::isAdmin($actor)) {
            throw new AuthorizationException('Only an active administrator of the platform manages cups.');
        }
    }
}
