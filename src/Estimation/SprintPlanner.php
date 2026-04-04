<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;

/**
 * Distribue les étapes de la feuille de route de migration dans des sprints
 * en respectant la vélocité de l'équipe et les dépendances entre tâches.
 */
final class SprintPlanner
{
    /**
     * Génère un plan de sprints pour la migration.
     *
     * @param MigrationReport $report        Rapport de migration à planifier
     * @param float           $velocityHours Capacité de l'équipe en heures par sprint
     *
     * @return list<Sprint>
     */
    public function plan(MigrationReport $report, float $velocityHours): array
    {
        $roadmapGenerator = new MigrationRoadmapGenerator();
        $allSteps = $roadmapGenerator->generate($report);

        if (count($allSteps) === 0) {
            return [];
        }

        /* Tri topologique des tâches selon leurs dépendances */
        $orderedSteps = $this->topologicalSort($allSteps);

        /* Distribution des tâches dans les sprints */
        $sprints = [];
        $currentSprintNumber = 1;
        $currentTasks = [];
        $currentHours = 0.0;
        $completedSteps = [];

        /** @var list<string> $criticalStages Phases considérées comme critiques */
        $criticalStages = ['Changements bloquants', 'Plugins'];

        foreach ($orderedSteps as $step) {
            /* Vérification que toutes les dépendances sont dans des sprints précédents ou le sprint courant */
            $dependenciesMet = $this->areDependenciesMet($step, $completedSteps);

            /*
             * Si les dépendances ne sont pas satisfaites, on finalise le sprint courant
             * et on en commence un nouveau.
             */
            if (!$dependenciesMet && count($currentTasks) > 0) {
                $sprints[] = $this->createSprint(
                    $currentSprintNumber,
                    $currentTasks,
                    $currentHours,
                    $criticalStages,
                );
                $completedSteps = array_merge($completedSteps, array_map(
                    static fn (RoadmapStep $s): string => $s->name,
                    $currentTasks,
                ));
                $currentSprintNumber++;
                $currentTasks = [];
                $currentHours = 0.0;
            }

            /* Si l'ajout de cette tâche dépasse la vélocité, on commence un nouveau sprint */
            if ($currentHours + $step->estimatedHours > $velocityHours && count($currentTasks) > 0) {
                $sprints[] = $this->createSprint(
                    $currentSprintNumber,
                    $currentTasks,
                    $currentHours,
                    $criticalStages,
                );
                $completedSteps = array_merge($completedSteps, array_map(
                    static fn (RoadmapStep $s): string => $s->name,
                    $currentTasks,
                ));
                $currentSprintNumber++;
                $currentTasks = [];
                $currentHours = 0.0;
            }

            $currentTasks[] = $step;
            $currentHours += $step->estimatedHours;
        }

        /* Finalisation du dernier sprint s'il contient des tâches */
        if (count($currentTasks) > 0) {
            $sprints[] = $this->createSprint(
                $currentSprintNumber,
                $currentTasks,
                $currentHours,
                $criticalStages,
            );
        }

        return $sprints;
    }

    /**
     * Trie les étapes par ordre topologique en respectant les dépendances.
     *
     * @param list<RoadmapStep> $steps Étapes à trier
     * @return list<RoadmapStep>
     */
    private function topologicalSort(array $steps): array
    {
        /* Construction d'un index par nom pour résoudre les dépendances */
        $stepsByName = [];
        foreach ($steps as $step) {
            $stepsByName[$step->name] = $step;
        }

        $visited = [];
        $sorted = [];

        foreach ($steps as $step) {
            $this->visit($step, $stepsByName, $visited, $sorted);
        }

        return $sorted;
    }

    /**
     * Parcours en profondeur pour le tri topologique.
     *
     * @param RoadmapStep $step       Étape courante
     * @param array<string, RoadmapStep> $stepsByName Index des étapes par nom
     * @param array<string, bool> $visited Étapes déjà visitées
     * @param list<RoadmapStep> $sorted Liste triée en construction
     */
    private function visit(
        RoadmapStep $step,
        array $stepsByName,
        array &$visited,
        array &$sorted,
    ): void {
        if (isset($visited[$step->name])) {
            return;
        }

        $visited[$step->name] = true;

        /* Visite des dépendances d'abord */
        foreach ($step->dependencies as $dependency) {
            if (isset($stepsByName[$dependency])) {
                $this->visit($stepsByName[$dependency], $stepsByName, $visited, $sorted);
            }
        }

        $sorted[] = $step;
    }

    /**
     * Vérifie que toutes les dépendances d'une étape sont satisfaites.
     *
     * @param RoadmapStep  $step           Étape à vérifier
     * @param list<string> $completedSteps Noms des étapes déjà complétées
     */
    private function areDependenciesMet(RoadmapStep $step, array $completedSteps): bool
    {
        foreach ($step->dependencies as $dependency) {
            if (!in_array($dependency, $completedSteps, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Crée un objet Sprint à partir des tâches accumulées.
     *
     * @param int               $number         Numéro du sprint
     * @param list<RoadmapStep> $tasks          Tâches du sprint
     * @param float             $totalHours     Heures totales du sprint
     * @param list<string>      $criticalStages Phases considérées comme critiques
     */
    private function createSprint(
        int $number,
        array $tasks,
        float $totalHours,
        array $criticalStages,
    ): Sprint {
        /* Détection de tâches critiques dans le sprint */
        $hasCriticalTask = false;
        foreach ($tasks as $task) {
            if (in_array($task->stage, $criticalStages, true)) {
                $hasCriticalTask = true;
                break;
            }
        }

        return new Sprint(
            number: $number,
            tasks: $tasks,
            totalHours: $totalHours,
            hasCriticalTask: $hasCriticalTask,
        );
    }
}
