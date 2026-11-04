<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

/**
 * Convertit une estimation en heures en budget à partir d'un taux journalier moyen.
 */
final class CostEstimator
{
    /**
     * Une journée facturée compte 7 heures productives : c'est la base
     * utilisée par la plupart des ESN et freelances français pour un TJM.
     */
    public const HOURS_PER_DAY = 7;

    /**
     * Calcule le budget correspondant à un volume d'heures.
     *
     * Les jours sont arrondis à la demi-journée supérieure, car une demi-journée
     * entamée est en pratique facturée.
     *
     * @throws \InvalidArgumentException Si le TJM n'est pas strictement positif
     */
    public function estimate(float $hours, int $dailyRate): CostEstimate
    {
        if ($dailyRate <= 0) {
            throw new \InvalidArgumentException(sprintf('Le TJM doit être strictement positif, %d reçu.', $dailyRate));
        }

        $days = ceil($hours / self::HOURS_PER_DAY * 2) / 2;

        return new CostEstimate(
            hours: $hours,
            days: $days,
            dailyRate: $dailyRate,
            amount: $days * $dailyRate,
        );
    }
}
