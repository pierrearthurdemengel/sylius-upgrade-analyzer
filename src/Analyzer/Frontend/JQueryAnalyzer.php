<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Frontend;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur de l'utilisation de jQuery dans les assets JavaScript.
 * Sylius 2.x migre vers Symfony UX et Stimulus, rendant jQuery obsolete.
 * Cet analyseur detecte les usages jQuery dans les fichiers JS/TS du projet.
 */
final class JQueryAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes pour un fichier simple (moins de 5 usages jQuery) */
    private const MINUTES_SIMPLE = 30;

    /** Estimation en minutes pour un fichier complexe (5 usages ou plus) */
    private const MINUTES_COMPLEX = 120;

    /** Seuil d'usages pour considerer un fichier comme complexe */
    private const COMPLEXITY_THRESHOLD = 5;

    /** URL de la documentation Symfony UX */
    private const DOC_URL = 'https://symfony.com/doc/current/frontend/ux.html';

    /**
     * Expressions regulieres pour detecter les usages jQuery.
     *
     * @var array<string, string>
     */
    private const JQUERY_PATTERNS = [
        'Appel $()' => '/\$\s*\(/',
        'Appel jQuery()' => '/jQuery\s*\(/',
        'Appel $.ajax' => '/\$\.ajax/',
        'Appel $.each' => '/\$\.each/',
        'Appel $(document).ready' => '/\$\(document\)\.ready/',
        'Verification window.jQuery' => '/window\.jQuery/',
    ];

    /**
     * Repertoires a scanner pour les fichiers JavaScript et TypeScript.
     *
     * @var list<string>
     */
    private const SCAN_DIRECTORIES = [
        'assets',
        'public',
    ];

    /**
     * Repertoires a exclure de l'analyse (dependances tierces).
     *
     * @var list<string>
     */
    private const EXCLUDED_DIRECTORIES = [
        'vendor',
        'node_modules',
    ];

    public function getName(): string
    {
        return 'jQuery';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        foreach (self::SCAN_DIRECTORIES as $directory) {
            $dirPath = $projectPath . '/' . $directory;
            if (!is_dir($dirPath)) {
                continue;
            }

            /* Verification de la presence de fichiers JS ou TS dans le repertoire */
            $finder = new Finder();
            $finder->files()->in($dirPath)->name(['*.js', '*.ts']);

            /* Exclusion des repertoires de dependances */
            foreach (self::EXCLUDED_DIRECTORIES as $excluded) {
                $finder->exclude($excluded);
            }

            if ($finder->hasResults()) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $totalSimpleFiles = 0;
        $totalComplexFiles = 0;

        foreach (self::SCAN_DIRECTORIES as $directory) {
            $dirPath = $projectPath . '/' . $directory;
            if (!is_dir($dirPath)) {
                continue;
            }

            /* Recherche des fichiers JS et TS en excluant vendor/ et node_modules/ */
            $finder = new Finder();
            $finder->files()->in($dirPath)->name(['*.js', '*.ts']);

            foreach (self::EXCLUDED_DIRECTORIES as $excluded) {
                $finder->exclude($excluded);
            }

            foreach ($finder as $file) {
                $filePath = $file->getRealPath();
                if ($filePath === false) {
                    continue;
                }

                $content = $file->getContents();

                /* Comptage des usages jQuery dans le fichier */
                $usageCount = $this->countJQueryUsages($content);

                if ($usageCount === 0) {
                    continue;
                }

                /* Determination de la complexite du fichier */
                $isComplex = $usageCount >= self::COMPLEXITY_THRESHOLD;
                $estimatedMinutes = $isComplex ? self::MINUTES_COMPLEX : self::MINUTES_SIMPLE;

                if ($isComplex) {
                    $totalComplexFiles++;
                } else {
                    $totalSimpleFiles++;
                }

                /* Identification des types d'usages detectes */
                $detectedUsages = $this->identifyUsages($content);
                $relativePath = $directory . '/' . $file->getRelativePathname();

                $complexityLabel = $isComplex ? 'complexe' : 'simple';

                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::FRONTEND,
                    analyzer: $this->getName(),
                    message: sprintf(
                        '%d usage(s) jQuery detecte(s) dans %s (fichier %s)',
                        $usageCount,
                        $relativePath,
                        $complexityLabel,
                    ),
                    detail: sprintf(
                        'Le fichier %s contient %d usage(s) de jQuery : %s. '
                        . 'Sylius 2.x utilise Symfony UX et Stimulus a la place de jQuery.',
                        $relativePath,
                        $usageCount,
                        implode(', ', $detectedUsages),
                    ),
                    suggestion: 'Migrer les fonctionnalites jQuery vers des controleurs Stimulus '
                        . '(Symfony UX). Remplacer les selecteurs jQuery par des targets Stimulus '
                        . 'et les evenements jQuery par des actions Stimulus.',
                    file: $filePath,
                    line: $this->findFirstUsageLine($content),
                    codeSnippet: $this->extractJQuerySnippet($content),
                    docUrl: self::DOC_URL,
                    estimatedMinutes: $estimatedMinutes,
                ));
            }
        }

        /* Ajout d'un probleme de synthese si des fichiers sont impactes */
        $totalFiles = $totalSimpleFiles + $totalComplexFiles;
        if ($totalFiles > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::FRONTEND,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d fichier(s) JavaScript/TypeScript utilisant jQuery detecte(s) '
                    . '(%d simple(s), %d complexe(s))',
                    $totalFiles,
                    $totalSimpleFiles,
                    $totalComplexFiles,
                ),
                detail: sprintf(
                    'Le projet contient %d fichier(s) utilisant jQuery. '
                    . 'Les fichiers simples (moins de %d usages) necessitent environ %d min, '
                    . 'les fichiers complexes environ %d min chacun.',
                    $totalFiles,
                    self::COMPLEXITY_THRESHOLD,
                    self::MINUTES_SIMPLE,
                    self::MINUTES_COMPLEX,
                ),
                suggestion: 'Planifier la migration de jQuery vers Symfony UX/Stimulus. '
                    . 'Commencer par les fichiers simples pour se familiariser avec Stimulus, '
                    . 'puis aborder les fichiers complexes.',
                docUrl: self::DOC_URL,
            ));
        }
    }

    /**
     * Compte le nombre total d'usages jQuery dans un contenu.
     */
    private function countJQueryUsages(string $content): int
    {
        $totalMatches = 0;

        foreach (self::JQUERY_PATTERNS as $pattern) {
            $count = preg_match_all($pattern, $content);
            if ($count !== false) {
                $totalMatches += $count;
            }
        }

        return $totalMatches;
    }

    /**
     * Identifie les types d'usages jQuery presents dans le contenu.
     *
     * @return list<string> Liste des types d'usages detectes
     */
    private function identifyUsages(string $content): array
    {
        $detected = [];

        foreach (self::JQUERY_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $detected[] = $label;
            }
        }

        return $detected;
    }

    /**
     * Trouve le numero de ligne du premier usage jQuery dans le contenu.
     */
    private function findFirstUsageLine(string $content): int
    {
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            foreach (self::JQUERY_PATTERNS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    return $index + 1;
                }
            }
        }

        return 1;
    }

    /**
     * Extrait un extrait de code contenant le premier usage jQuery.
     */
    private function extractJQuerySnippet(string $content, int $contextLines = 2): string
    {
        $lines = explode("\n", $content);
        $targetLine = null;

        foreach ($lines as $index => $line) {
            foreach (self::JQUERY_PATTERNS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $targetLine = $index;
                    break 2;
                }
            }
        }

        if ($targetLine === null) {
            return '';
        }

        /* Extraction des lignes autour du premier usage */
        $start = max(0, $targetLine - $contextLines);
        $end = min(count($lines) - 1, $targetLine + $contextLines);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $snippet[] = $lines[$i];
        }

        return implode("\n", $snippet);
    }
}
