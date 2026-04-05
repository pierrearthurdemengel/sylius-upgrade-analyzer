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
 * Analyseur de l'utilisation de SwiftMailer.
 * SwiftMailer est abandonne et remplace par Symfony Mailer.
 * Cet analyseur detecte les dependances, configurations et usages de Swift dans le projet.
 */
final class SwiftMailerAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par usage de SwiftMailer detecte */
    private const MINUTES_PER_USAGE = 120;

    /** URL de la documentation Symfony Mailer */
    private const DOC_URL = 'https://symfony.com/doc/current/mailer.html';

    /** Classes SwiftMailer recherchees dans le code source */
    private const SWIFT_CLASSES = [
        'Swift_Message',
        'Swift_Attachment',
        'Swift_Mailer',
        'Swift_SmtpTransport',
    ];

    public function getName(): string
    {
        return 'SwiftMailer';
    }

    public function supports(MigrationReport $report): bool
    {
        $composerJsonPath = $report->getProjectPath() . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return false;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);

        return isset($composerData['require']['swiftmailer/swiftmailer']);
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();
        $usageCount = 0;

        /* Etape 1 : verification dans composer.json */
        $usageCount += $this->analyzeComposerJson($report, $projectPath);

        /* Etape 2 : analyse des fichiers de configuration YAML */
        $usageCount += $this->analyzeYamlConfigurations($report, $projectPath);

        /* Etape 3 : analyse des fichiers PHP pour les usages directs */
        $usageCount += $this->analyzePhpUsages($report, $projectPath);

        /* Etape 4 : comptage des templates email */
        $usageCount += $this->analyzeEmailTemplates($report, $projectPath);

        /* Etape 5 : ajout d'un probleme global avec estimation */
        if ($usageCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d usage(s) de SwiftMailer detecte(s) necessitant une migration vers Symfony Mailer',
                    $usageCount,
                ),
                detail: 'SwiftMailer est abandonne et n\'est plus maintenu. '
                    . 'Tous les usages doivent etre migres vers le composant Symfony Mailer.',
                suggestion: 'Installer symfony/mailer, remplacer Swift_Message par '
                    . 'Symfony\\Component\\Mime\\Email et adapter les transports.',
                docUrl: self::DOC_URL,
                estimatedMinutes: $usageCount * self::MINUTES_PER_USAGE,
            ));
        }
    }

    /**
     * Verifie la presence de swiftmailer/swiftmailer dans composer.json.
     * Retourne 1 si la dependance est trouvee, 0 sinon.
     */
    private function analyzeComposerJson(MigrationReport $report, string $projectPath): int
    {
        $composerJsonPath = $projectPath . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return 0;
        }

        $composerData = json_decode((string) file_get_contents($composerJsonPath), true);

        if (!isset($composerData['require']['swiftmailer/swiftmailer'])) {
            return 0;
        }

        $version = $composerData['require']['swiftmailer/swiftmailer'];
        $report->addIssue(new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: $this->getName(),
            message: 'Dependance swiftmailer/swiftmailer detectee dans composer.json',
            detail: sprintf(
                'La version %s de swiftmailer/swiftmailer est installee. '
                . 'SwiftMailer est abandonne et doit etre remplace par Symfony Mailer.',
                $version,
            ),
            suggestion: 'Remplacer swiftmailer/swiftmailer par symfony/mailer dans composer.json.',
            file: $composerJsonPath,
            docUrl: self::DOC_URL,
        ));

        return 1;
    }

    /**
     * Analyse les fichiers YAML pour la configuration swiftmailer.
     * Retourne le nombre de configurations trouvees.
     */
    private function analyzeYamlConfigurations(MigrationReport $report, string $projectPath): int
    {
        $configDir = $projectPath . '/config/packages';
        if (!is_dir($configDir)) {
            return 0;
        }

        $count = 0;
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

            if (!is_array($config) || !isset($config['swiftmailer'])) {
                continue;
            }

            $count++;
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf('Configuration swiftmailer detectee dans %s', $file->getRelativePathname()),
                detail: 'La configuration swiftmailer: doit etre remplacee par la configuration '
                    . 'framework.mailer: de Symfony Mailer.',
                suggestion: 'Migrer la configuration swiftmailer: vers framework.mailer: '
                    . 'avec le DSN de transport adapte.',
                file: $filePath,
                docUrl: self::DOC_URL,
            ));
        }

        return $count;
    }

    /**
     * Analyse les fichiers PHP pour detecter les usages de classes SwiftMailer.
     * Retourne le nombre d'usages trouves.
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

        $totalUsages = 0;

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

            /* Visiteur pour detecter les classes Swift_ dans l'AST */
            $swiftClasses = self::SWIFT_CLASSES;
            $visitor = new class ($swiftClasses) extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int}> */
                public array $usages = [];

                /** @param list<string> $targetClasses */
                public function __construct(private readonly array $targetClasses)
                {
                }

                public function enterNode(Node $node): null
                {
                    /* Detection des noms de classes (FQCN ou non qualifies) */
                    if ($node instanceof Node\Name) {
                        $name = $node->toString();
                        foreach ($this->targetClasses as $targetClass) {
                            if ($name === $targetClass || str_ends_with($name, '\\' . $targetClass)) {
                                $this->usages[] = [
                                    'class' => $targetClass,
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

            /* Creation d'un probleme pour chaque usage detecte */
            foreach ($visitor->usages as $usage) {
                $totalUsages++;
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Utilisation de %s detectee', $usage['class']),
                    detail: sprintf(
                        'La classe %s est utilisee dans %s ligne %d. '
                        . 'Elle doit etre remplacee par l\'equivalent Symfony Mailer.',
                        $usage['class'],
                        $file->getRelativePathname(),
                        $usage['line'],
                    ),
                    suggestion: $this->getSuggestionForClass($usage['class']),
                    file: $filePath,
                    line: $usage['line'],
                    docUrl: self::DOC_URL,
                ));
            }
        }

        return $totalUsages;
    }

    /**
     * Compte les fichiers de templates email dans templates/emails/.
     * Retourne le nombre de templates trouves.
     */
    private function analyzeEmailTemplates(MigrationReport $report, string $projectPath): int
    {
        $emailsDir = $projectPath . '/templates/emails';
        if (!is_dir($emailsDir)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($emailsDir)->name('*.html.twig')->name('*.txt.twig');

        $templateCount = iterator_count($finder);

        if ($templateCount > 0) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::BREAKING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    '%d template(s) email detecte(s) dans templates/emails/',
                    $templateCount,
                ),
                detail: 'Les templates email existants peuvent necessiter une adaptation '
                    . 'pour fonctionner avec Symfony Mailer et le nouveau systeme de rendu.',
                suggestion: 'Verifier la compatibilite des templates avec TemplatedEmail '
                    . 'de Symfony Mailer et adapter si necessaire.',
                docUrl: self::DOC_URL,
            ));
        }

        return $templateCount;
    }

    /**
     * Retourne la suggestion de remplacement pour une classe SwiftMailer donnee.
     */
    private function getSuggestionForClass(string $className): string
    {
        return match ($className) {
            'Swift_Message' => 'Remplacer Swift_Message par Symfony\\Component\\Mime\\Email '
                . 'ou Symfony\\Bridge\\Twig\\Mime\\TemplatedEmail.',
            'Swift_Attachment' => 'Remplacer Swift_Attachment par la methode '
                . 'attachFromPath() ou attach() de Symfony\\Component\\Mime\\Email.',
            'Swift_Mailer' => 'Remplacer Swift_Mailer par Symfony\\Component\\Mailer\\MailerInterface.',
            'Swift_SmtpTransport' => 'Remplacer Swift_SmtpTransport par un DSN de transport '
                . 'configure dans MAILER_DSN.',
            default => 'Remplacer par l\'equivalent Symfony Mailer.',
        };
    }
}
