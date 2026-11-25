<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * Which table of a period the viewer asks for (docs/35 §2.4, R7; owner's decision docs/31 §5г - any region may be opened):
 *
 *   all     the whole category (`scope=all`, the default);
 *   own     the viewer's region (`scope=region` without `region=`): of the month - the region the month was fixed in (else the current
 *           one); of "all time" - the current one; none → 422 `region_not_set`;
 *   a code  the table of that region (`scope=region&region=<code>`), read-only unless it is the viewer's own.
 *
 * The code is validated by the request (`Regions::isValid`) before it gets here; it is only ever bound to a query.
 */
final readonly class RegionChoice
{
    private function __construct(
        public bool $region,
        public ?string $code,
    ) {}

    public static function all(): self
    {
        return new self(false, null);
    }

    public static function own(): self
    {
        return new self(true, null);
    }

    public static function of(string $code): self
    {
        return new self(true, $code);
    }

    /** `all` / `region` - the scope as the API names it. */
    public function scope(): string
    {
        return $this->region ? 'region' : 'all';
    }
}
