<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\Support\NewCardBudget;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/** Default until the Free-plan quota (task 1.4b) is bound: no limit on new cards. */
final class UnlimitedNewCardBudgetResolver implements NewCardBudgetResolver
{
    public function budgetFor(Learner $learner, CarbonImmutable $now): NewCardBudget
    {
        return NewCardBudget::unlimited();
    }
}
