<?php

declare(strict_types=1);

namespace App\Domain\Learning\Contracts;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Learning\Support\NewCardBudget;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * Supplies the new-card budget TrainingFlowService hands to CardPicker for every
 * step. The default binding is unlimited; task 1.4b binds the Free-plan quota
 * (`NewWordQuota` → entitlement → `NewCardBudget::lazy(...)`) in its place
 * without touching the training services.
 */
interface NewCardBudgetResolver
{
    /**
     * @param  Entitlement|null  $entitlement  the learner's plan when the caller resolved it already (one read per
     *                                         request, docs/12 §5.1); the resolver reads it itself otherwise
     */
    public function budgetFor(Learner $learner, CarbonImmutable $now, ?Entitlement $entitlement = null): NewCardBudget;
}
