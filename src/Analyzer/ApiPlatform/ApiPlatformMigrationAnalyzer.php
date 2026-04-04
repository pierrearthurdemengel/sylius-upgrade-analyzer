<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\ApiPlatform;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur de la migration API Platform.
 * Detecte les usages de l'ancienne API Platform (annotations, DataProvider, DataPersister,
 * namespace ApiPlatform\Core) et les configurations YAML api_platform.
 * Sylius 2.x utilise API Platform 3.x avec les attributs PHP 8.
 */
final class ApiPlatformMigrationAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par ressource API Platform impactee */
    private const MINUTES_PER_RESOURCE = 120;

    /** URL de la documentation de migration API Platform */
    private const DOC_URL = 'https://api-platform.com/docs/core/upgrade-guide/';

    /**
     * Expression reguliere pour detecter l'annotation @ApiResource dans les docblocks.
     */
    private const API_RESOURCE_ANNOTATION_REGEX = '/@ApiResource\b/';

    /**
     * Interfaces obsoletes de l'ancienne API Platform (DataProvider/DataPersister).
     *
     * @var array<string, string>
     */
    private const LEGACY_INTERFACES = [
        'DataProviderInterface' => 'Remplacer DataProviderInterface par un StateProvider (API Platform 3.x).',
        'CollectionDataProviderInterface' => 'Remplacer CollectionDataProviderInterface par un StateProvider avec l\'operation GetCollection.',
        'ItemDataProviderInterface' => 'Remplacer ItemDataProviderInterface par un StateProvider avec l\'operation Get.',
        'SubresourceDataProviderInterface' => 'Remplacer SubresourceDataProviderInterface par un StateProvider avec les liens API Platform.',
        'DataPersisterInterface' => 'Remplacer DataPersisterInterface par un StateProcessor (API Platform 3.x).',
        'ContextAwareDataPersisterInterface' => 'Remplacer ContextAwareDataPersisterInterface par un StateProcessor.',
    ];

    /**
     * Namespace obsolete d'API Platform 2.x/3.x ancien.
     */
    private const LEGACY_NAMESPACE = 'ApiPlatform\\Core';

    public function getName(): string
    {
        return 'API Platform Migration';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verification de la presence de fichiers PHP avec ApiResource dans src/ */
        $srcDir = $projectPath . '/src';
        if (is_dir($srcDir)) {
            $finder = new Finder();
            $finder->files()->in($srcDir)->name('*.php');

            foreach ($finder as $file) {
                $content = $file->getContents();

                /* Detection de l'annotation @ApiResource ou de l'attribut #[ApiResource] */
                if (
                    preg_match(self::API_RESOURCE_ANNOTATION_REGEX, $content) === 1
                    || str_contains($content, '#[ApiResource')
                ) {
                    return true;
                }
            }
        }

        /* Verification de la presence de la configuration api_platform.yaml */
        $configDir = $projectPath . '/config';
        if (is_dir($configDir)) {
            $finder = new Finder();
            $finder->files()->in($configDir)->name('api_platform.yaml')->name('api_platform.yml');

            if ($finder->hasResults()) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $totalResources = 0;

        /* Etape 1 : analyse des fichiers PHP dans src/ */
        $totalResources += $this->analyzePhpFiles($report, $projectPath);

        /* Etape 2 : analyse de la configuration YAML */
        $totalResources += $this->analyzeYamlConfiguration($report, $projectPath);

        /* Etape 3 : ajout d'un probleme de synthese si des ressources sont impactees */
        if ($totalResources > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::API,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d ressource(s) API Platform necessitant une migration detectee(s)',
                    $totalResources,
                ),
                detail: sprintf(
                    'Le projet contient %d ressource(s) ou composant(s) API Platform '
                    . 'utilisant des fonctionnalites obsoletes (annotations, DataProvider, '
                    . 'DataPersister, namespace ApiPlatform\\Core). '
                    . 'Ces elements doivent etre migres vers API Platform 3.x.',
                    $totalResources,
                ),
                suggestion: 'Migrer vers les attributs PHP 8 #[ApiResource], remplacer '
                    . 'les DataProvider par des StateProvider et les DataPersister par des '
                    . 'StateProcessor. Mettre a jour les namespaces ApiPlatform\\Core '
                    . 'vers ApiPlatform\\Metadata et ApiPlatform\\State.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $totalResources * self::MINUTES_PER_RESOURCE,
            ));
        }
    }

    /**
     * Analyse les fichiers PHP dans src/ pour detecter les usages API Platform obsoletes.
     * Retourne le nombre de ressources impactees.
     */
    private function analyzePhpFiles(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $totalResources = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $content = $file->getContents();
            $relativePath = $file->getRelativePathname();

            /* Detection des annotations @ApiResource dans les docblocks */
            $annotationCount = $this->detectAnnotations($report, $content, $filePath, $relativePath);
            $totalResources += $annotationCount;

            /* Analyse de l'AST avec php-parser */
            try {
                $ast = $parser->parse($content);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            /* Visiteur pour detecter les attributs, interfaces et namespaces obsoletes */
            $legacyInterfaces = self::LEGACY_INTERFACES;
            $legacyNamespace = self::LEGACY_NAMESPACE;

            $visitor = new class ($legacyInterfaces, $legacyNamespace) extends NodeVisitorAbstract {
                /** @var list<array{type: string, name: string, line: int}> */
                public array $issues = [];

                /**
                 * @param array<string, string> $legacyInterfaces
                 */
                public function __construct(
                    private readonly array $legacyInterfaces,
                    private readonly string $legacyNamespace,
                ) {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection de l'attribut #[ApiResource] PHP 8 */
                    if ($node instanceof Node\Stmt\Class_) {
                        foreach ($node->attrGroups as $attrGroup) {
                            foreach ($attrGroup->attrs as $attr) {
                                $attrName = $attr->name->toString();
                                if ($attrName === 'ApiResource' || str_ends_with($attrName, '\\ApiResource')) {
                                    $this->issues[] = [
                                        'type' => 'attribute',
                                        'name' => 'ApiResource',
                                        'line' => $attr->getStartLine(),
                                    ];
                                }
                            }
                        }
                    }

                    /* Detection de l'implementation d'interfaces obsoletes */
                    if ($node instanceof Node\Stmt\Class_ && $node->implements !== []) {
                        foreach ($node->implements as $interface) {
                            $interfaceName = $interface->toString();
                            $shortName = $this->getShortName($interfaceName);

                            if (isset($this->legacyInterfaces[$shortName])) {
                                $this->issues[] = [
                                    'type' => 'interface',
                                    'name' => $shortName,
                                    'line' => $interface->getStartLine(),
                                ];
                            }
                        }
                    }

                    /* Detection de l'utilisation du namespace ApiPlatform\Core */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $useName = $use->name->toString();
                            if (str_starts_with($useName, $this->legacyNamespace)) {
                                $this->issues[] = [
                                    'type' => 'namespace',
                                    'name' => $useName,
                                    'line' => $use->getStartLine(),
                                ];
                            }
                        }
                    }

                    return null;
                }

                /**
                 * Extrait le nom court d'un FQCN.
                 */
                private function getShortName(string $fqcn): string
                {
                    $parts = explode('\\', $fqcn);

                    return end($parts);
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            /* Creation des problemes pour chaque element detecte */
            foreach ($visitor->issues as $issue) {
                $totalResources++;

                match ($issue['type']) {
                    'attribute' => $this->addAttributeIssue($report, $filePath, $relativePath, $issue['line']),
                    'interface' => $this->addInterfaceIssue($report, $filePath, $relativePath, $issue['name'], $issue['line']),
                    'namespace' => $this->addNamespaceIssue($report, $filePath, $relativePath, $issue['name'], $issue['line']),
                    default => null,
                };
            }
        }

        return $totalResources;
    }

    /**
     * Detecte les annotations @ApiResource dans les docblocks PHP.
     * Retourne le nombre d'annotations trouvees.
     */
    private function detectAnnotations(
        MigrationReport $report,
        string $content,
        string $filePath,
        string $relativePath,
    ): int {
        $count = 0;
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            if (preg_match(self::API_RESOURCE_ANNOTATION_REGEX, $line) !== 1) {
                continue;
            }

            $count++;
            $lineNumber = $index + 1;

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::API,
                analyzer: $this->getName(),
                message: sprintf(
                    'Annotation @ApiResource detectee dans %s',
                    $relativePath,
                ),
                detail: sprintf(
                    'L\'annotation @ApiResource (Doctrine annotations) est utilisee '
                    . 'dans %s ligne %d. Les annotations Doctrine sont obsoletes '
                    . 'et doivent etre remplacees par des attributs PHP 8.',
                    $relativePath,
                    $lineNumber,
                ),
                suggestion: 'Convertir l\'annotation @ApiResource en attribut PHP 8 '
                    . '#[ApiResource(...)]. Adapter les options selon la syntaxe '
                    . 'API Platform 3.x.',
                file: $filePath,
                line: $lineNumber,
                codeSnippet: trim($line),
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Analyse les fichiers de configuration YAML api_platform.
     * Retourne le nombre de configurations trouvees.
     */
    private function analyzeYamlConfiguration(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return 0;
        }

        $count = 0;
        $finder = new Finder();
        $finder->files()->in($configDir)->name(['api_platform.yaml', 'api_platform.yml'])->depth('< 3');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $count++;
            $relativePath = 'config/' . $file->getRelativePathname();

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::API,
                analyzer: $this->getName(),
                message: sprintf(
                    'Configuration API Platform YAML detectee : %s',
                    $relativePath,
                ),
                detail: sprintf(
                    'Le fichier %s contient une configuration API Platform en YAML. '
                    . 'Verifier la compatibilite avec API Platform 3.x et adapter '
                    . 'les options obsoletes (itemOperations, collectionOperations, etc.).',
                    $relativePath,
                ),
                suggestion: 'Migrer la configuration YAML vers les attributs PHP 8 '
                    . '#[ApiResource] directement dans les entites, ou mettre a jour '
                    . 'la syntaxe YAML pour API Platform 3.x '
                    . '(operations au lieu de itemOperations/collectionOperations).',
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Ajoute un probleme lie a un attribut #[ApiResource] detecte.
     */
    private function addAttributeIssue(
        MigrationReport $report,
        string $filePath,
        string $relativePath,
        int $line,
    ): void {
        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::API,
            analyzer: $this->getName(),
            message: sprintf(
                'Attribut #[ApiResource] detecte dans %s',
                $relativePath,
            ),
            detail: sprintf(
                'L\'attribut #[ApiResource] dans %s ligne %d doit etre verifie '
                . 'pour la compatibilite avec API Platform 3.x. '
                . 'Les options itemOperations/collectionOperations sont obsoletes.',
                $relativePath,
                $line,
            ),
            suggestion: 'Verifier et adapter l\'attribut #[ApiResource] pour utiliser '
                . 'la syntaxe API Platform 3.x : remplacer itemOperations et '
                . 'collectionOperations par le parametre operations.',
            file: $filePath,
            line: $line,
            docUrl: self::DOC_URL,
        ));
    }

    /**
     * Ajoute un probleme lie a une interface obsolete (DataProvider/DataPersister).
     */
    private function addInterfaceIssue(
        MigrationReport $report,
        string $filePath,
        string $relativePath,
        string $interfaceName,
        int $line,
    ): void {
        $suggestion = self::LEGACY_INTERFACES[$interfaceName]
            ?? 'Remplacer par l\'equivalent API Platform 3.x.';

        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::API,
            analyzer: $this->getName(),
            message: sprintf(
                'Interface obsolete %s detectee dans %s',
                $interfaceName,
                $relativePath,
            ),
            detail: sprintf(
                'L\'interface %s est implementee dans %s ligne %d. '
                . 'Cette interface est obsolete dans API Platform 3.x '
                . 'et doit etre remplacee par StateProvider ou StateProcessor.',
                $interfaceName,
                $relativePath,
                $line,
            ),
            suggestion: $suggestion,
            file: $filePath,
            line: $line,
            docUrl: self::DOC_URL,
        ));
    }

    /**
     * Ajoute un probleme lie a l'utilisation du namespace ApiPlatform\Core.
     */
    private function addNamespaceIssue(
        MigrationReport $report,
        string $filePath,
        string $relativePath,
        string $namespaceName,
        int $line,
    ): void {
        $report->addIssue(new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::API,
            analyzer: $this->getName(),
            message: sprintf(
                'Namespace obsolete ApiPlatform\\Core detecte dans %s',
                $relativePath,
            ),
            detail: sprintf(
                'L\'import %s dans %s ligne %d utilise le namespace '
                . 'ApiPlatform\\Core qui est obsolete dans API Platform 3.x.',
                $namespaceName,
                $relativePath,
                $line,
            ),
            suggestion: 'Mettre a jour le namespace de ApiPlatform\\Core vers '
                . 'ApiPlatform\\Metadata (pour les attributs) ou '
                . 'ApiPlatform\\State (pour les providers/processors).',
            file: $filePath,
            line: $line,
            docUrl: self::DOC_URL,
        ));
    }
}
