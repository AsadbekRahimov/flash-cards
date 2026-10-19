<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Enums\StartPayloadKind;

/**
 * A parsed and validated `/start` deep-link parameter (docs/12 §7.2).
 *
 * Telegram allows up to 64 characters of `[A-Za-z0-9_-]`; on top of that each prefix has its own
 * shape. Anything that does not fit — too long, foreign characters, unknown prefix, malformed
 * value — parses to {@see StartPayloadKind::None}: the person simply gets the ordinary
 * onboarding and nothing is recorded, so a hand-crafted link can never write junk into
 * `acquisition_source` / `start_payload`.
 *
 *   ref_<learner id>  — invited by a learner (digits only)
 *   src_<channel>     — campaign / blogger, `[a-z0-9_]{1,32}`
 *   c_<slug>          — course link, a lower-case course slug
 *   org_<code>        — centre invite (Phase 2; only stored in Phase 1)
 */
final readonly class StartPayload
{
    public const MAX_LENGTH = 64;

    /** `acquisition_source` is varchar(64): `course:` + slug must fit. */
    public const COURSE_SLUG_MAX = 56;

    private function __construct(
        public StartPayloadKind $kind,
        /** The part after the prefix (learner id, channel, slug, code); null for None. */
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
            [StartPayloadKind::Ref, '/^ref_([1-9][0-9]{0,14})$/'],
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

    public function referrerId(): ?int
    {
        return $this->kind === StartPayloadKind::Ref ? (int) $this->value : null;
    }

    public function courseSlug(): ?string
    {
        return $this->kind === StartPayloadKind::Course ? $this->value : null;
    }
}
