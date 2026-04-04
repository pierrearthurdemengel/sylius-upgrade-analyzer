<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interface pour les générateurs de rapports de migration.
 * Chaque implémentation produit un rapport dans un format spécifique.
 */
interface ReporterInterface
{
    /**
     * Génère le rapport dans le format supporté.
     *
     * @param MigrationReport $report  Rapport de migration à formater
     * @param OutputInterface $output  Sortie console pour l'affichage
     * @param array<string, mixed> $context Paramètres de contexte additionnels
     */
    public function generate(MigrationReport $report, OutputInterface $output, array $context = []): void;

    /**
     * Retourne l'identifiant du format de sortie (ex: 'console', 'json', 'pdf').
     */
    public function getFormat(): string;
}
