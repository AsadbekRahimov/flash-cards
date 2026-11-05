<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Operations\DTO\ReadinessReport;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ReadinessReport $resource
 */
final class ReadinessResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{status: string, surface: string, checks: array<string, string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource->status(),
            // Which php-fpm container answered: `full` (app) or `api` (app-api), docs/DEVOPS_GUIDE.md §0.
            'surface' => Surface::current(),
            'checks' => $this->resource->checks,
        ];
    }
}
