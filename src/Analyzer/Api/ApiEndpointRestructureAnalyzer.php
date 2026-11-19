<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Api;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\TemplateDirectoryLocator;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur de restructuration des endpoints API.
 * Detecte les references aux anciens chemins d'endpoints API dans les fichiers PHP,
 * les templates Twig, les fichiers JS et les fichiers de configuration.
 * Sylius 2.0 modifie ou supprime plusieurs endpoints de l'API v2.
 */
final class ApiEndpointRestructureAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par reference a un ancien endpoint */
    private const MINUTES_PER_ENDPOINT = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /**
     * Correspondance entre les anciens endpoints et les nouveaux (ou suppression).
     *
     * @var array<string, string>
     */
    private const OLD_ENDPOINTS = [
        '/api/v2/admin/avatar-images/' => '/api/v2/admin/administrators/{id}/avatar-image',
        '/api/v2/shop/reset-password-requests' => '/api/v2/shop/reset-password',
        '/api/v2/shop/account-verification-requests' => '/api/v2/shop/verify-shop-user',
        '/api/v2/admin/gateway-configs' => 'removed',
        '/api/v2/admin/channel-price-history-configs' => 'removed (embedded in Channel)',
        '/api/v2/admin/shop-billing-datas' => 'removed (embedded in Channel)',
        '/api/v2/admin/zone-members' => 'removed (embedded in Zone)',
        '/api/v2/admin/order-item-units' => 'removed',
    ];

    /** Ancien nom de route API Platform a detecter */
    private const OLD_ROUTE_NAME = 'api_platform.action.post_item';

    public function getName(): string
    {
        return 'API Endpoint Restructure';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification dans les fichiers PHP */
        if ($this->hasEndpointReferencesInDirectory($projectPath . '/src', ['*.php'])) {
            return true;
        }

        /* Verification dans les templates Twig */
        if ($this->hasEndpointReferencesInDirectory($projectPath . '/templates', ['*.twig', '*.html.twig'])) {
            return true;
        }

        /* Verification dans les themes SyliusThemeBundle */
        foreach (TemplateDirectoryLocator::locateThemeRoots($projectPath) as $themeRoot) {
            if ($this->hasEndpointReferencesInDirectory($themeRoot, ['*.twig', '*.html.twig'])) {
                return true;
            }
        }

        /* Verification dans les fichiers JS */
        if ($this->hasEndpointReferencesInDirectory($projectPath . '/assets', ['*.js', '*.ts'])) {
            return true;
        }

        /* Verification dans les fichiers de configuration */
        if ($this->hasEndpointReferencesInDirectory($projectPath . '/config', ['*.yaml', '*.yml', '*.xml'])) {
            return true;
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $totalReferences = 0;

        /* Etape 1 : analyse des fichiers PHP dans src/ */
        $totalReferences += $this->analyzeDirectory(
            $report,
            $projectPath . '/src',
            ['*.php'],
            'src/',
        );

        /* Etape 2 : analyse des templates Twig */
        $totalReferences += $this->analyzeDirectory(
            $report,
            $projectPath . '/templates',
            ['*.twig', '*.html.twig'],
            'templates/',
        );

        /* Etape 2 bis : analyse des templates Twig des themes */
        foreach (TemplateDirectoryLocator::locateThemeRoots($projectPath) as $themeRoot) {
            $totalReferences += $this->analyzeDirectory(
                $report,
                $themeRoot,
                ['*.twig', '*.html.twig'],
                TemplateDirectoryLocator::relativePath($projectPath, $themeRoot) . '/',
            );
        }

        /* Etape 3 : analyse des fichiers JS */
        $totalReferences += $this->analyzeDirectory(
            $report,
            $projectPath . '/assets',
            ['*.js', '*.ts'],
            'assets/',
        );

        /* Etape 4 : analyse des fichiers de configuration */
        $totalReferences += $this->analyzeDirectory(
            $report,
            $projectPath . '/config',
            ['*.yaml', '*.yml', '*.xml'],
            'config/',
        );

        /* Etape 5 : resume global */
        if ($totalReferences > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::API,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d reference(s) a d\'anciens endpoints API detectee(s)',
                    $totalReferences,
                ),
                detail: 'Le projet contient des references a des endpoints API qui ont ete '
                    . 'restructures ou supprimes dans Sylius 2.0. Ces references doivent '
                    . 'etre mises a jour pour eviter des erreurs au runtime.',
                suggestion: 'Mettre a jour les chemins d\'endpoints API selon la nouvelle '
                    . 'structure de Sylius 2.0. Les endpoints supprimes doivent etre '
                    . 'remplaces par les nouvelles ressources imbriquees.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $totalReferences * self::MINUTES_PER_ENDPOINT,
            ));
        }
    }

    /**
     * Verifie si un repertoire contient des references aux anciens endpoints.
     *
     * @param list<string> $filePatterns Motifs de noms de fichiers a rechercher
     */
    private function hasEndpointReferencesInDirectory(string $directory, array $filePatterns): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($directory);
        foreach ($filePatterns as $pattern) {
            $finder->name($pattern);
        }

        foreach ($finder as $file) {
            $content = $file->getContents();

            foreach (array_keys(self::OLD_ENDPOINTS) as $oldEndpoint) {
                if (str_contains($content, $oldEndpoint)) {
                    return true;
                }
            }

            if (str_contains($content, self::OLD_ROUTE_NAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyse un repertoire pour detecter les references aux anciens endpoints.
     * Retourne le nombre total de references trouvees.
     *
     * @param list<string> $filePatterns Motifs de noms de fichiers a rechercher
     * @param string        $prefixLabel Prefixe pour les chemins relatifs dans les messages
     */
    private function analyzeDirectory(
        MigrationReport $report,
        string $directory,
        array $filePatterns,
        string $prefixLabel,
    ): int {
        if (!is_dir($directory)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($directory);
        foreach ($filePatterns as $pattern) {
            $finder->name($pattern);
        }

        $totalReferences = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = $file->getContents();
            $lines = explode("\n", $content);
            $relativePath = $prefixLabel . $file->getRelativePathname();

            /* Detection des anciens endpoints */
            $totalReferences += $this->detectOldEndpoints(
                $report,
                $lines,
                $filePath,
                $relativePath,
            );

            /* Detection de l'ancien nom de route */
            $totalReferences += $this->detectOldRouteName(
                $report,
                $lines,
                $filePath,
                $relativePath,
            );
        }

        return $totalReferences;
    }

    /**
     * Detecte les references aux anciens endpoints dans les lignes d'un fichier.
     * Retourne le nombre de references trouvees.
     *
     * @param list<string> $lines Lignes du fichier
     */
    private function detectOldEndpoints(
        MigrationReport $report,
        array $lines,
        string $filePath,
        string $relativePath,
    ): int {
        $count = 0;

        foreach ($lines as $index => $line) {
            foreach (self::OLD_ENDPOINTS as $oldEndpoint => $replacement) {
                if (!str_contains($line, $oldEndpoint)) {
                    continue;
                }

                $count++;
                $lineNumber = $index + 1;

                $isRemoved = str_starts_with($replacement, 'removed');
                $severity = $isRemoved ? Severity::BREAKING : Severity::BREAKING;

                $report->addIssue(new MigrationIssue(
                    severity: $severity,
                    category: Category::API,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Ancien endpoint API detecte dans %s ligne %d : %s',
                        $relativePath,
                        $lineNumber,
                        $oldEndpoint,
                    ),
                    detail: sprintf(
                        'Le fichier %s reference l\'ancien endpoint %s a la ligne %d. '
                        . 'Dans Sylius 2.0, cet endpoint a ete %s.',
                        $relativePath,
                        $oldEndpoint,
                        $lineNumber,
                        $isRemoved
                            ? 'supprime (' . $replacement . ')'
                            : 'deplace vers ' . $replacement,
                    ),
                    suggestion: $isRemoved
                        ? sprintf(
                            'L\'endpoint %s a ete supprime dans Sylius 2.0. '
                            . 'Utiliser les nouvelles ressources imbriquees a la place.',
                            $oldEndpoint,
                        )
                        : sprintf(
                            'Remplacer %s par %s.',
                            $oldEndpoint,
                            $replacement,
                        ),
                    file: $filePath,
                    line: $lineNumber,
                    codeSnippet: trim($line),
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Detecte les references a l'ancien nom de route API Platform.
     * Retourne le nombre de references trouvees.
     *
     * @param list<string> $lines Lignes du fichier
     */
    private function detectOldRouteName(
        MigrationReport $report,
        array $lines,
        string $filePath,
        string $relativePath,
    ): int {
        $count = 0;

        foreach ($lines as $index => $line) {
            if (!str_contains($line, self::OLD_ROUTE_NAME)) {
                continue;
            }

            $count++;
            $lineNumber = $index + 1;

            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::API,
                analyzer: $this->getName(),
                message: sprintf(
                    'Ancien nom de route API Platform detecte dans %s ligne %d',
                    $relativePath,
                    $lineNumber,
                ),
                detail: sprintf(
                    'Le fichier %s utilise l\'ancien nom de route %s a la ligne %d. '
                    . 'Cette route n\'existe plus dans API Platform 3.x utilise par Sylius 2.0.',
                    $relativePath,
                    self::OLD_ROUTE_NAME,
                    $lineNumber,
                ),
                suggestion: 'Remplacer la reference a ' . self::OLD_ROUTE_NAME
                    . ' par les nouvelles actions ou controllers API Platform 3.x.',
                file: $filePath,
                line: $lineNumber,
                codeSnippet: trim($line),
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }
}
