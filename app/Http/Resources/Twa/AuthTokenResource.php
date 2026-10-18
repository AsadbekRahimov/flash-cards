<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * POST /api/twa/auth
 *
 * @property array{jwt: array{token: string, expires_in: int, expires_at: int}, learner: Learner} $resource
 */
final class AuthTokenResource extends TwaResource
{
    /**
     * @return array{
     *   token: string,
     *   expires_in: int,
     *   learner: array{id: int, first_name: string|null, locale: string, onboarding_completed: bool}
     * }
     */
    public function toArray(Request $request): array
    {
        $learner = $this->resource['learner'];

        return [
            'token' => $this->resource['jwt']['token'],
            'expires_in' => $this->resource['jwt']['expires_in'],
            'learner' => [
                'id' => $learner->id,
                'first_name' => $learner->first_name,
                'locale' => $learner->interface_locale,
                'onboarding_completed' => $learner->onboarding_state === 'done',
            ],
        ];
    }
}
