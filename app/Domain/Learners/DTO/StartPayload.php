<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Enums\StartPayloadKind;

/**
 * A parsed and validated `/start` deep-link parameter (docs/12 §7.2, docs/13 §7.1).
 *
 * Telegram allows up to 64 characters of `[A-Za-z0-9_-]`; on top of that each prefix has its own
 * shape. Anything that does not fit — too long, foreign characters, unknown prefix, malformed
 * value — parses to {@see StartPayloadKind::None}: the person simply gets the ordinary
 * onboarding and nothing is recorded, so a hand-crafted link can never write junk into
 * `acquisition_source` / `start_payload`.
 *
 *   ref_<code>        — invited by a learner: their `learners.referral_code`, `[A-Z0-9]{6,12}`
 *                       (the numeric `ref_<learner id>` of Phase 1 is gone — ids are guessable)
 *   src_<channel>     — campaign / blogger, `[a-z0-9_]{1,32}`
 *   c_<slug>          — course link, a lower-case course slug
 *   org_<code>        — centre invite (docs/13 §6.2)
 */
final readonly class StartPayload
{
    public const MAX_LENGTH = 64;

    /** `acquisition_source` is varchar(64): `course:` + slug must fit. */
    public const COURSE_SLUG_MAX = 56;

    private function __construct(
        public StartPayloadKind $kind,
        /** The part after the prefix (referral code, channel, slug, invite code); null for None. */
        public ?string $value,
        /** The validated raw payload, as stored in `learners.start_payload`; null for None. */
        public ?string $raw,
    ) {}

    public static function none(): self
    {
        return new self(StartPayloadKind::None, null, null);
    }

    public static function parse(?string $payload): self
    {
        if ($payload === null) {
            return self::none();
        }

        $payload = trim($payload);
        if ($payload === '' || strlen($payload) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $payload) !== 1) {
            return self::none();
        }

        $patterns = [
            [StartPayloadKind::Ref, '/^ref_([A-Z0-9]{6,12})$/'],
            [StartPayloadKind::Src, '/^src_([a-z0-9_]{1,32})$/'],
            [StartPayloadKind::Course, '/^c_([a-z0-9][a-z0-9_-]{0,'.(self::COURSE_SLUG_MAX - 1).'})$/'],
            [StartPayloadKind::Org, '/^org_([A-Za-z0-9_-]{1,32})$/'],
        ];

        foreach ($patterns as [$kind, $pattern]) {
            if (preg_match($pattern, $payload, $m) === 1) {
                return new self($kind, $m[1], $payload);
            }
        }

        return self::none();
    }

    public function isEmpty(): bool
    {
        return $this->kind === StartPayloadKind::None;
    }

    /** Value for `learners.acquisition_source` (docs/12 §1.2); null when there is nothing to attribute. */
    public function acquisitionSource(): ?string
    {
        return match ($this->kind) {
            StartPayloadKind::None => null,
            StartPayloadKind::Ref => 'ref',
            StartPayloadKind::Src => 'src:'.$this->value,
            StartPayloadKind::Course => 'course:'.$this->value,
            StartPayloadKind::Org => 'org',
        };
    }

    /** The inviter's `referral_code` of a `ref_<code>` link. */
    public function referralCode(): ?string
    {
        return $this->kind === StartPayloadKind::Ref ? $this->value : null;
    }

    public function courseSlug(): ?string
    {
        return $this->kind === StartPayloadKind::Course ? $this->value : null;
    }
}
