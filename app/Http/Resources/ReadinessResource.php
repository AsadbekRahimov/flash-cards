<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Operations\DTO\ReadinessReport;
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
     * @return array{status: string, checks: array<string, string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource->ok() ? ReadinessReport::OK : ReadinessReport::FAIL,
            'checks' => $this->resource->checks,
        ];
    }
}
