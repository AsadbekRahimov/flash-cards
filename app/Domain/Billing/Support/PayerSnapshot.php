<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;

/**
 * Who paid, as they were at the time (docs/03 §2.27, §2.29; docs/07 §10): the `payer_*` columns of `payment_orders`
 * and `payments`. `subscriber_id` keeps pointing at a learner that is deleted for good (docs/03 §8 rule 2), and this
 * snapshot is then the only thing that still says who it was — for the admin screens and for a Stars refund
 * (`refundStarPayment` needs the Telegram user id).
 *
 * A learner: Telegram id, "first last", username. An organization: its name (no Telegram id). The ledger copies the
 * live row when it still exists ({@see of()}) and otherwise falls back to the snapshot of the order / refunded charge
 * ({@see or()}), so a renewal or a refund after the deletion still carries the payer.
 */
final readonly class PayerSnapshot
{
    public function __construct(
        public ?int $telegramUserId,
        public ?string $name,
        public ?string $username,
    ) {}

    public static function empty(): self
    {
        return new self(null, null, null);
    }

    public static function of(Learner|Organization|null $subject): self
    {
        return match (true) {
            $subject instanceof Learner => self::fromLearner($subject),
            $subject instanceof Organization => new self(null, self::clip($subject->name, 201), null),
            default => self::empty(),
        };
    }

    public static function fromLearner(Learner $learner): self
    {
        $name = trim(($learner->first_name ?? '').' '.($learner->last_name ?? ''));

        return new self(
            $learner->telegram_user_id > 0 ? $learner->telegram_user_id : null,
            $name === '' ? null : self::clip($name, 201),
            $learner->username === null || $learner->username === '' ? null : self::clip($learner->username, 100),
        );
    }

    /** The snapshot a ledger row / order already carries. */
    public static function fromRecord(Payment|PaymentOrder $record): self
    {
        return new self($record->payer_telegram_user_id, $record->payer_name, $record->payer_username);
    }

    public function isEmpty(): bool
    {
        return $this->telegramUserId === null && $this->name === null && $this->username === null;
    }

    /** This snapshot when it says anything, else the fallback (the order's, the refunded charge's). */
    public function or(self $fallback): self
    {
        return $this->isEmpty() ? $fallback : $this;
    }

    /** @return array{payer_telegram_user_id: int|null, payer_name: string|null, payer_username: string|null} */
    public function toColumns(): array
    {
        return [
            'payer_telegram_user_id' => $this->telegramUserId,
            'payer_name' => $this->name,
            'payer_username' => $this->username,
        ];
    }

    /** "First Last @username · tg:123456" — what the admin sees when the subscriber row is gone; null when empty. */
    public function label(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $parts = array_filter([
            trim(($this->name ?? '').($this->username !== null ? ' @'.$this->username : '')),
            $this->telegramUserId !== null ? 'tg:'.$this->telegramUserId : '',
        ], static fn (string $part): bool => $part !== '');

        return implode(' · ', $parts);
    }

    private static function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
