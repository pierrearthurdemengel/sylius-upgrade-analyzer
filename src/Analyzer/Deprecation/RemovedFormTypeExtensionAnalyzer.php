<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
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
 * Analyseur des extensions de formulaire supprimees par Sylius 2.0.
 *
 * UPGRADE-2.0.md retire cinq extensions de formulaire du coeur ainsi que les services
 * sylius.form.extension.type.country, .customer et .locale. Une classe du projet qui herite
 * de l'une de ces extensions ne se charge plus, et une configuration qui reference l'un de
 * ces services (decoration, alias, injection) fait echouer la compilation du conteneur.
 */
final class RemovedFormTypeExtensionAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par extension ou reference de service a reprendre */
    private const MINUTES_PER_EXTENSION = 60;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Extensions de formulaire retirees de Sylius 2.0 d'apres UPGRADE-2.0.md */
    private const REMOVED_SYLIUS_EXTENSIONS = [
        'Sylius\Bundle\AdminBundle\Form\Extension\CatalogPromotionActionTypeExtension',
        'Sylius\Bundle\AdminBundle\Form\Extension\CatalogPromotionScopeTypeExtension',
        'Sylius\Bundle\CoreBundle\Form\Extension\CountryTypeExtension',
        'Sylius\Bundle\CoreBundle\Form\Extension\CustomerTypeExtension',
        'Sylius\Bundle\CoreBundle\Form\Extension\LocaleTypeExtension',
    ];

    /** Identifiants de services d'extension retires de Sylius 2.0 d'apres UPGRADE-2.0.md */
    private const REMOVED_SERVICE_IDS = [
        'sylius.form.extension.type.country',
        'sylius.form.extension.type.customer',
        'sylius.form.extension.type.locale',
    ];

    public function getName(): string
    {
        return 'Removed Form Type Extension';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        return $this->filesContain($projectPath . '/src', ['*.php'], 'TypeExtension')
            || $this->filesContain($projectPath . '/config', ['*.yaml', '*.yml'], 'sylius.form.extension.type.');
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        $extensionCount = $this->analyzeTypeExtensionClasses($report, $projectPath)
            + $this->analyzeRemovedServiceReferences($report, $projectPath);

        if ($extensionCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d extension(s) de type de formulaire a revoir',
                    $extensionCount,
                ),
                detail: 'Sylius 2.0 supprime les extensions de formulaire CountryTypeExtension, '
                    . 'CustomerTypeExtension, LocaleTypeExtension, CatalogPromotionActionTypeExtension '
                    . 'et CatalogPromotionScopeTypeExtension, ainsi que les services '
                    . 'sylius.form.extension.type.country, .customer et .locale.',
                suggestion: 'Faire heriter chaque extension concernee de AbstractTypeExtension en reprenant '
                    . 'la logique encore utile, et retirer les references aux services supprimes.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $extensionCount * self::MINUTES_PER_EXTENSION,
            ));
        }
    }

    /**
     * Detecte les classes heritant d'une extension de formulaire supprimee dans Sylius 2.0.
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

            /* Visiteur pour detecter les classes heritant d'une extension Sylius supprimee */
            $visitor = new class (self::REMOVED_SYLIUS_EXTENSIONS) extends NodeVisitorAbstract {
                /** @var list<array{class: string, parent: string, line: int}> */
                public array $extensions = [];

                /** @param list<string> $removedExtensions */
                public function __construct(private readonly array $removedExtensions)
                {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_ || $node->extends === null) {
                        return null;
                    }

                    /* Le NameResolver a deja resolu le parent en nom pleinement qualifie (use, alias) */
                    $parentName = $node->extends->toString();
                    if (!in_array($parentName, $this->removedExtensions, true)) {
                        return null;
                    }

                    $this->extensions[] = [
                        'class' => $node->name !== null ? $node->name->toString() : '(anonyme)',
                        'parent' => $parentName,
                        'line' => $node->getStartLine(),
                    ];

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->extensions as $extension) {
                $count++;
                /* Le parent n'existe plus : la classe provoque une erreur fatale au chargement */
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Extension de formulaire "%s" heritant de %s, supprimee dans Sylius 2.0',
                        $extension['class'],
                        $extension['parent'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s etend %s, retiree de Sylius 2.0. '
                        . 'La classe ne se chargera plus et le comportement herite disparait.',
                        $extension['class'],
                        $file->getRelativePathname(),
                        $extension['parent'],
                    ),
                    suggestion: sprintf(
                        'Faire heriter %s de Symfony\Component\Form\AbstractTypeExtension '
                        . 'et y reprendre la logique encore utile de %s.',
                        $extension['class'],
                        $extension['parent'],
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
     * Detecte, dans la configuration YAML des services, toute reference a un service
     * d'extension supprime : redefinition, decoration, alias ou injection ('@id').
     * Une issue est produite par service supprime et par fichier.
     */
    private function analyzeRemovedServiceReferences(MigrationReport $report, string $projectPath): int
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
                $config = Yaml::parseFile($filePath, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($config) || !is_array($config['services'] ?? null)) {
                continue;
            }

            foreach (self::REMOVED_SERVICE_IDS as $serviceId) {
                if (!$this->referencesService($config['services'], $serviceId)) {
                    continue;
                }

                $count++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Reference au service "%s", supprime dans Sylius 2.0, dans %s',
                        $serviceId,
                        $file->getRelativePathname(),
                    ),
                    detail: sprintf(
                        'Le fichier %s reference le service %s, retire de Sylius 2.0 avec son extension '
                        . 'de formulaire. La compilation du conteneur echouera.',
                        $file->getRelativePathname(),
                        $serviceId,
                    ),
                    suggestion: sprintf(
                        'Supprimer la reference a %s et porter la logique necessaire dans une extension '
                        . 'de formulaire du projet.',
                        $serviceId,
                    ),
                    file: $filePath,
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $count;
    }

    /**
     * Indique si une valeur de configuration (cle ou valeur, a toute profondeur) reference
     * le service donne : identifiant exact ou injection '@id' / '@?id'.
     */
    private function referencesService(mixed $value, string $serviceId): bool
    {
        if (is_string($value)) {
            return in_array($value, [$serviceId, '@' . $serviceId, '@?' . $serviceId], true);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if ($key === $serviceId || $this->referencesService($item, $serviceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indique si au moins un fichier du repertoire contient la chaine recherchee.
     *
     * @param list<string> $namePatterns
     */
    private function filesContain(string $directory, array $namePatterns, string $needle): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($directory)->name($namePatterns);

        foreach ($finder as $file) {
            if (str_contains((string) file_get_contents((string) $file->getRealPath()), $needle)) {
                return true;
            }
        }

        return false;
    }
}
