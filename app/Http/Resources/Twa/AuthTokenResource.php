<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Models\Student;
use Illuminate\Http\Request;

/**
 * POST /api/twa/auth
 *
 * @property array{jwt: array{token: string, expires_in: int, expires_at: int}, student: Student} $resource
 */
final class AuthTokenResource extends TwaResource
{
    /**
     * @return array{
     *   token: string,
     *   expires_in: int,
     *   student: array{id: int, first_name: string|null, username: string|null, telegram_group_id: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $student = $this->resource['student'];

        return [
            'token' => $this->resource['jwt']['token'],
            'expires_in' => $this->resource['jwt']['expires_in'],
            'student' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'username' => $student->username,
                'telegram_group_id' => (int) $student->telegram_group_id,
            ],
        ];
    }
}
