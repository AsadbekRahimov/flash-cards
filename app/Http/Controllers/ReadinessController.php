<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Operations\Services\ReadinessProbe;
use App\Http\Resources\ReadinessResource;
use Illuminate\Http\JsonResponse;

final class ReadinessController
{
    public function __invoke(ReadinessProbe $probe): JsonResponse
    {
        $report = $probe->run();

        return (new ReadinessResource($report))
            ->response()
            ->setStatusCode($report->ok() ? 200 : 503);
    }
}
