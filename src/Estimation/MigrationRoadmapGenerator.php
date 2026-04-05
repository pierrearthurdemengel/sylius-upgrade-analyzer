<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Génère une feuille de route de migration ordonnée par dépendances.
 * Les étapes sont organisées en phases : prérequis, corrections automatiques,
 * changements bloquants, frontend, plugins et validation.
 */
final class MigrationRoadmapGenerator
{
    /** Phases de la migration, dans l'ordre d'exécution */
    private const STAGE_PREREQUISITES = 'Prérequis';
    private const STAGE_AUTO_FIX = 'Corrections automatiques';
    private const STAGE_BLOCKING = 'Changements bloquants';
    private const STAGE_FRONTEND = 'Frontend';
    private const STAGE_PLUGINS = 'Plugins';
    private const STAGE_VALIDATION = 'Validation';

    /**
     * Génère la feuille de route de migration complète.
     *
     * @return list<RoadmapStep>
     */
    public function generate(MigrationReport $report): array
    {
        $steps = [];

        /* Phase 1 : Prérequis — préparation de l'environnement */
        $steps = array_merge($steps, $this->generatePrerequisites($report));

        /* Phase 2 : Corrections automatiques — changements qui peuvent être appliqués automatiquement */
        $steps = array_merge($steps, $this->generateAutoFixSteps($report));

        /* Phase 3 : Changements bloquants — interventions manuelles obligatoires */
        $steps = array_merge($steps, $this->generateBlockingSteps($report));

        /* Phase 4 : Frontend — assets et templates */
        $steps = array_merge($steps, $this->generateFrontendSteps($report));

        /* Phase 5 : Plugins — compatibilité des extensions tierces */
        $steps = array_merge($steps, $this->generatePluginSteps($report));

        /* Phase 6 : Validation — tests et vérifications finales */
        $steps = array_merge($steps, $this->generateValidationSteps($report));

        return $steps;
    }

    /**
     * Génère les étapes de prérequis.
     *
     * @return list<RoadmapStep>
     */
    private function generatePrerequisites(MigrationReport $report): array
    {
        $steps = [];

        $steps[] = new RoadmapStep(
            stage: self::STAGE_PREREQUISITES,
            name: 'Sauvegarde du projet',
            description: 'Créer une branche Git dédiée à la migration et sauvegarder la base de données.',
            estimatedHours: 0.5,
            dependencies: [],
            canParallelize: false,
        );

        $steps[] = new RoadmapStep(
            stage: self::STAGE_PREREQUISITES,
            name: 'Mise à jour des dépendances PHP',
            description: 'Mettre à jour composer.json pour cibler Sylius ' . $report->getTargetVersion() . ' et résoudre les conflits de dépendances.',
            estimatedHours: 2.0,
            dependencies: ['Sauvegarde du projet'],
            canParallelize: false,
        );

        $steps[] = new RoadmapStep(
            stage: self::STAGE_PREREQUISITES,
            name: 'Mise à jour de la configuration',
            description: 'Adapter les fichiers de configuration Symfony et Sylius pour la version cible.',
            estimatedHours: 1.5,
            dependencies: ['Mise à jour des dépendances PHP'],
            canParallelize: false,
        );

        return $steps;
    }

    /**
     * Génère les étapes de corrections automatiques basées sur les suggestions.
     *
     * @return list<RoadmapStep>
     */
    private function generateAutoFixSteps(MigrationReport $report): array
    {
        $steps = [];

        /* Regroupement des problèmes SUGGESTION par catégorie pour les corrections automatiques */
        $suggestionsByCategory = [];
        foreach ($report->getIssuesBySeverity(Severity::SUGGESTION) as $issue) {
            $categoryValue = $issue->getCategory()->value;
            if (!isset($suggestionsByCategory[$categoryValue])) {
                $suggestionsByCategory[$categoryValue] = 0;
            }
            $suggestionsByCategory[$categoryValue]++;
        }

        foreach ($suggestionsByCategory as $category => $count) {
            $hours = ceil($count * 5 / 30.0) * 0.5;

            $steps[] = new RoadmapStep(
                stage: self::STAGE_AUTO_FIX,
                name: sprintf('Corrections automatiques — %s', $category),
                description: sprintf('Appliquer les %d correction(s) automatique(s) pour la catégorie %s.', $count, $category),
                estimatedHours: $hours,
                dependencies: ['Mise à jour de la configuration'],
                canParallelize: true,
            );
        }

        return $steps;
    }

