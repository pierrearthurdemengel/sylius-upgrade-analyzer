<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

/**
 * Estimation budgétaire d'une migration, dérivée des heures estimées et d'un TJM.
 */
final readonly class CostEstimate
{
    /**
     * @param float $hours     Heures estimées (BREAKING + WARNING)
     * @param float $days      Jours-homme, arrondis à la demi-journée supérieure
     * @param int   $dailyRate Taux journalier moyen en euros
     * @param float $amount    Coût total en euros (jours × TJM)
     */
    public function __construct(
        public float $hours,
        public float $days,
        public int $dailyRate,
        public float $amount,
    ) {
    }
}
