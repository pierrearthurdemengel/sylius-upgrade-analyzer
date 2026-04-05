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
 * Analyseur des encodeurs de mots de passe deprecies.
 * Symfony a remplace les "encoders" par les "password_hashers" et la methode getSalt()
 * n'est plus necessaire avec les algorithmes modernes (bcrypt, argon2).
 */
final class UserEncoderAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes par classe User affectee */
    private const MINUTES_PER_USER_CLASS = 60;

    public function getName(): string
    {
        return 'User Encoder';
    }

    /**
     * Cet analyseur est toujours applicable car il verifie security.yaml
     * qui est present dans tous les projets Symfony.
     */
    public function supports(MigrationReport $report): bool
    {
        return true;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        /* Etape 1 : verification de la cle "encoders:" dans security.yaml */
        $this->analyzeSecurityConfig($report, $projectPath);

        /* Etape 2 : recherche de la methode getSalt() dans les entites */
        $this->analyzeEntitySaltMethod($report, $projectPath);
    }

    /**
     * Verifie si security.yaml utilise encore la cle "encoders:" au lieu de "password_hashers:".
     */
    private function analyzeSecurityConfig(MigrationReport $report, string $projectPath): void
    {
        $securityPaths = [
            $projectPath . '/config/packages/security.yaml',
            $projectPath . '/config/packages/security.yml',
        ];

        foreach ($securityPaths as $securityPath) {
            if (!file_exists($securityPath)) {
                continue;
            }

            try {
                $config = Yaml::parseFile($securityPath);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($config)) {
                continue;
            }

            /* Verification de la cle "encoders" dans la configuration de securite */
            $securityConfig = $config['security'] ?? $config;

            if (!is_array($securityConfig)) {
                continue;
            }

            if (isset($securityConfig['encoders'])) {
                $encoderCount = is_array($securityConfig['encoders'])
                    ? count($securityConfig['encoders'])
                    : 1;

                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: 'Configuration "encoders:" depreciee detectee dans security.yaml',
                    detail: sprintf(
                        'La cle "security.encoders" est depreciee depuis Symfony 5.3 et supprimee en Symfony 6.0. '
                        . '%d encoder(s) configure(s) doivent etre migres vers "security.password_hashers".',
                        $encoderCount,
                    ),
                    suggestion: 'Remplacer "security.encoders:" par "security.password_hashers:" '
                        . 'et utiliser l\'algorithme "auto" ou "sodium" au lieu de "bcrypt" ou "argon2i".',
                    file: $securityPath,
                    estimatedMinutes: self::MINUTES_PER_USER_CLASS,
                ));
            }
        }
    }

    /**
     * Recherche les classes d'entite qui definissent la methode getSalt().
     * Avec bcrypt et argon2, le sel est integre au hash et getSalt() est inutile.
     */
    private function analyzeEntitySaltMethod(MigrationReport $report, string $projectPath): void
    {
        $entityDir = $projectPath . '/src/Entity';
        if (!is_dir($entityDir)) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($entityDir)->name('*.php');

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

            /* Visiteur pour detecter la methode getSalt() dans les classes */
            $visitor = new class () extends NodeVisitorAbstract {
                /** @var list<array{class: string, line: int}> */
                public array $findings = [];

                /** Nom de la classe en cours d'analyse */
                private ?string $currentClass = null;

                public function enterNode(Node $node): null
                {
                    /* Suivi du nom de la classe courante */
                    if ($node instanceof Node\Stmt\Class_) {
                        $this->currentClass = $node->name?->name;
                    }

                    /* Detection de la methode getSalt() */
                    if ($node instanceof Node\Stmt\ClassMethod && $node->name->name === 'getSalt') {
                        $this->findings[] = [
                            'class' => $this->currentClass ?? 'Classe anonyme',
                            'line' => $node->getStartLine(),
                        ];
                    }

                    return null;
                }

                public function leaveNode(Node $node): null
                {
                    if ($node instanceof Node\Stmt\Class_) {
                        $this->currentClass = null;
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            /* Creation d'un probleme pour chaque classe avec getSalt() */
            foreach ($visitor->findings as $finding) {
                $report->addIssue(new MigrationIssue(
                    severity: Severity::WARNING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Methode getSalt() detectee dans la classe %s',
                        $finding['class'],
                    ),
                    detail: sprintf(
                        'La classe %s dans %s definit la methode getSalt() (ligne %d). '
                        . 'Avec les algorithmes modernes (bcrypt, argon2), le sel est genere automatiquement '
                        . 'et cette methode peut retourner null.',
                        $finding['class'],
                        $file->getRelativePathname(),
                        $finding['line'],
                    ),
                    suggestion: 'Supprimer l\'implementation de getSalt() ou la faire retourner null. '
                        . 'S\'assurer que le password hasher utilise l\'algorithme "auto".',
                    file: $filePath,
                    line: $finding['line'],
                    estimatedMinutes: self::MINUTES_PER_USER_CLASS,
                ));
            }
        }
    }
}
