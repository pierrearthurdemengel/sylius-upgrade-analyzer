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
 * Analyseur de l'utilisation de Payum pour les paiements.
 * Sylius 2.0 introduit un nouveau systeme de paiement base sur les Payment Requests
 * qui remplace Payum. Cet analyseur detecte les gateways et usages a migrer.
 */
final class PayumAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par gateway personnalisee */
    private const MINUTES_PER_CUSTOM_GATEWAY = 480;

    /** Estimation en minutes par gateway standard */
    private const MINUTES_PER_STANDARD_GATEWAY = 120;

    /** URL de la documentation Sylius sur les Payment Requests */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0/payment-requests';

    /** Paquets Payum recherches dans composer.json */
    private const PAYUM_PACKAGES = [
        'payum/core',
        'payum/payum-bundle',
    ];

    public function getName(): string
    {
        return 'Payum';
    }

    public function supports(MigrationReport $report): bool
    {
        $composerJsonPath = $report->getProjectPath() . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return false;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);
        if (!is_array($composerData) || !isset($composerData['require'])) {
            return false;
        }

        /* Verification de la presence d'un des paquets Payum */
        foreach (self::PAYUM_PACKAGES as $package) {
            if (isset($composerData['require'][$package])) {
                return true;
            }
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        /* Etape 1 : verification dans composer.json */
        $this->analyzeComposerJson($report, $projectPath);

        /* Etape 2 : analyse des fichiers de configuration YAML */
        $standardGatewayCount = $this->analyzeYamlConfigurations($report, $projectPath);

        /* Etape 3 : analyse des fichiers PHP pour les classes GatewayFactory */
        $customGatewayCount = $this->analyzePhpUsages($report, $projectPath);

        /* Etape 4 : ajout d'un probleme global avec estimation */
        $totalMinutes = ($customGatewayCount * self::MINUTES_PER_CUSTOM_GATEWAY)
            + ($standardGatewayCount * self::MINUTES_PER_STANDARD_GATEWAY);

        if ($totalMinutes > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    'Migration Payum requise : %d gateway(s) standard et %d gateway(s) personnalisee(s)',
                    $standardGatewayCount,
                    $customGatewayCount,
                ),
                detail: 'Sylius 2.0 remplace Payum par un systeme de Payment Requests. '
                    . 'Toutes les gateways de paiement doivent etre migrees vers le nouveau systeme.',
                suggestion: 'Suivre le guide de migration Sylius pour convertir chaque gateway Payum '
                    . 'en Payment Request handler.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $totalMinutes,
            ));
        }
    }

    /**
     * Verifie la presence des paquets Payum dans composer.json.
     */
    private function analyzeComposerJson(MigrationReport $report, string $projectPath): void
    {
        $composerJsonPath = $projectPath . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);
        if (!is_array($composerData) || !isset($composerData['require'])) {
            return;
        }

        foreach (self::PAYUM_PACKAGES as $package) {
            if (!isset($composerData['require'][$package])) {
                continue;
            }

            $version = $composerData['require'][$package];
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf('Dependance %s detectee dans composer.json', $package),
                detail: sprintf(
                    'La version %s de %s est installee. '
                    . 'Payum est remplace par le systeme de Payment Requests dans Sylius 2.0.',
                    $version,
                    $package,
                ),
                suggestion: sprintf(
                    'Retirer %s de composer.json apres migration vers le systeme de Payment Requests.',
                    $package,
                ),
                file: $composerJsonPath,
                docUrl: self::DOC_URL,
            ));
        }
    }

    /**
     * Analyse les fichiers YAML pour les definitions de gateways Payum.
     * Retourne le nombre de gateways standard configurees.
     */
    private function analyzeYamlConfigurations(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config/packages';
        if (!is_dir($configDir)) {
            return 0;
        }

        $gatewayCount = 0;
        $finder = new Finder();
        $finder->files()->in($configDir)->name('*.yaml')->name('*.yml');

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

            if (!is_array($config) || !isset($config['payum'])) {
                continue;
            }

            $payumConfig = $config['payum'];
            if (!is_array($payumConfig)) {
                continue;
            }

            /* Comptage des gateways definies sous payum.gateways */
            if (isset($payumConfig['gateways']) && is_array($payumConfig['gateways'])) {
                foreach ($payumConfig['gateways'] as $gatewayName => $gatewayConfig) {
                    $gatewayCount++;
                    $report->addIssue(new MigrationIssue(
                        severity: Severity::BREAKING,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf('Gateway Payum "%s" a migrer', $gatewayName),
                        detail: sprintf(
                            'La gateway "%s" definie dans %s doit etre convertie '
                            . 'en Payment Request handler compatible Sylius 2.0.',
                            $gatewayName,
                            $file->getRelativePathname(),
                        ),
                        suggestion: sprintf(
                            'Creer un Payment Request handler pour la gateway "%s" '
                            . 'en suivant la documentation Sylius.',
                            $gatewayName,
                        ),
                        file: $filePath,
                        docUrl: self::DOC_URL,
                    ));
                }
            }
        }

        return $gatewayCount;
    }

    /**
     * Analyse les fichiers PHP pour detecter les classes etendant GatewayFactory.
     * Retourne le nombre de gateways personnalisees trouvees.
     */
    private function analyzePhpUsages(MigrationReport $report, string $projectPath): int
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return 0;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $customGatewayCount = 0;

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

            /* Visiteur pour detecter les classes etendant GatewayFactory */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{class: string, parent: string, line: int}> */
                public array $gatewayFactories = [];

                /** @var list<array{class: string, line: int}> */
                public array $payumUsages = [];

                public function enterNode(Node $node): null
                {
                    /* Detection des classes qui etendent GatewayFactory */
                    if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
                        $parentName = $node->extends->toString();
                        if (
                            str_contains($parentName, 'GatewayFactory')
                            || str_contains($parentName, 'Payum')
                        ) {
                            $this->gatewayFactories[] = [
                                'class' => $node->name !== null ? $node->name->name : 'Classe anonyme',
                                'parent' => $parentName,
                                'line' => $node->getStartLine(),
                            ];
                        }
                    }

                    /* Detection des imports de classes Payum */
                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $fullName = $use->name->toString();
                            if (str_starts_with($fullName, 'Payum\\')) {
                                $this->payumUsages[] = [
                                    'class' => $fullName,
                                    'line' => $node->getStartLine(),
                                ];
                            }
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            /* Problemes pour les GatewayFactory personnalisees */
            foreach ($visitor->gatewayFactories as $factory) {
                $customGatewayCount++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Gateway factory personnalisee %s detectee (etend %s)',
                        $factory['class'],
                        $factory['parent'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s etend %s. '
                        . 'Les gateway factories Payum doivent etre entierement reecrites '
                        . 'en Payment Request handlers.',
                        $factory['class'],
                        $file->getRelativePathname(),
                        $factory['parent'],
                    ),
                    suggestion: 'Creer un nouveau Payment Request handler pour remplacer '
                        . 'cette gateway factory Payum personnalisee.',
                    file: $filePath,
                    line: $factory['line'],
                    docUrl: self::DOC_URL,
                ));
            }

            /* Problemes pour les usages generaux de Payum */
            foreach ($visitor->payumUsages as $usage) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Utilisation de la classe Payum %s detectee', $usage['class']),
                    detail: sprintf(
                        'La classe %s est importee dans %s. '
                        . 'Toutes les references a Payum doivent etre remplacees par '
                        . 'le nouveau systeme de Payment Requests.',
                        $usage['class'],
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Remplacer les references Payum par les interfaces et classes '
                        . 'du systeme de Payment Requests de Sylius 2.0.',
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $customGatewayCount;
    }
}
