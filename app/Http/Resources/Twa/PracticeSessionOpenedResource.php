<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\SessionView;
use App\Domain\Practice\Services\PracticeLimits;
use Illuminate\Http\Request;

/**
 * The 201 of POST /practice/sessions (docs/16 §7.2, docs/18 G1): the session exactly as {@see PracticeSessionResource} gives it,
 * plus `limits` - the Free limits of the day AFTER this opening (`null`: none applies), so the screen can say how many are left
 * without another request. A GET and the `session` of an answer stay without the key: they are not creations.
 *
 * @property SessionView $resource
 *
 * @phpstan-import-type LimitsBlock from PracticeLimits
 */
final class PracticeSessionOpenedResource extends TwaResource
{
    /** @param LimitsBlock|null $limits */
    public function __construct(SessionView $view, private readonly ?array $limits)
    {
        parent::__construct($view);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...(new PracticeSessionResource($this->resource))->toArray($request), 'limits' => $this->limits];
    }
}
