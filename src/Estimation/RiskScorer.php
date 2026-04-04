<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Calcule un score de risque global pour une migration
 * en évaluant différents facteurs d'impact.
 */
final class RiskScorer
{
    /**
     * Évalue le score de risque global pour le rapport de migration donné.
     *
     * Les facteurs analysés incluent : machines à états, passerelles de paiement,
     * plugins incompatibles, absence de couverture de tests.
     */
    public function score(MigrationReport $report): RiskScore
    {
        $factors = [];
        $recommendations = [];

        /* Analyse des machines à états (impact élevé) */
        $stateMachineFactor = $this->evaluateStateMachines($report);
        if ($stateMachineFactor !== null) {
            $factors[] = $stateMachineFactor;
            $recommendations[] = 'Migrer les machines à états Winzou vers Symfony Workflow en priorité.';
        }

        /* Analyse des passerelles de paiement (impact élevé) */
        $gatewayFactor = $this->evaluateGateways($report);
        if ($gatewayFactor !== null) {
            $factors[] = $gatewayFactor;
            $recommendations[] = 'Vérifier la compatibilité des passerelles de paiement avec Sylius 2.x avant la migration.';
        }

        /* Analyse des plugins incompatibles */
        $pluginFactor = $this->evaluatePlugins($report);
        if ($pluginFactor !== null) {
            $factors[] = $pluginFactor;
            $recommendations[] = 'Contacter les éditeurs de plugins pour obtenir des versions compatibles Sylius 2.x.';
        }

        /* Analyse de l'absence de couverture de tests */
        $testCoverageFactor = $this->evaluateTestCoverage($report);
        if ($testCoverageFactor !== null) {
            $factors[] = $testCoverageFactor;
            $recommendations[] = 'Ajouter des tests de non-régression avant de commencer la migration.';
        }

        /* Calcul du score total pondéré */
        $totalWeight = 0;
        foreach ($factors as $factor) {
            $totalWeight += $factor->weight;
        }

        /* Détermination du niveau de risque selon le score total */
        $level = match (true) {
            $totalWeight >= 200 => RiskLevel::CRITIQUE,
            $totalWeight >= 120 => RiskLevel::ELEVE,
            $totalWeight >= 50 => RiskLevel::MODERE,
            default => RiskLevel::FAIBLE,
        };

        /* Ajout de recommandations générales selon le niveau */
        if ($level === RiskLevel::CRITIQUE) {
            $recommendations[] = 'Envisager une migration progressive par étapes plutôt qu\'une migration en une seule fois.';
        }

        if ($level === RiskLevel::ELEVE || $level === RiskLevel::CRITIQUE) {
            $recommendations[] = 'Prévoir un environnement de staging dédié pour valider chaque étape de la migration.';
        }

        return new RiskScore(
            level: $level,
            factors: $factors,
            recommendations: $recommendations,
        );
    }

    /**
     * Évalue le facteur de risque lié aux machines à états.
     * La présence de machines à états personnalisées augmente significativement le risque.
     */
    private function evaluateStateMachines(MigrationReport $report): ?RiskFactor
    {
        $stateMachineIssues = 0;

        foreach ($report->getIssues() as $issue) {
            /* Détection des problèmes liés aux machines à états via l'analyseur ou le message */
            $analyzerName = strtolower($issue->getAnalyzer());
            $message = strtolower($issue->getMessage());

            if (
                str_contains($analyzerName, 'state_machine')
                || str_contains($analyzerName, 'statemachine')
                || str_contains($message, 'state machine')
                || str_contains($message, 'winzou')
                || str_contains($message, 'workflow')
            ) {
                $stateMachineIssues++;
            }
        }

        if ($stateMachineIssues === 0) {
            return null;
        }

        /* Poids proportionnel au nombre de problèmes détectés */
        $weight = min(100, $stateMachineIssues * 25);

        return new RiskFactor(
            name: 'Machines à états',
            weight: $weight,
            description: sprintf('%d problème(s) lié(s) aux machines à états détecté(s).', $stateMachineIssues),
            impact: 'La migration des machines à états Winzou vers Symfony Workflow est complexe et impacte le cœur métier.',
        );
    }

