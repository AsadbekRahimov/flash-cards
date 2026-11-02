<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\BattleView;
use App\Domain\Practice\Services\PracticeLimits;
use Illuminate\Http\Request;

/**
 * The 201 of POST /practice/battles (docs/16 §7.3, docs/18 G1): the battle exactly as {@see BattleResource} gives it, plus
 * `limits` - the Free limits of the day AFTER this call (`null`: none applies). The other answers of a battle stay without the
 * key: accepting is free and nothing else creates.
 *
 * @property BattleView $resource
 *
 * @phpstan-import-type LimitsBlock from PracticeLimits
 */
final class BattleInvitedResource extends TwaResource
{
    /** @param LimitsBlock|null $limits */
    public function __construct(BattleView $view, private readonly ?array $limits)
    {
        parent::__construct($view);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...(new BattleResource($this->resource))->toArray($request), 'limits' => $this->limits];
    }
}
