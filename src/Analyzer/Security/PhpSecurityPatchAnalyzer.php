<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Security;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Analyseur des versions de PHP declarees par le projet face aux failles de securite connues.
 *
 * L'outil ne voit pas le PHP de production : il lit la version que le projet declare
 * (plateforme composer.json, Dockerfile, fichiers compose, .php-version, .tool-versions).
 * Seule une image Docker epinglee sur un correctif (php:8.3.10-fpm) decrit le PHP execute :
 * sous le correctif de sa branche, ou sur une branche sans support, elle est bloquante.
 * Les autres declarations sont signalees pour verification, sans alerte bloquante :
 * config.platform.php fixe souvent un minimum pour resoudre les dependances,
 * .php-version et .tool-versions decrivent le poste du developpeur, une image sans
 * correctif (php:8.3-fpm) peut etre a jour, et les PHP des distributions recoivent
 * les correctifs sans changer de numero.
 */
final class PhpSecurityPatchAnalyzer implements AnalyzerInterface
{
    /**
     * Premiere version corrigee de chaque branche supportee.
     * Mise a jour du 2026-10-21 : CVE-2026-109618 et CVE-2026-109619, execution de code
     * a distance sans authentification, exploitees (catalogue KEV de la CISA).
     * A completer a chaque version de securite de PHP.
     */
    private const FIRST_PATCHED_VERSIONS = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** Failles corrigees par les versions ci-dessus, citees dans les rapports avec leurs avis officiels */
    private const ADVISORIES = 'CVE-2026-109618 (https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) '
        . 'et CVE-2026-109619 (https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp), '
        . 'execution de code a distance sans authentification, exploitees selon le catalogue KEV de la CISA (26/10/2026)';

    /** Plus ancienne branche encore corrigee par l'equipe PHP */
    private const OLDEST_SUPPORTED_BRANCH = '8.2';

    /** Mise a jour d'une image ou d'un serveur puis verification du projet */
    private const MINUTES_PER_VULNERABLE_RUNTIME = 60;

    /** Verification de la version reellement deployee */
    private const MINUTES_PER_UNVERIFIED_RUNTIME = 15;

    /** Avis de securite de l'equipe PHP */
    private const DOC_URL = 'https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w';

    /** Repertoires ignores lors de la recherche des fichiers Docker */
    private const EXCLUDED_DIRECTORIES = ['vendor', 'node_modules', 'var', 'public'];

    public function getName(): string
    {
        return 'PHP Security Patch';
    }

    public function supports(MigrationReport $report): bool
    {
        return $this->collectDeclaredVersions($report->getProjectPath()) !== [];
    }

    public function analyze(MigrationReport $report): void
    {
        foreach ($this->collectDeclaredVersions($report->getProjectPath()) as $declaration) {
            $issue = $this->evaluate($declaration);
            if ($issue !== null) {
                $report->addIssue($issue);
            }
        }
    }

