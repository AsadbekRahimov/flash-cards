<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\PreparedShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/twa/share/prepare → `{id, expires_at}` (docs/04 A.22): the id for `WebApp.shareMessage` and when Telegram forgets it
 * (ISO 8601). No `data` envelope.
 *
 * @property PreparedShare $resource
 */
final class ShareMessageResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array{id: string, expires_at: string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'expires_at' => $this->resource->expiresAt->toIso8601String(),
        ];
    }
}
