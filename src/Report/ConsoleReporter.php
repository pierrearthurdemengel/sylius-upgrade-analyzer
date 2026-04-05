<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Complexity;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Générateur de rapport au format console.
 * Affiche un rapport lisible avec couleurs, tableaux et barre de progression.
 */
final class ConsoleReporter implements ReporterInterface
{
    /** Largeur totale de la jauge ASCII */
    private const GAUGE_WIDTH = 40;

    /** Seuils de la jauge pour le code couleur */
    private const GAUGE_THRESHOLDS = [
        'trivial' => 'green',
        'moderate' => 'yellow',
        'complex' => 'red',
        'major' => 'red',
    ];

    public function generate(MigrationReport $report, OutputInterface $output, array $context = []): void
    {
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->displayHeader($io, $report);
        $this->displaySummary($io, $report);
        $this->displayEstimatedHoursByCategory($io, $report);
        $this->displayBreakingIssues($io, $report);
        $this->displayWarningIssues($io, $report, $output);
        $this->displaySuggestions($io, $report, $output);
        $this->displayFooter($io);
    }

    public function getFormat(): string
    {
        return 'console';
    }

    /**
     * Affiche l'en-tête du rapport avec les informations du projet.
     */
    private function displayHeader(SymfonyStyle $io, MigrationReport $report): void
    {
        $io->title('Rapport d\'analyse de migration Sylius');

        $io->horizontalTable(
            ['Projet', 'Version détectée', 'Version cible', 'Date d\'analyse'],
            [[
                $report->getProjectPath(),
                $report->getDetectedSyliusVersion() ?? '<fg=yellow>Non détectée</>',
                $report->getTargetVersion(),
                $report->getStartedAt()->format('d/m/Y H:i:s'),
            ]],
        );
    }

    /**
     * Affiche le résumé global avec la jauge de complexité ASCII.
     */
    private function displaySummary(SymfonyStyle $io, MigrationReport $report): void
    {
        $io->section('Résumé global');

        $complexity = $report->getComplexity();
        $totalHours = $report->getTotalEstimatedHours();

        $breakingCount = count($report->getIssuesBySeverity(Severity::BREAKING));
        $warningCount = count($report->getIssuesBySeverity(Severity::WARNING));
        $suggestionCount = count($report->getIssuesBySeverity(Severity::SUGGESTION));

        $io->text([
            sprintf('  Problèmes critiques (BREAKING) : <fg=red>%d</>', $breakingCount),
            sprintf('  Avertissements (WARNING) :        <fg=yellow>%d</>', $warningCount),
            sprintf('  Suggestions :                     <fg=blue>%d</>', $suggestionCount),
            '',
            sprintf('  Temps total estimé : <options=bold>%.1f heures</>', $totalHours),
            '',
            sprintf('  Complexité globale : %s', $this->formatComplexityLabel($complexity)),
            '',
            '  ' . $this->buildAsciiGauge($complexity, $totalHours),
        ]);

        $io->newLine();
    }

    /**
     * Affiche le tableau des heures estimées ventilées par catégorie.
     */
    private function displayEstimatedHoursByCategory(SymfonyStyle $io, MigrationReport $report): void
    {
        $hoursByCategory = $report->getEstimatedHoursByCategory();

        if (count($hoursByCategory) === 0) {
            return;
        }

        $io->section('Estimation par catégorie');

        $rows = [];
        foreach ($hoursByCategory as $category => $hours) {
            $categoryLabel = $this->getCategoryLabel($category);
            $issueCount = count($report->getIssuesByCategory(Category::from($category)));
            $rows[] = [$categoryLabel, $issueCount, sprintf('%.1f h', $hours)];
        }

        $io->table(
            ['Catégorie', 'Nb problèmes', 'Heures estimées'],
            $rows,
        );
    }

    /**
     * Affiche les problèmes BREAKING en rouge.
     */
    private function displayBreakingIssues(SymfonyStyle $io, MigrationReport $report): void
    {
        $breakingIssues = $report->getIssuesBySeverity(Severity::BREAKING);

        if (count($breakingIssues) === 0) {
            return;
        }

        $io->section(sprintf('Changements cassants (%d)', count($breakingIssues)));

        foreach ($breakingIssues as $index => $issue) {
            $this->displayIssue($io, $issue, $index + 1, 'red');
        }
    }

    /**
     * Affiche les avertissements en jaune (uniquement en mode verbeux).
     */
    private function displayWarningIssues(SymfonyStyle $io, MigrationReport $report, OutputInterface $output): void
    {
        $warningIssues = $report->getIssuesBySeverity(Severity::WARNING);

        if (count($warningIssues) === 0) {
            return;
        }

        $io->section(sprintf('Avertissements (%d)', count($warningIssues)));

        if (!$output->isVerbose()) {
            $io->text(sprintf(
                '  <fg=yellow>%d avertissement(s) détecté(s). Utilisez -v pour afficher les détails.</>',
                count($warningIssues),
            ));
            $io->newLine();

            return;
        }

        foreach ($warningIssues as $index => $issue) {
            $this->displayIssue($io, $issue, $index + 1, 'yellow');
        }
    }

