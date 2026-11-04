<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\GameLimitState;
use App\Domain\Game\DTO\RunView;
use Illuminate\Http\Request;

/**
 * The 201 of POST /game/runs (docs/04 A.18): the run exactly as {@see GameRunResource} gives it, plus `limits` - the Free limits and the
 * tickets AFTER this start, so the screen can say what is left without another request. A GET and the `run` of an answer stay without
 * the key: they are not creations.
 *
 * @property RunView $resource
 *
 * @phpstan-import-type LimitsBlock from GameLimitState
 */
final class GameRunStartedResource extends TwaResource
{
    /** @param LimitsBlock|null $limits */
    public function __construct(RunView $view, private readonly ?array $limits)
    {
        parent::__construct($view);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...(new GameRunResource($this->resource))->toArray($request), 'limits' => $this->limits];
    }
}
