<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Model;

/**
 * Niveaux de complexité globale estimée pour la migration.
 */
enum Complexity: string
{
    /** Migration triviale, peu d'efforts nécessaires */
    case TRIVIAL = 'trivial';

    /** Migration modérée, efforts raisonnables */
    case MODERATE = 'moderate';

    /** Migration complexe, efforts significatifs */
    case COMPLEX = 'complex';

    /** Migration majeure, efforts très importants */
    case MAJOR = 'major';
}