    /**
     * Affiche les suggestions (uniquement en mode très verbeux).
     */
    private function displaySuggestions(SymfonyStyle $io, MigrationReport $report, OutputInterface $output): void
    {
        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);

        if (count($suggestions) === 0) {
            return;
        }

        $io->section(sprintf('Suggestions (%d)', count($suggestions)));

        if (!$output->isVeryVerbose()) {
            $io->text(sprintf(
                '  <fg=blue>%d suggestion(s) disponible(s). Utilisez -vv pour afficher les détails.</>',
                count($suggestions),
            ));
            $io->newLine();

            return;
        }

        foreach ($suggestions as $index => $issue) {
            $this->displayIssue($io, $issue, $index + 1, 'blue');
        }
    }

    /**
     * Affiche un problème individuel avec formatage couleur.
     */
    private function displayIssue(SymfonyStyle $io, MigrationIssue $issue, int $number, string $color): void
    {
        $io->text(sprintf(
            '  <fg=%s>%d. [%s] %s</>',
            $color,
            $number,
            strtoupper($issue->getCategory()->value),
            $issue->getMessage(),
        ));

        if ($issue->getFile() !== null) {
            $location = $issue->getFile();
            if ($issue->getLine() !== null) {
                $location .= ':' . $issue->getLine();
            }
            $io->text(sprintf('     Fichier : %s', $location));
        }

        $io->text(sprintf('     Détail : %s', $issue->getDetail()));
        $io->text(sprintf('     Suggestion : %s', $issue->getSuggestion()));

        if ($issue->getEstimatedMinutes() > 0) {
            $io->text(sprintf('     Estimation : %d min', $issue->getEstimatedMinutes()));
        }

        if ($issue->getDocUrl() !== null) {
            $io->text(sprintf('     Documentation : %s', $issue->getDocUrl()));
        }

        $io->newLine();
    }

    /**
     * Affiche le pied de page avec les commandes utiles.
     */
    private function displayFooter(SymfonyStyle $io): void
    {
        $io->section('Prochaines étapes');

        $io->text([
            '  Pour générer un rapport PDF :',
            '    <info>sylius-upgrade-analyzer sylius-upgrade:analyze --pdf</info>',
            '',
            '  Pour afficher tous les détails :',
            '    <info>sylius-upgrade-analyzer sylius-upgrade:analyze -vv</info>',
            '',
            '  Pour exporter en JSON :',
            '    <info>sylius-upgrade-analyzer sylius-upgrade:analyze --format=json --output=rapport.json</info>',
        ]);

        $io->newLine();
    }

    /**
     * Construit la jauge ASCII représentant la complexité.
     */
    private function buildAsciiGauge(Complexity $complexity, float $totalHours): string
    {
        /* Calcul du remplissage proportionnel (max 200h pour la jauge) */
        $maxHours = 200.0;
        $ratio = min($totalHours / $maxHours, 1.0);
        $filled = (int) round($ratio * self::GAUGE_WIDTH);
        $empty = self::GAUGE_WIDTH - $filled;

        $color = self::GAUGE_THRESHOLDS[$complexity->value];

        $gauge = sprintf(
            '[<fg=%s>%s</>%s] %.1fh / %s',
            $color,
            str_repeat('█', $filled),
            str_repeat('░', $empty),
            $totalHours,
            strtoupper($complexity->value),
        );

        return $gauge;
    }

    /**
     * Formate le libellé de complexité avec couleur.
     */
    private function formatComplexityLabel(Complexity $complexity): string
    {
        $color = self::GAUGE_THRESHOLDS[$complexity->value];
        $labels = [
            'trivial' => 'TRIVIALE',
            'moderate' => 'MODÉRÉE',
            'complex' => 'COMPLEXE',
            'major' => 'MAJEURE',
        ];

        $label = $labels[$complexity->value];

        return sprintf('<fg=%s;options=bold>%s</>', $color, $label);
    }

    /**
     * Retourne le libellé français d'une catégorie.
     */
    private function getCategoryLabel(string $category): string
    {
        $labels = [
            'twig' => 'Templates Twig',
            'deprecation' => 'Dépréciations',
            'plugin' => 'Plugins',
            'grid' => 'Grilles',
            'resource' => 'Ressources',
            'frontend' => 'Front-end',
            'api' => 'API',
        ];

        return $labels[$category] ?? ucfirst($category);
    }
}
