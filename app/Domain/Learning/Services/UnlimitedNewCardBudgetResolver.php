<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Learning\Support\NewCardBudget;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/** No limit on new cards. Not bound in production (`NewWordQuota` is, task 1.4b); for tests and tooling. */
final class UnlimitedNewCardBudgetResolver implements NewCardBudgetResolver
{
    public function budgetFor(Learner $learner, CarbonImmutable $now): NewCardBudget
    {
        return NewCardBudget::unlimited();
    }
}
