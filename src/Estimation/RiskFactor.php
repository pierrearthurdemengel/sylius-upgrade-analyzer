<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

/**
 * Représente un facteur de risque individuel contribuant au score global.
 */
final readonly class RiskFactor
{
    /**
     * @param string $name        Nom du facteur de risque
     * @param int    $weight      Poids du facteur dans le calcul du score (0-100)
     * @param string $description Description du facteur de risque
     * @param string $impact      Description de l'impact sur la migration
     */
    public function __construct(
        public string $name,
        public int $weight,
        public string $description,
        public string $impact,
    ) {
    }
}
