<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Analytics\Services\ClientTimingRecorder;
use App\Domain\Learners\Support\OnboardingChoices;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * POST /api/twa/auth
 *
 * @property array{jwt: array{token: string, expires_in: int, expires_at: int}, learner: Learner, account_restored: array{pro_can_resume: bool}|null} $resource
 */
final class AuthTokenResource extends TwaResource
{
    /**
     * @return array{
     *   token: string,
     *   expires_in: int,
     *   learner: array{id: int, first_name: string|null, display_name: string|null, locale: string, onboarding_completed: bool, onboarding: array{completed: bool, step: string|null}},
     *   account_restored: array{pro_can_resume: bool}|null,
     *   client_timing: array{sample_percent: int}
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
                // The name chosen in the onboarding of the Mini App (docs/40 §2.1): for the learner's own screens only.
                'display_name' => $learner->display_name,
                'locale' => $learner->interface_locale,
                'onboarding_completed' => $learner->onboarding_state === 'done',
                // Where the Mini App resumes its onboarding: from the loaded row, no statement.
                'onboarding' => [
                    'completed' => $learner->onboarding_state === 'done',
                    'step' => OnboardingChoices::resumeStep($learner),
                ],
            ],
            'account_restored' => $this->resource['account_restored'],
            // The share of opens that report their timing marks (docs/04 A.21): config only, no query; 0 - the client sends nothing.
            'client_timing' => ['sample_percent' => ClientTimingRecorder::samplePercent()],
        ];
    }
}
