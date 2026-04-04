<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

/**
 * Niveaux de risque pour la migration.
 */
enum RiskLevel: string
{
    /** Risque faible, migration sans danger majeur */
    case FAIBLE = 'faible';

    /** Risque modéré, quelques points d'attention */
    case MODERE = 'modere';

    /** Risque élevé, vigilance requise */
    case ELEVE = 'eleve';

    /** Risque critique, migration à haut danger */
    case CRITIQUE = 'critique';
}
