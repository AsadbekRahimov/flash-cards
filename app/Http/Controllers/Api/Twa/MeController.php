<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Services\StudentStatsService;
use App\Http\Resources\Twa\MeResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(private readonly StudentStatsService $stats) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->attributes->get('student');

        $student->loadMissing('group');

        return (new MeResource([
            'student' => $student,
            'stats' => $this->stats->forStudent($student),
        ]))->response();
    }
}