    /**
     * Évalue le facteur de risque lié aux passerelles de paiement.
     */
    private function evaluateGateways(MigrationReport $report): ?RiskFactor
    {
        $gatewayIssues = 0;

        foreach ($report->getIssues() as $issue) {
            $analyzerName = strtolower($issue->getAnalyzer());
            $message = strtolower($issue->getMessage());

            if (
                str_contains($analyzerName, 'gateway')
                || str_contains($analyzerName, 'payment')
                || str_contains($message, 'gateway')
                || str_contains($message, 'payum')
                || str_contains($message, 'paiement')
            ) {
                $gatewayIssues++;
            }
        }

        if ($gatewayIssues === 0) {
            return null;
        }

        $weight = min(80, $gatewayIssues * 20);

        return new RiskFactor(
            name: 'Passerelles de paiement',
            weight: $weight,
            description: sprintf('%d problème(s) lié(s) aux passerelles de paiement détecté(s).', $gatewayIssues),
            impact: 'Les passerelles de paiement personnalisées peuvent nécessiter une réécriture complète.',
        );
    }

    /**
     * Évalue le facteur de risque lié aux plugins incompatibles.
     */
    private function evaluatePlugins(MigrationReport $report): ?RiskFactor
    {
        $pluginIssues = $report->getIssuesByCategory(Category::PLUGIN);
        $breakingPluginIssues = 0;

        foreach ($pluginIssues as $issue) {
            if ($issue->getSeverity() === Severity::BREAKING) {
                $breakingPluginIssues++;
            }
        }

        if ($breakingPluginIssues === 0) {
            return null;
        }

        $weight = min(90, $breakingPluginIssues * 15);

        return new RiskFactor(
            name: 'Plugins incompatibles',
            weight: $weight,
            description: sprintf('%d plugin(s) incompatible(s) avec la version cible.', $breakingPluginIssues),
            impact: 'Les plugins sans version compatible bloquent la migration et nécessitent un remplacement ou un fork.',
        );
    }

    /**
     * Évalue le facteur de risque lié à l'absence de couverture de tests.
     * L'absence de tests rend la validation post-migration plus risquée.
     */
    private function evaluateTestCoverage(MigrationReport $report): ?RiskFactor
    {
        $breakingCount = count($report->getIssuesBySeverity(Severity::BREAKING));
        $totalHours = $report->getTotalEstimatedHours();

        /*
         * Heuristique : si la migration est complexe (beaucoup de problèmes BREAKING)
         * et que le projet semble manquer de tests (pas de mention de tests dans les problèmes),
         * on considère l'absence de tests comme un facteur de risque.
         */
        $hasTestRelatedIssues = false;
        foreach ($report->getIssues() as $issue) {
            $message = strtolower($issue->getMessage());
            $analyzer = strtolower($issue->getAnalyzer());

            if (
                str_contains($message, 'test')
                || str_contains($analyzer, 'test')
                || str_contains($message, 'phpunit')
                || str_contains($message, 'behat')
            ) {
                $hasTestRelatedIssues = true;
                break;
            }
        }

        /* Si la migration est conséquente et qu'aucun problème de test n'est détecté, c'est un risque */
        if ($breakingCount < 5 || $totalHours < 20.0 || $hasTestRelatedIssues) {
            return null;
        }

        $weight = min(60, (int) ($totalHours / 5));

        return new RiskFactor(
            name: 'Absence de couverture de tests',
            weight: $weight,
            description: 'Aucune mention de tests détectée dans l\'analyse malgré une migration conséquente.',
            impact: 'Sans tests automatisés, la validation de la migration repose entièrement sur des tests manuels.',
        );
    }
}
