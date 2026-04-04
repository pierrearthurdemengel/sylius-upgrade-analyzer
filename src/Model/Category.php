<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Model;

/**
 * Catégories de problèmes identifiés lors de l'analyse de migration.
 */
enum Category: string
{
    /** Templates Twig et hooks */
    case TWIG = 'twig';

    /** Dépréciations de code PHP */
    case DEPRECATION = 'deprecation';

    /** Compatibilité des plugins */
    case PLUGIN = 'plugin';

    /** Configuration des grilles */
    case GRID = 'grid';

    /** Configuration des ressources */
    case RESOURCE = 'resource';

    /** Assets et intégration front-end */
    case FRONTEND = 'frontend';

    /** Configuration et endpoints API */
    case API = 'api';
}
