<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Estimation;

/**
 * Représente une étape individuelle dans la feuille de route de migration.
 */
final readonly class RoadmapStep
{
    /**
     * @param string      $stage          Phase de la migration (ex: 'preparation', 'migration', 'validation')
     * @param string      $name           Nom de l'étape
     * @param string      $description    Description détaillée de l'étape
     * @param float       $estimatedHours Estimation du temps nécessaire en heures
     * @param list<string> $dependencies  Noms des étapes pré-requises
     * @param bool        $canParallelize Indique si l'étape peut être exécutée en parallèle
     */
    public function __construct(
        public string $stage,
        public string $name,
        public string $description,
        public float $estimatedHours,
        public array $dependencies,
        public bool $canParallelize,
    ) {
    }
}
