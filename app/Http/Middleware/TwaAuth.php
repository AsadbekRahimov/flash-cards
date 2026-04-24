<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Twa\Services\JwtService;
use App\Models\Student;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates TWA API requests by verifying the Bearer JWT and
 * loading the corresponding student into the request.
 *
 * Sets:
 *   $request->attributes->get('twa') = ['student_id' => int, 'group_id' => int]
 *   $request->attributes->get('student') = Student
 */
final class TwaAuth
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('missing_token');
        }

        $token = substr($header, 7);
        if ($token === '') {
            return $this->unauthorized('missing_token');
        }

        try {
            $payload = $this->jwt->verify($token);
        } catch (Throwable) {
            return $this->unauthorized('invalid_token');
        }

        $student = Student::query()->find($payload['student_id']);
        if ($student === null || ! $student->is_active) {
            return $this->unauthorized('student_not_found');
        }

        if ((int) $student->telegram_group_id !== $payload['group_id']) {
            return $this->unauthorized('group_mismatch');
        }

        $request->attributes->set('twa', [
            'student_id' => $payload['student_id'],
            'group_id' => $payload['group_id'],
        ]);
        $request->attributes->set('student', $student);

        return $next($request);
    }

    private function unauthorized(string $code): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => 'Unauthorized'],
        ], 401);
    }
}
