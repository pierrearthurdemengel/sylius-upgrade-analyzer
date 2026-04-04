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
 * Analyseur de l'utilisation de Semantic UI dans les templates Twig.
 * Sylius 2.x abandonne Semantic UI au profit d'un systeme front-end moderne.
 * Cet analyseur detecte les classes CSS Semantic UI dans les fichiers Twig du projet.
 */
final class SemanticUiAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par fichier contenant des classes Semantic UI */
    private const MINUTES_PER_FILE = 60;

    /** URL de la documentation de migration front-end Sylius */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0/frontend';

    /** Expression reguliere pour detecter les classes CSS Semantic UI */
    private const SEMANTIC_UI_REGEX = '/class="[^"]*\bui\b[^"]*"/';

    /**
     * Motifs Semantic UI courants recherches dans les templates.
     *
     * @var list<string>
     */
    private const SEMANTIC_UI_PATTERNS = [
        'ui menu',
        'ui button',
        'ui container',
        'ui segment',
        'ui grid',
        'ui card',
        'ui form',
        'ui modal',
        'ui table',
        'ui message',
    ];

    public function getName(): string
    {
        return 'Semantic UI';
    }

    public function supports(MigrationReport $report): bool
    {
        $templatesDir = $report->getProjectPath() . '/templates';
        if (!is_dir($templatesDir)) {
            return false;
        }

        /* Verification de la presence d'au moins un fichier Twig */
        $finder = new Finder();
        $finder->files()->in($templatesDir)->name('*.twig');

        return $finder->hasResults();
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $templatesDir = $projectPath . '/templates';

        if (!is_dir($templatesDir)) {
            return;
        }

        /* Recherche de tous les fichiers Twig dans le repertoire templates/ */
        $finder = new Finder();
        $finder->files()->in($templatesDir)->name(['*.html.twig', '*.twig']);

        $affectedFiles = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = $file->getContents();

            /* Detection des classes Semantic UI via regex */
            if (preg_match(self::SEMANTIC_UI_REGEX, $content) !== 1) {
                continue;
            }

            $affectedFiles++;

            /* Identification des motifs Semantic UI specifiques trouves */
            $detectedPatterns = $this->detectPatterns($content);
            $relativePath = $file->getRelativePathname();

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::FRONTEND,
                analyzer: $this->getName(),
                message: sprintf(
                    'Classes Semantic UI detectees dans %s',
                    $relativePath,
                ),
                detail: sprintf(
                    'Le fichier %s contient des classes CSS Semantic UI : %s. '
                    . 'Sylius 2.x abandonne Semantic UI et necessite une migration '
                    . 'vers le nouveau systeme front-end.',
                    $relativePath,
                    implode(', ', $detectedPatterns),
                ),
                suggestion: 'Migrer les classes Semantic UI vers le systeme front-end '
                    . 'de Sylius 2.x. Consulter la documentation de migration pour '
                    . 'les equivalences de composants.',
                file: $filePath,
                line: $this->findFirstOccurrenceLine($content),
                codeSnippet: $this->extractSemanticUiSnippet($content),
                docUrl: self::DOC_URL,
                estimatedMinutes: self::MINUTES_PER_FILE,
            ));
        }

        /* Ajout d'un probleme de synthese si des fichiers sont impactes */
        if ($affectedFiles > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::FRONTEND,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d fichier(s) Twig contenant des classes Semantic UI detecte(s)',
                    $affectedFiles,
                ),
                detail: sprintf(
                    'Le projet contient %d fichier(s) template utilisant Semantic UI. '
                    . 'Tous ces fichiers devront etre migres vers le nouveau systeme '
                    . 'front-end de Sylius 2.x.',
                    $affectedFiles,
                ),
                suggestion: 'Planifier la migration progressive des templates Semantic UI. '
                    . 'Commencer par les pages les plus critiques (panier, tunnel d\'achat).',
                docUrl: self::DOC_URL,
            ));
        }
    }

    /**
     * Detecte les motifs Semantic UI specifiques presents dans le contenu.
     *
     * @return list<string> Liste des motifs detectes
     */
    private function detectPatterns(string $content): array
    {
        $detected = [];

        foreach (self::SEMANTIC_UI_PATTERNS as $pattern) {
            if (str_contains($content, $pattern)) {
                $detected[] = $pattern;
            }
        }

        /* Si aucun motif specifique n'est trouve, indiquer la presence generique */
        if (count($detected) === 0) {
            $detected[] = 'ui (classe generique)';
        }

        return $detected;
    }

    /**
     * Trouve le numero de ligne de la premiere occurrence d'une classe Semantic UI.
     */
    private function findFirstOccurrenceLine(string $content): int
    {
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            if (preg_match(self::SEMANTIC_UI_REGEX, $line) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * Extrait un extrait de code contenant la premiere occurrence de classe Semantic UI.
     */
    private function extractSemanticUiSnippet(string $content, int $contextLines = 2): string
    {
        $lines = explode("\n", $content);
        $targetLine = null;

        foreach ($lines as $index => $line) {
            if (preg_match(self::SEMANTIC_UI_REGEX, $line) === 1) {
                $targetLine = $index;
                break;
            }
        }

        if ($targetLine === null) {
            return '';
        }

        /* Extraction des lignes autour de la premiere occurrence */
        $start = max(0, $targetLine - $contextLines);
        $end = min(count($lines) - 1, $targetLine + $contextLines);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $snippet[] = $lines[$i];
        }

        return implode("\n", $snippet);
    }
}
