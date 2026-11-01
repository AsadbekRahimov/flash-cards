<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one error envelope of the API: `{"error": {"code": "...", "message": "..."}}`, plus `details` (an object) for
 * the codes whose client needs fields (docs/16 §7.6: `practice_not_enough_material`, `practice_session_active`,
 * `answer_already_accepted`...). The key `details` is there ONLY when there are details: the other errors keep
 * their shape. Build responses with {@see self::respond()}.
 *
 * @property array{code: string, message: string, details?: array<string, mixed>} $resource
 */
final class ApiErrorResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{error: array{code: string, message: string, details?: array<string, mixed>}}
     */
    public function toArray(Request $request): array
    {
        $error = [
            'code' => $this->resource['code'],
            'message' => $this->resource['message'],
        ];

        if (($this->resource['details'] ?? []) !== []) {
            $error['details'] = $this->resource['details'];
        }

        return ['error' => $error];
    }

    /**
     * @param  array<string, mixed>  $details  put in `error.details` when not empty
     */
    public static function respond(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return (new self(['code' => $code, 'message' => $message, 'details' => $details]))
            ->response()
            ->setStatusCode($status);
    }
}
