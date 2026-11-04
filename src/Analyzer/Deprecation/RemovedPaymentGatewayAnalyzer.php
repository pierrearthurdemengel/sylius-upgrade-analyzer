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
 * Analyseur des passerelles de paiement supprimees.
 * Les packages payum/stripe et payum/paypal-express-checkout ne sont plus supportes dans Sylius 2.0.
 * Cet analyseur detecte ces dependances et les configurations associees.
 */
final class RemovedPaymentGatewayAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par passerelle supprimee */
    private const MINUTES_PER_GATEWAY = 480;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /**
     * Packages Payum supprimes. Le nom publie sur Packagist est payum/paypal-express-checkout-nvp
     * (c'est lui que UPGRADE-2.0.md retire du composer.json) ; l'ancienne forme reste reconnue.
     */
    private const REMOVED_PACKAGES = [
        'payum/stripe' => 'Stripe via Payum',
        'payum/paypal-express-checkout-nvp' => 'PayPal Express Checkout via Payum',
        'payum/paypal-express-checkout' => 'PayPal Express Checkout via Payum',
    ];

    /** Noms de factory de passerelles supprimees */
    private const REMOVED_GATEWAY_FACTORIES = [
        'stripe_checkout',
        'stripe_js',
        'paypal_express_checkout',
    ];

    /**
     * Espaces de noms des passerelles Payum retirees de Sylius 2.0 (payum/stripe et
     * payum/paypal-express-checkout-nvp). Les autres classes "Stripe" ou "PayPal"
     * (SDK stripe/stripe-php, sylius/paypal-plugin, payum/paypal-rest) ne sont pas concernees.
     */
    private const REMOVED_NAMESPACES = [
        'Payum\\Stripe\\',
        'Payum\\Paypal\\ExpressCheckout\\Nvp\\',
    ];

    public function getName(): string
    {
        return 'Removed Payment Gateway';
    }

    public function supports(MigrationReport $report): bool
    {
        $composerJsonPath = $report->getProjectPath() . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return false;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);
        if (!is_array($composerData)) {
            return false;
        }

        $require = array_merge(
            $composerData['require'] ?? [],
            $composerData['require-dev'] ?? [],
        );

        foreach (array_keys(self::REMOVED_PACKAGES) as $package) {
            if (isset($require[$package])) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $gatewayCount = 0;

        /* Etape 1 : verification dans composer.json */
        $gatewayCount += $this->analyzeComposerJson($report, $projectPath);

        /* Etape 2 : detection des noms de factory dans la configuration */
        $gatewayCount += $this->analyzeGatewayConfigurations($report, $projectPath);

        /* Etape 3 : detection des classes etendant les gateways Payum */
        $gatewayCount += $this->analyzeExtendingClasses($report, $projectPath);

        /* Etape 4 : resume global avec estimation */
        if ($gatewayCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d passerelle(s) de paiement supprimee(s) detectee(s)',
                    $gatewayCount,
                ),
                detail: 'Les passerelles Payum Stripe et PayPal Express Checkout ont ete supprimees de Sylius 2.0. '
                    . 'Elles doivent etre remplacees par les plugins officiels ou des integrations directes.',
                suggestion: 'Migrer vers les plugins officiels Sylius (sylius/paypal-plugin, sylius/stripe-plugin) '
                    . 'ou implementer une integration directe avec les API modernes.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $gatewayCount * self::MINUTES_PER_GATEWAY,
            ));
        }
    }

    /**
     * Verifie la presence des packages Payum supprimes dans composer.json.
     * Retourne le nombre de packages trouves.
     */
    private function analyzeComposerJson(MigrationReport $report, string $projectPath): int
    {
        $composerJsonPath = $projectPath . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return 0;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);
        if (!is_array($composerData)) {
            return 0;
        }

        $require = array_merge(
            $composerData['require'] ?? [],
            $composerData['require-dev'] ?? [],
        );

        $count = 0;
        foreach (self::REMOVED_PACKAGES as $package => $label) {
            if (!isset($require[$package])) {
                continue;
            }

            $count++;
            $version = $require[$package];
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf('Dependance %s (%s) detectee dans composer.json', $package, $version),
                detail: sprintf(
                    'Le package %s (%s) n\'est plus supporte dans Sylius 2.0. '
                    . 'La passerelle %s doit etre remplacee par une alternative moderne.',
                    $package,
                    $version,
                    $label,
                ),
                suggestion: sprintf(
                    'Supprimer %s et migrer vers le plugin officiel Sylius ou une integration directe.',
                    $package,
                ),
                file: $composerJsonPath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Detecte les noms de factory de passerelles supprimees dans la configuration YAML.
     * Retourne le nombre de configurations trouvees.
     */
    private function analyzeGatewayConfigurations(MigrationReport $report, string $projectPath): int
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

            if (!is_array($config)) {
                continue;
            }

            /* Seules les valeurs de cle "factory" comptent : un commentaire ou un identifiant de
               service contenant "stripe_js" ne configure aucune passerelle. */
            $factories = $this->collectFactoryValues($config);

            foreach (self::REMOVED_GATEWAY_FACTORIES as $factoryName) {
                if (in_array($factoryName, $factories, true)) {
                    $count++;
                    $report->addIssue(new MigrationIssue(
                        severity: Severity::BREAKING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf('Configuration de gateway "%s" detectee dans %s', $factoryName, $file->getRelativePathname()),
                        detail: sprintf(
                            'La factory de passerelle "%s" utilisee dans %s n\'est plus disponible dans Sylius 2.0.',
                            $factoryName,
                            $file->getRelativePathname(),
                        ),
                        suggestion: sprintf(
                            'Remplacer la configuration "%s" par l\'integration du plugin officiel correspondant.',
                            $factoryName,
                        ),
                        file: $filePath,
                        docUrl: self::DOC_URL,
                    ));
                }
            }
        }

        return $count;
    }

    /**
     * Collecte recursivement les valeurs scalaires des cles "factory" d'une configuration YAML.
     * Le parcours recursif couvre payum.gateways.* comme les blocs when@env.
     *
     * @param array<mixed> $config Configuration parsee
     *
     * @return list<string>
     */
    private function collectFactoryValues(array $config): array
    {
        $factories = [];

        foreach ($config as $key => $value) {
            if ($key === 'factory' && is_string($value)) {
                $factories[] = $value;
            } elseif (is_array($value)) {
                $factories = array_merge($factories, $this->collectFactoryValues($value));
            }
        }

        return $factories;
    }

    /**
     * Detecte les classes PHP etendant les classes des passerelles Payum supprimees.
     * Retourne le nombre de classes trouvees.
     */
    private function analyzeExtendingClasses(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $totalClasses = 0;

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

            /* Visiteur pour detecter les classes etendant ou utilisant des composants Payum Stripe/PayPal */
            $visitor = new class (self::REMOVED_NAMESPACES) extends NodeVisitorAbstract {
                /** @var list<array{name: string, line: int, type: string}> */
                public array $usages = [];

                /**
                 * @param list<string> $removedNamespaces Espaces de noms des passerelles supprimees
                 */
                public function __construct(private readonly array $removedNamespaces)
                {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection des imports Payum Stripe ou PayPal Express Checkout */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $fullName = $use->name->toString();
                            if ($this->isRemovedGatewayClass($fullName)) {
                                $this->usages[] = [
                                    'name' => $fullName,
                                    'line' => $node->getStartLine(),
                                    'type' => 'import',
                                ];
                            }
                        }
                    }

                    /* Detection de l'heritage de classes Payum : le NameResolver a remplace le parent
                       par son nom pleinement qualifie, alias et imports compris. */
                    if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
                        $parentName = $node->extends->toString();
                        if ($this->isRemovedGatewayClass($parentName)) {
                            $this->usages[] = [
                                'name' => $parentName,
                                'line' => $node->getStartLine(),
                                'type' => 'extends',
                            ];
                        }
                    }

                    return null;
                }

                /** Indique si le nom pleinement qualifie appartient a une passerelle Payum supprimee. */
                private function isRemovedGatewayClass(string $fullName): bool
                {
                    foreach ($this->removedNamespaces as $namespace) {
                        if (str_starts_with($fullName, $namespace)) {
                            return true;
                        }
                    }

                    return false;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            if ($visitor->usages !== []) {
                $totalClasses++;
                $firstUsage = $visitor->usages[0];

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Classe utilisant une passerelle Payum supprimee dans %s', $file->getRelativePathname()),
                    detail: sprintf(
                        'Le fichier %s reference %s (ligne %d). '
                        . 'Les composants Payum Stripe/PayPal ne sont plus supportes dans Sylius 2.0.',
                        $file->getRelativePathname(),
                        $firstUsage['name'],
                        $firstUsage['line'],
                    ),
                    suggestion: 'Reecrire cette classe pour utiliser le plugin officiel Sylius '
                        . 'ou une integration directe avec l\'API du prestataire de paiement.',
                    file: $filePath,
                    line: $firstUsage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $totalClasses;
    }
}
