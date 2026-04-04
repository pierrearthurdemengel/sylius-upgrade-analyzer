<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Model;

/**
 * Niveaux de sévérité des problèmes détectés lors de l'analyse de migration.
 */
enum Severity: string
{
    /** Changement cassant nécessitant une intervention obligatoire */
    case BREAKING = 'breaking';

    /** Avertissement pouvant nécessiter une attention particulière */
    case WARNING = 'warning';

    /** Suggestion d'amélioration, non bloquante */
    case SUGGESTION = 'suggestion';
}
