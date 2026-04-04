<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

/**
 * Représente un sprint dans le plan de migration.
 */
final readonly class Sprint
{
    /**
     * @param int             $number          Numéro du sprint
     * @param list<RoadmapStep> $tasks         Tâches assignées à ce sprint
     * @param float           $totalHours      Nombre total d'heures estimées pour ce sprint
     * @param bool            $hasCriticalTask  Indique si le sprint contient une tâche critique
     */
    public function __construct(
        public int $number,
        public array $tasks,
        public float $totalHours,
        public bool $hasCriticalTask,
    ) {
    }
}
