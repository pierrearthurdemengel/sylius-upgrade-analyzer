<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\TemplateDirectoryLocator;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur des evenements de blocs Sonata et des template events Sylius.
 * Sylius 2.0 remplace sonata_block_render_event() et sylius_template_event()
 * par le systeme de Twig Hooks (fonction hook()).
 * Cet analyseur detecte aussi les references a BlockEventListener dans les fichiers PHP et YAML.
 */
final class SonataBlockEventAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par usage detecte */
    private const MINUTES_PER_USAGE = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Classe BlockEventListener recherchee dans les fichiers PHP et YAML */
    private const BLOCK_EVENT_LISTENER_CLASS = 'Sylius\\Bundle\\UiBundle\\Block\\BlockEventListener';

    public function getName(): string
    {
        return 'Sonata Block Event';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification dans les templates Twig (templates/ et themes) */
        $finder = TemplateDirectoryLocator::createTwigFinder($projectPath, ['*.html.twig']);
        if ($finder !== null) {
            foreach ($finder as $file) {
                $content = (string) file_get_contents((string) $file->getRealPath());
                if (str_contains($content, 'sonata_block_render_event')
                    || str_contains($content, 'sylius_template_event')) {
                    return true;
                }
            }
        }

        /* Verification dans les fichiers PHP et YAML pour BlockEventListener */
        $dirs = [];
        if (is_dir($projectPath . '/src')) {
            $dirs[] = $projectPath . '/src';
        }
        if (is_dir($projectPath . '/config')) {
            $dirs[] = $projectPath . '/config';
        }

        if ($dirs !== []) {
            $finder = new Finder();
            $finder->files()->in($dirs)->name('*.php')->name('*.yaml')->name('*.yml');

            foreach ($finder as $file) {
                $content = (string) file_get_contents((string) $file->getRealPath());
                if (str_contains($content, self::BLOCK_EVENT_LISTENER_CLASS)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $usageCount = 0;

        /* Etape 1 : detection de sonata_block_render_event dans les templates */
        $usageCount += $this->analyzeSonataBlockEvents($report, $projectPath);

        /* Etape 2 : detection de sylius_template_event dans les templates */
        $usageCount += $this->analyzeSyliusTemplateEvents($report, $projectPath);

        /* Etape 3 : detection de BlockEventListener dans les fichiers PHP et YAML */
        $usageCount += $this->analyzeBlockEventListeners($report, $projectPath);

        /* Etape 4 : ajout d'un probleme de synthese */
        if ($usageCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::TWIG,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d usage(s) de blocs Sonata / template events detecte(s)',
                    $usageCount,
                ),
                detail: 'Les fonctions sonata_block_render_event() et sylius_template_event() '
                    . 'ainsi que BlockEventListener sont remplaces par le systeme de Twig Hooks dans Sylius 2.0.',
                suggestion: 'Migrer vers le systeme de Twig Hooks en utilisant la fonction hook() '
                    . 'et en configurant les hooks dans la configuration YAML.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $usageCount * self::MINUTES_PER_USAGE,
            ));
        }
    }

    /**
     * Detecte les appels a sonata_block_render_event() dans les templates Twig et les themes.
     * Retourne le nombre d'usages detectes.
     */
    private function analyzeSonataBlockEvents(MigrationReport $report, string $projectPath): int
    {
        $finder = TemplateDirectoryLocator::createTwigFinder($projectPath, ['*.html.twig']);
        if ($finder === null) {
            return 0;
        }

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            if (!str_contains($content, 'sonata_block_render_event')) {
                continue;
            }

            $label = TemplateDirectoryLocator::templateLabel($projectPath, $filePath);
            $lines = explode("\n", $content);
            foreach ($lines as $index => $line) {
                if (!str_contains($line, 'sonata_block_render_event')) {
                    continue;
                }

                $count++;
                $lineNumber = $index + 1;

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::TWIG,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Appel a sonata_block_render_event() detecte dans %s',
                        $label,
                    ),
                    detail: sprintf(
                        'Le template %s utilise sonata_block_render_event() ligne %d. '
                        . 'Cette fonction est supprimee dans Sylius 2.0 au profit de Twig Hooks.',
                        $label,
                        $lineNumber,
                    ),
                    suggestion: 'Remplacer sonata_block_render_event() par la fonction hook() '
                        . 'du systeme de Twig Hooks de Sylius 2.0.',
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
     * Detecte les appels a sylius_template_event() dans les templates Twig et les themes.
     * Retourne le nombre d'usages detectes.
     */
    private function analyzeSyliusTemplateEvents(MigrationReport $report, string $projectPath): int
    {
        $finder = TemplateDirectoryLocator::createTwigFinder($projectPath, ['*.html.twig']);
        if ($finder === null) {
            return 0;
        }

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            if (!str_contains($content, 'sylius_template_event')) {
                continue;
            }

            $label = TemplateDirectoryLocator::templateLabel($projectPath, $filePath);
            $lines = explode("\n", $content);
            foreach ($lines as $index => $line) {
                if (!str_contains($line, 'sylius_template_event')) {
                    continue;
                }

                $count++;
                $lineNumber = $index + 1;

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::TWIG,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Appel a sylius_template_event() detecte dans %s',
                        $label,
                    ),
                    detail: sprintf(
                        'Le template %s utilise sylius_template_event() ligne %d. '
                        . 'Cette fonction est supprimee dans Sylius 2.0 au profit de Twig Hooks.',
                        $label,
                        $lineNumber,
                    ),
                    suggestion: 'Remplacer sylius_template_event() par la fonction hook() '
                        . 'du systeme de Twig Hooks de Sylius 2.0.',
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
     * Detecte les references a BlockEventListener dans les fichiers PHP et YAML.
     * Retourne le nombre de references trouvees.
     */
    private function analyzeBlockEventListeners(MigrationReport $report, string $projectPath): int
    {
        $dirs = [];
        if (is_dir($projectPath . '/src')) {
            $dirs[] = $projectPath . '/src';
        }
        if (is_dir($projectPath . '/config')) {
            $dirs[] = $projectPath . '/config';
        }

        if ($dirs === []) {
            return 0;
        }

        $count = 0;
        $finder = new Finder();
        $finder->files()->in($dirs)->name('*.php')->name('*.yaml')->name('*.yml');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            if (!str_contains($content, self::BLOCK_EVENT_LISTENER_CLASS)) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $index => $line) {
                if (!str_contains($line, 'BlockEventListener')) {
                    continue;
                }

                $count++;
                $lineNumber = $index + 1;

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::TWIG,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Reference a BlockEventListener detectee dans %s',
                        $file->getRelativePathname(),
                    ),
                    detail: sprintf(
                        'Le fichier %s reference BlockEventListener ligne %d. '
                        . 'Cette classe est supprimee dans Sylius 2.0.',
                        $file->getRelativePathname(),
                        $lineNumber,
                    ),
                    suggestion: 'Remplacer BlockEventListener par une configuration de Twig Hooks '
                        . 'dans la configuration YAML de Sylius 2.0.',
                    file: $filePath,
                    line: $lineNumber,
                    codeSnippet: trim($line),
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }
}
