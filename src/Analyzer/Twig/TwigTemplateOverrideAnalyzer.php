<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\TemplateDirectoryLocator;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Analyseur de surcharges de templates Twig.
 * Détecte les templates Sylius surchargés qui devront être migrés vers le système de hooks Twig.
 *
 * L'estimation porte sur la personnalisation à reporter, pas sur le template entier :
 * quand l'original Sylius est présent dans vendor/, seul l'écart avec lui est compté.
 * Une copie à l'identique est simplement supprimée en Sylius 2.x, et un template
 * surchargé dans plusieurs thèmes n'est compté en entier qu'une fois.
 */
final class TwigTemplateOverrideAnalyzer implements AnalyzerInterface
{
    /*
     * Barème selon le nombre de lignes personnalisées par rapport à la référence.
     * Une retouche de quelques lignes se reporte en un quart d'heure dans un hook ;
     * au-delà de 100 lignes, la page est en pratique réécrite.
     */
    private const MINUTES_TINY_CHANGE = 15;
    private const MINUTES_SMALL_CHANGE = 30;
    private const MINUTES_MEDIUM_CHANGE = 60;
    private const MINUTES_LARGE_CHANGE = 120;

    private const MAX_LINES_TINY_CHANGE = 5;
    private const MAX_LINES_SMALL_CHANGE = 30;
    private const MAX_LINES_MEDIUM_CHANGE = 100;

    private const DOC_URL = 'https://docs.sylius.com/en/latest/the-book/frontend/twig-hooks.html';

    private readonly TwigHookMigrationMapper $mapper;

    public function __construct(?TwigHookMigrationMapper $mapper = null)
    {
        $this->mapper = $mapper ?? new TwigHookMigrationMapper();
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        /* Premier exemplaire de chaque template, référence des variantes portées par les autres thèmes */
        $firstCopies = [];

        foreach (TemplateOverrideInventory::findOverrideFiles($projectPath) as $templateFile) {
            $absolutePath = $templateFile->getRealPath();
            $relativePath = TemplateDirectoryLocator::relativePath($projectPath, (string) $absolutePath);
            $bundlePath = TemplateOverrideInventory::bundlePath($relativePath);
            $theme = $this->extractThemeName($projectPath, $absolutePath);
            $content = $templateFile->getContents();
            $location = $theme === null ? $bundlePath : sprintf('%s (thème %s)', $bundlePath, $theme);

            $hookMapping = $this->mapper->mapTemplateToHook($bundlePath);
            $hookName = $hookMapping['hook'] ?? 'hook non répertorié';
            $original = TemplateOverrideInventory::findVendorOriginal($projectPath, $bundlePath);

            /* Une copie à l'identique n'a rien à migrer : elle est supprimée */
            if ($original !== null && TemplateOverrideInventory::changedLines($content, $original) === 0) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::SUGGESTION,
                    category: Category::TWIG,
                    analyzer: $this->getName(),
                    message: sprintf('Copie à l\'identique d\'un template Sylius : %s', $location),
                    detail: 'Ce fichier reproduit le template Sylius d\'origine sans modification. '
                        . 'Il n\'y a aucune personnalisation à reporter dans les hooks Twig.',
                    suggestion: 'Supprimer ce fichier lors de la migration vers Sylius 2.x.',
                    file: $relativePath,
                    line: 1,
                    docUrl: self::DOC_URL,
                ));

