<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;

/**
 * Interface commune à tous les analyseurs de migration.
 * Chaque analyseur inspecte un aspect spécifique du projet Sylius.
 */
interface AnalyzerInterface
{
    /**
     * Exécute l'analyse et ajoute les problèmes détectés au rapport.
     */
    public function analyze(MigrationReport $report): void;

    /**
     * Retourne le nom lisible de l'analyseur.
     */
    public function getName(): string;

    /**
     * Détermine si cet analyseur est applicable au projet analysé.
     */
    public function supports(MigrationReport $report): bool;
}