    /**
     * Génère les étapes pour les changements bloquants.
     *
     * @return list<RoadmapStep>
     */
    private function generateBlockingSteps(MigrationReport $report): array
    {
        $steps = [];

        /* Regroupement des problèmes BREAKING par catégorie (hors frontend et plugins) */
        $breakingByCategory = [];
        $minutesByCategory = [];

        foreach ($report->getIssuesBySeverity(Severity::BREAKING) as $issue) {
            $category = $issue->getCategory();

            /* Frontend et plugins sont traités dans leurs propres phases */
            if ($category === Category::FRONTEND || $category === Category::PLUGIN) {
                continue;
            }

            $categoryValue = $category->value;
            if (!isset($breakingByCategory[$categoryValue])) {
                $breakingByCategory[$categoryValue] = 0;
                $minutesByCategory[$categoryValue] = 0;
            }
            $breakingByCategory[$categoryValue]++;
            $minutesByCategory[$categoryValue] += $issue->getEstimatedMinutes();
        }

        /* Création d'une étape par catégorie ayant des problèmes bloquants */
        foreach ($breakingByCategory as $category => $count) {
            $hours = ceil($minutesByCategory[$category] / 30.0) * 0.5;

            /* Les catégories API et resource ne peuvent pas être parallélisées car elles impactent le cœur */
            $canParallelize = !in_array($category, [Category::API->value, Category::RESOURCE->value], true);

            $dependencies = ['Mise à jour de la configuration'];

            $steps[] = new RoadmapStep(
                stage: self::STAGE_BLOCKING,
                name: sprintf('Migration — %s (%d problème(s))', $category, $count),
                description: sprintf('Résoudre les %d changement(s) bloquant(s) pour la catégorie %s.', $count, $category),
                estimatedHours: $hours,
                dependencies: $dependencies,
                canParallelize: $canParallelize,
            );
        }

        /* Ajout des problèmes WARNING regroupés */
        $warningCount = count($report->getIssuesBySeverity(Severity::WARNING));
        if ($warningCount > 0) {
            $warningMinutes = 0;
            foreach ($report->getIssuesBySeverity(Severity::WARNING) as $issue) {
                $warningMinutes += $issue->getEstimatedMinutes();
            }
            $warningHours = ceil($warningMinutes / 30.0) * 0.5;

            $steps[] = new RoadmapStep(
                stage: self::STAGE_BLOCKING,
                name: sprintf('Corrections des avertissements (%d)', $warningCount),
                description: sprintf('Traiter les %d avertissement(s) détecté(s) pour éviter les régressions futures.', $warningCount),
                estimatedHours: $warningHours,
                dependencies: ['Mise à jour de la configuration'],
                canParallelize: true,
            );
        }

        return $steps;
    }