                continue;
            }

            [$estimatedMinutes, $basis] = $this->estimate($bundlePath, $content, $original);

            /* Variante d'un template déjà compté dans un autre thème : seul l'écart avec lui est à porter */
            $firstCopy = $firstCopies[$bundlePath] ?? null;
            if ($firstCopy !== null) {
                $siblingMinutes = $this->minutesForChangedLines(
                    TemplateOverrideInventory::changedLines($content, $firstCopy['content']),
                );
                if ($siblingMinutes < $estimatedMinutes) {
                    $estimatedMinutes = $siblingMinutes;
                    $basis = $siblingMinutes === 0
                        ? sprintf('Identique à %s, déjà compté.', $firstCopy['path'])
                        : sprintf('Variante de %s, déjà compté : seul l\'écart est estimé.', $firstCopy['path']);
                }
            } else {
                $firstCopies[$bundlePath] = ['content' => $content, 'path' => $relativePath];
            }

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::TWIG,
                analyzer: $this->getName(),
                message: sprintf('Template Twig surchargé détecté : %s', $location),
                detail: sprintf(
                    'Ce template surcharge un template Sylius natif. '
                    . 'Dans Sylius 2.x, le système de templates a été remplacé par des hooks Twig. '
                    . 'Le hook correspondant est : %s. %s',
                    $hookName,
                    $basis,
                ),
                suggestion: sprintf(
                    'Migrer cette surcharge vers le système de hooks Twig. '
                    . 'Créer un hook Twig utilisant "%s" et supprimer la surcharge de template. '
                    . 'Consulter la documentation de migration Sylius pour les détails.',
                    $hookName,
                ),
                file: $relativePath,
                line: 1,
                codeSnippet: $this->extractSnippet($content),
                docUrl: self::DOC_URL,
                estimatedMinutes: $estimatedMinutes,
            ));
        }
    }

    public function getName(): string
    {
        return 'Twig Template Override';
    }

    public function supports(MigrationReport $report): bool
    {
        return TemplateOverrideInventory::hasOverrideDirectories($report->getProjectPath());
    }

    /**
     * Estime la reprise d'une surcharge et explique la base de calcul.
     *
     * Sans original dans vendor/ (vendor/ absent, ou template propre au projet),
     * l'estimation retombe sur la complexité du template entier. Avec l'original,
     * elle porte sur les lignes personnalisées, plafonnée par cette même complexité :
     * reporter une personnalisation ne coûte jamais plus que migrer la page entière.
     *
     * @return array{int, string} Minutes estimées et explication
     */
    private function estimate(string $bundlePath, string $content, ?string $original): array
    {
        $hookMapping = $this->mapper->mapTemplateToHook($bundlePath);
        $contentMinutes = $this->mapper->getComplexityMinutes($bundlePath, $content);
        $wholeTemplateMinutes = $hookMapping !== null
            ? max($hookMapping['minutes'], $contentMinutes)
            : $contentMinutes;

        if ($original === null) {
            return [
                $wholeTemplateMinutes,
                'Template d\'origine introuvable dans vendor/ : estimation sur le template entier.',
            ];
        }

        $changedLines = TemplateOverrideInventory::changedLines($content, $original);

        return [
            min($wholeTemplateMinutes, $this->minutesForChangedLines($changedLines)),
            sprintf('%d ligne(s) personnalisée(s) par rapport au template d\'origine.', $changedLines),
        ];
    }

    /**
     * Convertit un volume de lignes personnalisées en minutes selon le barème.
     */
    private function minutesForChangedLines(int $changedLines): int
    {
        return match (true) {
            $changedLines === 0 => 0,
            $changedLines <= self::MAX_LINES_TINY_CHANGE => self::MINUTES_TINY_CHANGE,
            $changedLines <= self::MAX_LINES_SMALL_CHANGE => self::MINUTES_SMALL_CHANGE,
            $changedLines <= self::MAX_LINES_MEDIUM_CHANGE => self::MINUTES_MEDIUM_CHANGE,
            default => self::MINUTES_LARGE_CHANGE,
        };
    }

    /**
     * Retourne le chemin du thème (relatif au projet) qui contient le fichier,
     * ou null si le fichier n'appartient à aucun thème.
     * Par exemple : themes/BootstrapTheme
     */
    private function extractThemeName(string $projectPath, string|false $absolutePath): ?string
    {
        if ($absolutePath === false) {
            return null;
        }

        $normalized = str_replace('\\', '/', $absolutePath);

        foreach (TemplateDirectoryLocator::locateThemeRoots($projectPath) as $root) {
            if (!str_starts_with($normalized, $root . '/')) {
                continue;
            }

            /* Le thème est le premier segment situé sous la racine des thèmes */
            $segments = explode('/', substr($normalized, strlen($root) + 1));

            return TemplateDirectoryLocator::relativePath($projectPath, $root . '/' . $segments[0]);
        }

        return null;
    }

    /**
     * Extrait un extrait de code des premières lignes significatives du template.
     */
    private function extractSnippet(string $content, int $maxLines = 5): string
    {
        $lines = explode("\n", $content);
        $significantLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $significantLines[] = $line;
            if (count($significantLines) >= $maxLines) {
                break;
            }
        }

        return implode("\n", $significantLines);
    }
}
