<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Complexity;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Générateur de rapport au format Markdown.
 * Produit un document structuré avec un tableau récapitulatif,
 * la liste des problèmes bloquants et des suggestions de correction.
 */
final class MarkdownReporter implements ReporterInterface
{
    public function getFormat(): string
    {
        return 'markdown';
    }

    public function generate(MigrationReport $report, OutputInterface $output, array $context = []): void
    {
        $markdown = $this->buildMarkdown($report);

        /* Écriture dans un fichier si un chemin de sortie est fourni */
        if (isset($context['output_file']) && is_string($context['output_file'])) {
            $outputFile = $context['output_file'];
            $directory = dirname($outputFile);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($outputFile, $markdown);
            $output->writeln(sprintf(
                '<info>Rapport Markdown généré dans %s</info>',
                $outputFile,
            ));

            return;
        }

        /* Sortie directe sur la console */
        $output->writeln($markdown);
    }

    /**
     * Construit le contenu Markdown complet du rapport.
     */
    private function buildMarkdown(MigrationReport $report): string
    {
        $lines = [];

        /* En-tête du rapport */
        $lines[] = '# Rapport de migration Sylius';
        $lines[] = '';
        $lines[] = sprintf(
            '> Version détectée : **%s** → Version cible : **%s**',
            $report->getDetectedSyliusVersion() ?? 'Non détectée',
            $report->getTargetVersion(),
        );
        $lines[] = '';

        /* Badge de complexité */
        $lines[] = $this->buildComplexityBadge($report);
        $lines[] = '';

        /* Résumé global */
        $lines[] = '## Résumé';
        $lines[] = '';
        $lines[] = sprintf('- **Problèmes totaux** : %d', count($report->getIssues()));
        $lines[] = sprintf('- **Bloquants** : %d', count($report->getIssuesBySeverity(Severity::BREAKING)));
        $lines[] = sprintf('- **Avertissements** : %d', count($report->getIssuesBySeverity(Severity::WARNING)));
        $lines[] = sprintf('- **Suggestions** : %d', count($report->getIssuesBySeverity(Severity::SUGGESTION)));
        $lines[] = sprintf('- **Estimation totale** : %.1f heures', $report->getTotalEstimatedHours());
        $lines[] = '';

        /* Tableau récapitulatif par catégorie */
        $lines[] = '## Répartition par catégorie';
        $lines[] = '';
        $lines = array_merge($lines, $this->buildCategoryTable($report));
        $lines[] = '';

        /* Liste des problèmes bloquants */
        $breakingIssues = $report->getIssuesBySeverity(Severity::BREAKING);
        if (count($breakingIssues) > 0) {
            $lines[] = '## Problèmes bloquants';
            $lines[] = '';
            $lines = array_merge($lines, $this->buildBreakingIssuesList($breakingIssues));
            $lines[] = '';
        }

        /* Section "Comment corriger" pour les 3 premiers problèmes bloquants */
        if (count($breakingIssues) > 0) {
            $lines[] = '## Comment corriger';
            $lines[] = '';
            $lines = array_merge($lines, $this->buildHowToFixSection($breakingIssues));
            $lines[] = '';
        }

        /* Pied de page */
        $lines[] = '---';
        $lines[] = sprintf(
            '*Rapport généré le %s par sylius-upgrade-analyzer*',
            $report->getStartedAt()->format('d/m/Y à H:i'),
        );
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Génère le badge de complexité en Markdown.
     */
    private function buildComplexityBadge(MigrationReport $report): string
    {
        $complexity = $report->getComplexity();

        $emoji = match ($complexity) {
            Complexity::TRIVIAL => '🟢',
            Complexity::MODERATE => '🟡',
            Complexity::COMPLEX => '🟠',
            Complexity::MAJOR => '🔴',
        };

        return sprintf(
            '**Complexité** : %s `%s` (%.1f heures estimées)',
            $emoji,
            strtoupper($complexity->value),
            $report->getTotalEstimatedHours(),
        );
    }

    /**
     * Construit le tableau récapitulatif par catégorie.
     *
     * @return list<string>
     */
    private function buildCategoryTable(MigrationReport $report): array
    {
        $lines = [];

        $lines[] = '| Catégorie | Problèmes | Heures estimées |';
        $lines[] = '|-----------|-----------|-----------------|';

        $hoursByCategory = $report->getEstimatedHoursByCategory();

        foreach (Category::cases() as $category) {
            $issues = $report->getIssuesByCategory($category);
            $count = count($issues);

            if ($count === 0) {
                continue;
            }

            $hours = $hoursByCategory[$category->value] ?? 0.0;

            $lines[] = sprintf(
                '| %s | %d | %.1f |',
                $this->formatCategoryName($category),
                $count,
                $hours,
            );
        }

        return $lines;
    }

    /**
     * Construit la liste des problèmes bloquants avec liens de documentation.
     *
     * @param list<MigrationIssue> $breakingIssues
     * @return list<string>
     */
    private function buildBreakingIssuesList(array $breakingIssues): array
    {
        $lines = [];

        foreach ($breakingIssues as $index => $issue) {
            $number = $index + 1;
            $lines[] = sprintf('### %d. %s', $number, $issue->getMessage());
            $lines[] = '';

            /* Détails du problème */
            if ($issue->getFile() !== null) {
                $location = $issue->getFile();
                if ($issue->getLine() !== null) {
                    $location .= sprintf(':%d', $issue->getLine());
                }
                $lines[] = sprintf('- **Fichier** : `%s`', $location);
            }

            $lines[] = sprintf('- **Catégorie** : %s', $this->formatCategoryName($issue->getCategory()));
            $lines[] = sprintf('- **Analyseur** : %s', $issue->getAnalyzer());
            $lines[] = sprintf('- **Estimation** : %d minutes', $issue->getEstimatedMinutes());

            /* Lien vers la documentation */
            if ($issue->getDocUrl() !== null) {
                $lines[] = sprintf('- **Documentation** : [Voir la documentation](%s)', $issue->getDocUrl());
            }

            $lines[] = '';

            /* Détail technique */
            if ($issue->getDetail() !== '') {
                $lines[] = sprintf('> %s', $issue->getDetail());
                $lines[] = '';
            }

            /* Extrait de code si disponible */
            if ($issue->getCodeSnippet() !== null) {
                $lines[] = '```php';
                $lines[] = $issue->getCodeSnippet();
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * Construit la section "Comment corriger" pour les 3 premiers problèmes bloquants.
     *
     * @param list<MigrationIssue> $breakingIssues
     * @return list<string>
     */
    private function buildHowToFixSection(array $breakingIssues): array
    {
        $lines = [];

        /* Limiter aux 3 premiers problèmes bloquants */
        $topIssues = array_slice($breakingIssues, 0, 3);

        foreach ($topIssues as $index => $issue) {
            $number = $index + 1;
            $lines[] = sprintf('### %d. %s', $number, $issue->getMessage());
            $lines[] = '';

            /* Suggestion de correction */
            if ($issue->getSuggestion() !== '') {
                $lines[] = $issue->getSuggestion();
                $lines[] = '';
            }

            /* Lien de documentation pour aller plus loin */
            if ($issue->getDocUrl() !== null) {
                $lines[] = sprintf(
                    'Pour plus de détails, consultez la [documentation officielle](%s).',
                    $issue->getDocUrl(),
                );
                $lines[] = '';
            }

            /* Extrait de code corrigé si une suggestion est disponible */
            if ($issue->getCodeSnippet() !== null && $issue->getSuggestion() !== '') {
                $lines[] = '**Avant** :';
                $lines[] = '```php';
                $lines[] = $issue->getCodeSnippet();
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * Formate le nom d'une catégorie pour l'affichage.
     */
    private function formatCategoryName(Category $category): string
    {
        return match ($category) {
            Category::TWIG => 'Templates Twig',
            Category::DEPRECATION => 'Dépréciations',
            Category::PLUGIN => 'Plugins',
            Category::GRID => 'Grilles',
            Category::RESOURCE => 'Ressources',
            Category::FRONTEND => 'Frontend',
            Category::API => 'API',
        };
    }
}
