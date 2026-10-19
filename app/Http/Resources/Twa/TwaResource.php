<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for TWA API payloads. The TWA client (resources/twa/src/api/types.ts)
 * expects the bare object, so the global `data` envelope is switched off
 * for this family only.
 */
abstract class TwaResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;
}
