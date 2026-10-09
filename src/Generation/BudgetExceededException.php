<?php

namespace App\Generation;

use App\Ai\Pricing\Money;

final class BudgetExceededException extends \RuntimeException
{
    public function __construct(public readonly int $spentPico, public readonly int $ceilingPico, public readonly int $budgetPico)
    {
        parent::__construct(\sprintf(
            'Budget atteint : déjà consommé %s, prochaine étape jusqu\'à %s, budget maximal %s. Augmentez le budget pour continuer.',
            Money::format($spentPico),
            Money::format($ceilingPico),
            Money::format($budgetPico),
        ));
    }
}