    /**
     * Construit l'issue correspondant a une version declaree, ou null si elle est a jour.
     *
     * @param array{version: string, source: string, file: string, line: int|null, pinned: bool} $declaration
     */
    private function evaluate(array $declaration): ?MigrationIssue
    {
        if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $declaration['version'], $matches) !== 1) {
            return null;
        }

        $branch = $matches[1] . '.' . $matches[2];
        $patch = $matches[3] ?? null;
        $version = $patch === null ? $branch : $branch . '.' . $patch;

        $firstPatched = self::FIRST_PATCHED_VERSIONS[$branch] ?? null;
        $unsupported = version_compare($branch, self::OLDEST_SUPPORTED_BRANCH, '<');
        if (!$unsupported && $firstPatched === null) {
            /* Branche plus recente que la table : aucune faille connue a signaler */
            return null;
        }

        if ($patch !== null && !$unsupported && version_compare($version, $firstPatched, '>=')) {
            return null;
        }

        $required = $firstPatched ?? self::FIRST_PATCHED_VERSIONS[self::OLDEST_SUPPORTED_BRANCH];

        /* Une image sur une branche sans support est vulnerable quel que soit son correctif */
        if (!$declaration['pinned'] || ($patch === null && !$unsupported)) {
            return $this->createIssue(
                $declaration,
                Severity::SUGGESTION,
                sprintf('PHP %s declare (%s) : verifier que le PHP execute est >= %s', $version, $declaration['source'], $required),
                sprintf(
                    'Cette declaration ne prouve pas la version executee en production. Les versions anterieures a PHP %s sont exposees a %s.',
                    $required,
                    self::ADVISORIES,
                ),
                sprintf(
                    'Verifier la version reellement deployee (php -v sur le serveur) ; pour une image Docker, la reconstruire avec --pull, '
                    . 'ou epingler PHP %s ou plus recent.',
                    $required,
                ),
                self::MINUTES_PER_UNVERIFIED_RUNTIME,
            );
        }

        if ($unsupported) {
            return $this->createIssue(
                $declaration,
                Severity::BREAKING,
                sprintf('PHP %s declare (%s) : branche sans correctif de securite', $version, $declaration['source']),
                sprintf(
                    'La branche PHP %s ne recoit plus de correctif officiel. Elle reste exposee a %s, '
                    . 'corrigees uniquement a partir de PHP %s.',
                    $branch,
                    self::ADVISORIES,
                    self::FIRST_PATCHED_VERSIONS[self::OLDEST_SUPPORTED_BRANCH],
                ),
                sprintf('Passer a une branche supportee, au minimum PHP %s, requis de toute facon par Sylius 2.', self::FIRST_PATCHED_VERSIONS[self::OLDEST_SUPPORTED_BRANCH]),
                self::MINUTES_PER_VULNERABLE_RUNTIME,
            );
        }

        return $this->createIssue(
            $declaration,
            Severity::BREAKING,
            sprintf('PHP %s declare (%s) : version vulnerable, corrigee en %s', $version, $declaration['source'], $firstPatched),
            sprintf('PHP %s est expose a %s.', $version, self::ADVISORIES),
            sprintf('Mettre a jour vers PHP %s ou plus recent, puis redeployer.', $firstPatched),
            self::MINUTES_PER_VULNERABLE_RUNTIME,
        );
    }

    /**
     * @param array{version: string, source: string, file: string, line: int|null, pinned: bool} $declaration
     */
    private function createIssue(
        array $declaration,
        Severity $severity,
        string $message,
        string $detail,
        string $suggestion,
        int $minutes,
    ): MigrationIssue {
        return new MigrationIssue(
            severity: $severity,
            category: Category::SECURITY,
            analyzer: $this->getName(),
            message: $message,
            detail: $detail,
            suggestion: $suggestion,
            file: $declaration['file'],
            line: $declaration['line'],
            docUrl: self::DOC_URL,
            estimatedMinutes: $minutes,
        );
    }

    /**
     * Rassemble les versions de PHP declarees par le projet, sans doublon par fichier.
     *
     * @return list<array{version: string, source: string, file: string, line: int|null, pinned: bool}>
     */
    private function collectDeclaredVersions(string $projectPath): array
    {
        $declarations = array_merge(
            $this->fromComposerPlatform($projectPath),
            $this->fromVersionFiles($projectPath),
            $this->fromDockerFiles($projectPath),
        );

        $unique = [];
        foreach ($declarations as $declaration) {
            $unique[$declaration['file'] . '|' . $declaration['version']] ??= $declaration;
        }

        return array_values($unique);
    }

    /**
     * Version imposee par config.platform.php, qui reflete generalement le PHP de production.
     *
     * @return list<array{version: string, source: string, file: string, line: int|null, pinned: bool}>
     */
    private function fromComposerPlatform(string $projectPath): array
    {
        $file = $projectPath . '/composer.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $version = is_array($data) ? ($data['config']['platform']['php'] ?? null) : null;
        if (!is_string($version)) {
            return [];
        }

        return [['version' => $version, 'source' => 'composer.json config.platform', 'file' => $file, 'line' => null, 'pinned' => false]];
    }

    /**
     * Versions declarees pour les gestionnaires de versions (Symfony CLI, phpenv, asdf, mise).
     *
     * @return list<array{version: string, source: string, file: string, line: int|null, pinned: bool}>
     */
    private function fromVersionFiles(string $projectPath): array
    {
        $declarations = [];

        $phpVersionFile = $projectPath . '/.php-version';
        if (is_file($phpVersionFile)) {
            $version = trim((string) strtok((string) file_get_contents($phpVersionFile), "\n"));
            if ($version !== '') {
                $declarations[] = ['version' => $version, 'source' => '.php-version', 'file' => $phpVersionFile, 'line' => 1, 'pinned' => false];
            }
        }

        $toolVersionsFile = $projectPath . '/.tool-versions';
        if (is_file($toolVersionsFile)) {
            foreach (file($toolVersionsFile, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (preg_match('/^\s*php\s+(\S+)/', $line, $matches) === 1) {
                    $declarations[] = ['version' => $matches[1], 'source' => '.tool-versions', 'file' => $toolVersionsFile, 'line' => $index + 1, 'pinned' => false];
                }
            }
        }

        return $declarations;
    }

    /**
     * Versions des images PHP utilisees par les Dockerfile et fichiers compose du projet.
     *
     * @return list<array{version: string, source: string, file: string, line: int|null, pinned: bool}>
     */
    private function fromDockerFiles(string $projectPath): array
    {
        $finder = new Finder();
        $finder->files()
            ->in($projectPath)
            ->depth('< 4')
            ->exclude(self::EXCLUDED_DIRECTORIES)
            ->ignoreDotFiles(false)
            ->name(['Dockerfile', 'Dockerfile.*', '*.Dockerfile', 'docker-compose*.yml', 'docker-compose*.yaml', 'compose*.yml', 'compose*.yaml']);

        $declarations = [];

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            /* Valeurs par defaut des ARG, pour resoudre FROM php:${PHP_VERSION}-fpm */
            $arguments = [];

            foreach (file($filePath, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (preg_match('/^\s*ARG\s+(\w+)\s*=\s*["\']?([^"\'\s]+)/i', $line, $matches) === 1) {
                    $arguments[$matches[1]] = $matches[2];
                    continue;
                }

                /* Les options de FROM (--platform=...) precedent la reference de l'image */
                if (preg_match('/^\s*(?:FROM(?:\s+--\S+)*|-?\s*image:)\s*["\']?([^"\'\s]+)/i', $line, $matches) !== 1) {
                    continue;
                }

                $version = $this->phpVersionFromImage($this->resolveArguments($matches[1], $arguments));
                if ($version !== null) {
                    $declarations[] = [
                        'version' => $version,
                        'source' => 'image ' . $this->resolveArguments($matches[1], $arguments),
                        'file' => $filePath,
                        'line' => $index + 1,
                        'pinned' => true,
                    ];
                }
            }
        }

        return $declarations;
    }

    /**
     * Extrait la version de PHP d'une reference d'image : image officielle php:8.3.10-fpm
     * ou image derivee dont le tag mentionne php, comme dunglas/frankenphp:1.4-php8.3.
     */
    private function phpVersionFromImage(string $image): ?string
    {
        $separator = strrpos($image, ':');
        if ($separator === false) {
            return null;
        }

        $name = substr($image, 0, $separator);
        $tag = substr($image, $separator + 1);

        if (($name === 'php' || str_ends_with($name, '/php')) && preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $tag, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/php-?(\d+\.\d+(?:\.\d+)?)/i', $tag, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Remplace ${VAR} et $VAR par la valeur par defaut de l'ARG correspondant.
     *
     * @param array<string, string> $arguments
     */
    private function resolveArguments(string $value, array $arguments): string
    {
        return (string) preg_replace_callback(
            '/\$\{?(\w+)\}?/',
            static fn (array $matches): string => $arguments[$matches[1]] ?? $matches[0],
            $value,
        );
    }
}
