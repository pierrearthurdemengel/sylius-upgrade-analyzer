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
 *
 * Sylius 2.0 ajoute les Payment Requests mais conserve Payum : payum/payum-bundle reste
 * requis par sylius/sylius 2.0 et SyliusPayumBundle est maintenu (UPGRADE-2.0.md). Les
 * gateways Payum continuent donc de fonctionner ; leur migration vers les Payment Requests
 * est optionnelle et signalee en SUGGESTION, hors du total d'heures. Les changements
 * obligatoires (passerelles Stripe et PayPal Express retirees, imports de routing et de
 * configuration, services renommes) sont couverts par les analyseurs dedies.
 */
final class PayumAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par gateway personnalisee */
    private const MINUTES_PER_CUSTOM_GATEWAY = 480;

    /** Estimation en minutes par gateway standard */
    private const MINUTES_PER_STANDARD_GATEWAY = 120;

    /**
     * Estimation en minutes par fichier PHP dependant de Payum hors gateway factory
     * (action Capture/Status/Notify, extension, requete) : chacun devient un handler
     * de Payment Request, un travail comparable a la migration d'une gateway standard.
     */
    private const MINUTES_PER_PAYUM_DEPENDENT_FILE = 120;

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

        /* Etape 2 : analyse des fichiers de configuration YAML */
        $standardGatewayCount = $this->analyzeYamlConfigurations($report, $projectPath);

        /* Etape 3 : analyse des fichiers PHP pour les classes GatewayFactory et les imports Payum */
        $phpUsages = $this->analyzePhpUsages($report, $projectPath);
        $customGatewayCount = $phpUsages['customGateways'];
        $dependentFileCount = $phpUsages['dependentFiles'];

        /* Etape 4 : ajout d'un probleme global avec estimation */
        $totalMinutes = ($customGatewayCount * self::MINUTES_PER_CUSTOM_GATEWAY)
            + ($standardGatewayCount * self::MINUTES_PER_STANDARD_GATEWAY)
            + ($dependentFileCount * self::MINUTES_PER_PAYUM_DEPENDENT_FILE);

        if ($totalMinutes > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::SUGGESTION,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    'Migration optionnelle vers les Payment Requests : %d gateway(s) standard, '
                    . '%d gateway(s) personnalisee(s) et %d autre(s) fichier(s) PHP dependant de Payum',
                    $standardGatewayCount,
                    $customGatewayCount,
                    $dependentFileCount,
                ),
                detail: 'Sylius 2.0 conserve Payum (SyliusPayumBundle) et ajoute les Payment Requests. '
                    . 'Les gateways Payum continuent de fonctionner ; l\'estimation indique l\'effort '
                    . 'si vous choisissez de les convertir, elle n\'entre pas dans le total.',
                suggestion: 'Garder Payum pour la montee de version, puis convertir les gateways en '
                    . 'Payment Request handlers si vous souhaitez adopter le nouveau systeme.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $totalMinutes,
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
                $config = Yaml::parseFile($filePath, Yaml::PARSE_CUSTOM_TAGS);
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
                        severity: Severity::SUGGESTION,
                        category: Category::DEPRECATION,
                        analyzer: $this->getName(),
                        message: sprintf('Gateway Payum "%s" convertible en Payment Request', $gatewayName),
                        detail: sprintf(
                            'La gateway "%s" definie dans %s reste supportee par SyliusPayumBundle '
                            . 'et peut etre convertie en Payment Request handler.',
                            $gatewayName,
                            $file->getRelativePathname(),
                        ),
                        suggestion: sprintf(
                            'Optionnel : creer un Payment Request handler pour la gateway "%s" '
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
     * Analyse les fichiers PHP pour detecter les classes etendant GatewayFactory et les imports Payum.
     * Retourne le nombre de gateways personnalisees et le nombre d'autres fichiers important Payum.
     * Un fichier contenant une gateway factory n'est compte qu'une fois, comme gateway personnalisee,
     * car son estimation couvre deja ses imports.
     *
     * @return array{customGateways: int, dependentFiles: int}
     */
    private function analyzePhpUsages(MigrationReport $report, string $projectPath): array
    {
        $srcDir = $projectPath . '/src';
        if (!is_dir($srcDir)) {
            return ['customGateways' => 0, 'dependentFiles' => 0];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        $customGatewayCount = 0;
        $dependentFileCount = 0;

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
                            $this->recordPayumImport($use->name->toString(), $node->getStartLine());
                        }
                    }

                    /* Imports groupes (use Payum\Core\{...}) : le nom complet est prefixe + element */
                    if ($node instanceof Node\Stmt\GroupUse) {
                        foreach ($node->uses as $use) {
                            $this->recordPayumImport(
                                Node\Name::concat($node->prefix, $use->name)?->toString() ?? '',
                                $use->getStartLine(),
                            );
                        }
                    }

                    return null;
                }

                private function recordPayumImport(string $fullName, int $line): void
                {
                    if (str_starts_with($fullName, 'Payum\\')) {
                        $this->payumUsages[] = [
                            'class' => $fullName,
                            'line' => $line,
                        ];
                    }
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            /* Problemes pour les GatewayFactory personnalisees */
            foreach ($visitor->gatewayFactories as $factory) {
                $customGatewayCount++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::SUGGESTION,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Gateway factory personnalisee %s detectee (etend %s)',
                        $factory['class'],
                        $factory['parent'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s etend %s. '
                        . 'Elle reste utilisable avec SyliusPayumBundle ; adopter les Payment Requests '
                        . 'demanderait de la reecrire en Payment Request handler.',
                        $factory['class'],
                        $file->getRelativePathname(),
                        $factory['parent'],
                    ),
                    suggestion: 'Optionnel : creer un Payment Request handler pour remplacer '
                        . 'cette gateway factory Payum personnalisee.',
                    file: $filePath,
                    line: $factory['line'],
                    docUrl: self::DOC_URL,
                ));
            }

            if ($visitor->gatewayFactories === [] && $visitor->payumUsages !== []) {
                $dependentFileCount++;
            }

            /* Problemes pour les usages generaux de Payum */
            foreach ($visitor->payumUsages as $usage) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::SUGGESTION,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Utilisation de la classe Payum %s detectee', $usage['class']),
                    detail: sprintf(
                        'La classe %s est importee dans %s. '
                        . 'Payum reste disponible dans Sylius 2.0 ; cette reference ne change que si '
                        . 'vous adoptez le systeme de Payment Requests.',
                        $usage['class'],
                        $file->getRelativePathname(),
                    ),
                    suggestion: 'Optionnel : remplacer les references Payum par les interfaces et classes '
                        . 'du systeme de Payment Requests de Sylius 2.0.',
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return ['customGateways' => $customGatewayCount, 'dependentFiles' => $dependentFileCount];
    }
}
