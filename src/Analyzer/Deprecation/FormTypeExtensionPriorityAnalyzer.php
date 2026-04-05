<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Analyseur des extensions de types de formulaire et de leurs priorites.
 * Sylius 2.0 necessite des priorites explicites pour les extensions de types de formulaire.
 * Les extensions sans priorite explicite peuvent ne pas fonctionner correctement.
 */
final class FormTypeExtensionPriorityAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par extension impactee */
    private const MINUTES_PER_EXTENSION = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    public function getName(): string
    {
        return 'Form Type Extension Priority';
    }

    public function supports(MigrationReport $report): bool
    {
        $srcDir = $report->getProjectPath() . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $content = (string) file_get_contents((string) $file->getRealPath());
            if (str_contains($content, 'AbstractTypeExtension')) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $extensionCount = 0;

        /* Etape 1 : detection des classes etendant AbstractTypeExtension */
        $extensionCount += $this->analyzeTypeExtensionClasses($report, $projectPath);

        /* Etape 2 : analyse des tags form.type_extension dans la configuration */
        $extensionCount += $this->analyzeFormTypeExtensionTags($report, $projectPath);

        /* Etape 3 : resume global */
        if ($extensionCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d extension(s) de type de formulaire detectee(s) sans priorite explicite',
                    $extensionCount,
                ),
                detail: 'Sylius 2.0 necessite des priorites explicites pour les extensions de types '
                    . 'de formulaire. Les extensions heritant d\'AbstractTypeExtension sans definir '
                    . 'de priorite peuvent avoir un comportement inattendu.',
                suggestion: 'Ajouter une priorite explicite a chaque extension de type de formulaire, '
                    . 'soit via le tag form.type_extension dans la configuration, '
                    . 'soit via la methode statique getExtendedTypes().',
                docUrl: self::DOC_URL,
                estimatedMinutes: $extensionCount * self::MINUTES_PER_EXTENSION,
            ));
        }
    }

    /**
     * Detecte les classes etendant AbstractTypeExtension sans priorite explicite.
     * Retourne le nombre de classes detectees.
     */
    private function analyzeTypeExtensionClasses(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            /* Visiteur pour detecter les classes heritant d'AbstractTypeExtension */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int, hasPriority: bool}> */
                public array $extensions = [];

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_ || $node->extends === null) {
                        return null;
                    }

                    $parentName = $node->extends->toString();
                    if (!str_contains($parentName, 'AbstractTypeExtension')) {
                        return null;
                    }

                    $className = $node->name !== null ? $node->name->toString() : '(anonyme)';

                    /* Verification de la presence d'une methode de priorite */
                    $hasPriority = false;
                    foreach ($node->getMethods() as $method) {
                        if ($method->name->toString() === 'getPriority'
                            || $method->name->toString() === 'getExtendedTypes'
                        ) {
                            $hasPriority = true;
                            break;
                        }
                    }

                    $this->extensions[] = [
                        'class' => $className,
                        'line' => $node->getStartLine(),
                        'hasPriority' => $hasPriority,
                    ];

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->extensions as $extension) {
                if ($extension['hasPriority']) {
                    continue;
                }

                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Extension de formulaire "%s" sans priorite explicite',
                        $extension['class'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s etend AbstractTypeExtension sans definir '
                        . 'de priorite explicite. Cela peut causer des conflits dans Sylius 2.0.',
                        $extension['class'],
                        $file->getRelativePathname(),
                    ),
                    suggestion: sprintf(
                        'Ajouter une methode getPriority() ou configurer le tag '
                        . 'form.type_extension avec une priorite pour %s.',
                        $extension['class'],
                    ),
                    file: $filePath,
                    line: $extension['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Analyse les tags form.type_extension dans la configuration YAML.
     * Retourne le nombre de tags sans priorite.
     */
    private function analyzeFormTypeExtensionTags(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config';
        if (!is_dir($configDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

        $count = 0;

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            try {
                $config = Yaml::parseFile($filePath);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($config)) {
                continue;
            }

            $services = $config['services'] ?? [];
            if (!is_array($services)) {
                continue;
            }

            foreach ($services as $serviceId => $serviceConfig) {
                if (!is_array($serviceConfig) || !isset($serviceConfig['tags'])) {
                    continue;
                }

                $tags = $serviceConfig['tags'];
                if (!is_array($tags)) {
                    continue;
                }

                foreach ($tags as $tag) {
                    if (!is_array($tag)) {
                        /* Tag simple sous forme de chaine */
                        if ($tag === 'form.type_extension') {
                            $count++;
                            $report->addIssue(new MigrationIssue(
                                severity: Severity::WARNING,
                                category: Category::DEPRECATION,
                                analyzer: $this->getName(),
                                message: sprintf(
                                    'Tag form.type_extension sans priorite pour le service "%s"',
                                    $serviceId,
                                ),
                                detail: sprintf(
                                    'Le service "%s" dans %s a un tag form.type_extension sans priorite explicite.',
                                    $serviceId,
                                    $file->getRelativePathname(),
                                ),
                                suggestion: sprintf(
                                    'Ajouter une priorite au tag form.type_extension du service "%s".',
                                    $serviceId,
                                ),
                                file: $filePath,
                                docUrl: self::DOC_URL,
                            ));
                        }
                        continue;
                    }

                    $tagName = $tag['name'] ?? '';
                    if ($tagName !== 'form.type_extension') {
                        continue;
                    }

                    if (!isset($tag['priority'])) {
                        $count++;
                        $report->addIssue(new MigrationIssue(
                            severity: Severity::WARNING,
                            category: Category::DEPRECATION,
                            analyzer: $this->getName(),
                            message: sprintf(
                                'Tag form.type_extension sans priorite pour le service "%s"',
                                $serviceId,
                            ),
                            detail: sprintf(
                                'Le service "%s" dans %s a un tag form.type_extension '
                                . 'sans attribut priority. Cela peut causer des conflits dans Sylius 2.0.',
                                $serviceId,
                                $file->getRelativePathname(),
                            ),
                            suggestion: sprintf(
                                'Ajouter priority: <valeur> au tag form.type_extension du service "%s".',
                                $serviceId,
                            ),
                            file: $filePath,
                            docUrl: self::DOC_URL,
                        ));
                    }
                }
            }
        }

        return $count;
    }
}