    /**
     * Génère les étapes liées au frontend.
     *
     * @return list<RoadmapStep>
     */
    private function generateFrontendSteps(MigrationReport $report): array
    {
        $steps = [];

        $frontendIssues = $report->getIssuesByCategory(Category::FRONTEND);
        $twigIssues = $report->getIssuesByCategory(Category::TWIG);

        $frontendBreaking = array_filter(
            $frontendIssues,
            static fn ($issue): bool => $issue->getSeverity() === Severity::BREAKING,
        );

        $twigBreaking = array_filter(
            $twigIssues,
            static fn ($issue): bool => $issue->getSeverity() === Severity::BREAKING,
        );

        if (count($twigBreaking) > 0) {
            $twigMinutes = 0;
            foreach ($twigBreaking as $issue) {
                $twigMinutes += $issue->getEstimatedMinutes();
            }
            $twigHours = ceil($twigMinutes / 30.0) * 0.5;

            /* Collecte des noms des étapes bloquantes comme dépendances */
            $steps[] = new RoadmapStep(
                stage: self::STAGE_FRONTEND,
                name: sprintf('Migration des templates Twig (%d)', count($twigBreaking)),
                description: 'Adapter les templates Twig aux nouveaux hooks et structures de Sylius 2.x.',
                estimatedHours: $twigHours,
                dependencies: ['Mise à jour de la configuration'],
                canParallelize: true,
            );
        }

        if (count($frontendBreaking) > 0) {
            $frontendMinutes = 0;
            foreach ($frontendBreaking as $issue) {
                $frontendMinutes += $issue->getEstimatedMinutes();
            }
            $frontendHours = ceil($frontendMinutes / 30.0) * 0.5;

            $steps[] = new RoadmapStep(
                stage: self::STAGE_FRONTEND,
                name: sprintf('Migration des assets frontend (%d)', count($frontendBreaking)),
                description: 'Mettre à jour les assets, le build system et les dépendances JavaScript.',
                estimatedHours: $frontendHours,
                dependencies: ['Mise à jour de la configuration'],
                canParallelize: true,
            );
        }

        return $steps;
    }

    /**
     * Génère les étapes liées aux plugins.
     *
     * @return list<RoadmapStep>
     */
    private function generatePluginSteps(MigrationReport $report): array
    {
        $steps = [];

        $pluginIssues = $report->getIssuesByCategory(Category::PLUGIN);
        $breakingPlugins = array_filter(
            $pluginIssues,
            static fn ($issue): bool => $issue->getSeverity() === Severity::BREAKING,
        );

        if (count($breakingPlugins) === 0) {
            return $steps;
        }

        $pluginMinutes = 0;
        foreach ($breakingPlugins as $issue) {
            $pluginMinutes += $issue->getEstimatedMinutes();
        }
        $pluginHours = ceil($pluginMinutes / 30.0) * 0.5;

        $steps[] = new RoadmapStep(
            stage: self::STAGE_PLUGINS,
            name: sprintf('Migration des plugins (%d)', count($breakingPlugins)),
            description: 'Mettre à jour ou remplacer les plugins incompatibles avec Sylius 2.x.',
            estimatedHours: $pluginHours,
            dependencies: ['Mise à jour de la configuration'],
            canParallelize: false,
        );

        return $steps;
    }

    /**
     * Génère les étapes de validation finale.
     *
     * @return list<RoadmapStep>
     */
    private function generateValidationSteps(MigrationReport $report): array
    {
        /* Collecte de toutes les étapes précédentes comme dépendances de la validation */
        $allStepNames = [];

        /* Les dépendances de la validation incluent les principales phases */
        $breakingCount = count($report->getIssuesBySeverity(Severity::BREAKING));
        $validationDependencies = ['Mise à jour de la configuration'];

        $steps = [];

        $steps[] = new RoadmapStep(
            stage: self::STAGE_VALIDATION,
            name: 'Exécution de la suite de tests',
            description: 'Lancer tous les tests unitaires, fonctionnels et d\'intégration pour vérifier la non-régression.',
            estimatedHours: max(1.0, $breakingCount * 0.1),
            dependencies: $validationDependencies,
            canParallelize: false,
        );

        $steps[] = new RoadmapStep(
            stage: self::STAGE_VALIDATION,
            name: 'Tests de recette manuels',
            description: 'Valider les parcours utilisateur critiques : commande, paiement, gestion du catalogue.',
            estimatedHours: max(2.0, $breakingCount * 0.2),
            dependencies: ['Exécution de la suite de tests'],
            canParallelize: false,
        );

        $steps[] = new RoadmapStep(
            stage: self::STAGE_VALIDATION,
            name: 'Déploiement en staging',
            description: 'Déployer sur l\'environnement de pré-production et valider le bon fonctionnement.',
            estimatedHours: 1.0,
            dependencies: ['Tests de recette manuels'],
            canParallelize: false,
        );

        return $steps;
    }
}
