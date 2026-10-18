<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one error envelope of the API: `{"error": {"code": "...", "message": "..."}}`.
 * Build responses with {@see self::respond()}.
 *
 * @property array{code: string, message: string} $resource
 */
final class ApiErrorResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{error: array{code: string, message: string}}
     */
    public function toArray(Request $request): array
    {
        return [
            'error' => [
                'code' => $this->resource['code'],
                'message' => $this->resource['message'],
            ],
        ];
    }

    public static function respond(int $status, string $code, string $message): JsonResponse
    {
        return (new self(['code' => $code, 'message' => $message]))
            ->response()
            ->setStatusCode($status);
    }
}
