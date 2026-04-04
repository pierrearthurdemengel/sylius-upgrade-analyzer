<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

/**
 * Score de risque global pour une migration, comprenant le niveau,
 * les facteurs contributifs et les recommandations associées.
 */
final readonly class RiskScore
{
    /**
     * @param RiskLevel    $level           Niveau de risque global
     * @param list<RiskFactor> $factors     Facteurs de risque identifiés
     * @param list<string> $recommendations Recommandations pour atténuer les risques
     */
    public function __construct(
        public RiskLevel $level,
        public array $factors,
        public array $recommendations,
    ) {
    }
}
