<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Security\PhpSecurityPatchAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests de la detection des versions de PHP declarees sans correctif de securite.
 */
final class PhpSecurityPatchAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('phpsec_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item->getRealPath()) : unlink((string) $item->getRealPath());
        }
        rmdir($this->tempDir);
    }

    private function writeFile(string $relativePath, string $content): string
    {
        $path = $this->tempDir . '/' . $relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0755, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return list<MigrationIssue>
     */
    private function analyze(): array
    {
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.13.4',
            targetVersion: '2.2',
            projectPath: $this->tempDir,
        );
        (new PhpSecurityPatchAnalyzer())->analyze($report);

        return $report->getIssues();
    }

    /** Verifie qu'une image Docker officielle sous le correctif est bloquante, avec fichier et ligne. */
    #[Test]
    public function testReportsVulnerableOfficialDockerImage(): void
    {
        $file = $this->writeFile('Dockerfile', "FROM composer:2 AS composer\nFROM php:8.3.10-fpm-alpine AS app\n");

        $issues = $this->analyze();

        self::assertCount(1, $issues);
        self::assertSame(Severity::BREAKING, $issues[0]->getSeverity());
        self::assertSame(Category::SECURITY, $issues[0]->getCategory());
        self::assertStringContainsString('PHP 8.3.10', $issues[0]->getMessage());
        self::assertStringContainsString('8.3.36', $issues[0]->getMessage());
        self::assertStringContainsString('CVE-2026-109618', $issues[0]->getDetail());
        self::assertStringContainsString('GHSA-w8hw-w9fx-j79w', $issues[0]->getDetail());
        self::assertStringContainsString('GHSA-6wmf-4rv5-p3rp', $issues[0]->getDetail());
        self::assertStringContainsString('KEV', $issues[0]->getDetail());
        self::assertSame('https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w', $issues[0]->getDocUrl());
        self::assertSame(realpath($file), $issues[0]->getFile());
        self::assertSame(2, $issues[0]->getLine());
        self::assertSame(60, $issues[0]->getEstimatedMinutes());
    }

    /**
     * Verifie la classification selon la version d'une image Docker officielle.
     */
    #[Test]
    #[DataProvider('declaredVersions')]
    public function testClassifiesDeclaredVersion(string $version, ?Severity $expected): void
    {
        $this->writeFile('Dockerfile', 'FROM php:' . $version . "-fpm\n");

        $issues = $this->analyze();

        if ($expected === null) {
            self::assertSame([], $issues);

            return;
        }

        self::assertCount(1, $issues);
        self::assertSame($expected, $issues[0]->getSeverity());
    }

    /**
     * @return iterable<string, array{string, Severity|null}>
     */
    public static function declaredVersions(): iterable
    {
        yield 'branche 8.3 sous le correctif' => ['8.3.35', Severity::BREAKING];
        yield 'premiere version corrigee 8.3' => ['8.3.36', null];
        yield 'version corrigee plus recente' => ['8.4.30', null];
        yield 'branche 8.2 sous le correctif' => ['8.2.34', Severity::BREAKING];
        yield 'branche 8.5 sous le correctif' => ['8.5.11', Severity::BREAKING];
        yield 'branche sans support 8.1' => ['8.1.31', Severity::BREAKING];
        yield 'branche sans support 7.4, sans correctif' => ['7.4', Severity::BREAKING];
        yield 'branche seule, a verifier' => ['8.3', Severity::SUGGESTION];
        yield 'branche future hors table' => ['8.6.0', null];
    }

    /** Verifie qu'une version sans correctif precise est signalee pour verification, pas bloquante. */
    #[Test]
    public function testAsksToVerifyFloatingImageTag(): void
    {
        $this->writeFile('docker/php/Dockerfile', "FROM php:8.4-fpm\n");

        $issues = $this->analyze();

        self::assertCount(1, $issues);
        self::assertSame(Severity::SUGGESTION, $issues[0]->getSeverity());
        self::assertStringContainsString('verifier que le PHP execute est >= 8.4.27', $issues[0]->getMessage());
        self::assertStringContainsString('--pull', $issues[0]->getSuggestion());
    }

    /** Verifie la resolution d'une version passee par ARG et l'option --platform de FROM. */
    #[Test]
    public function testResolvesArgumentAndPlatformOptionInDockerfile(): void
    {
        $this->writeFile('Dockerfile', <<<'DOCKERFILE'
            ARG PHP_VERSION=8.4.20
            FROM --platform=linux/amd64 php:${PHP_VERSION}-fpm
            DOCKERFILE);

        $issues = $this->analyze();

        self::assertCount(1, $issues);
        self::assertStringContainsString('PHP 8.4.20', $issues[0]->getMessage());
        self::assertStringContainsString('image php:8.4.20-fpm', $issues[0]->getMessage());
    }

    /** Verifie la lecture des images FrankenPHP et des fichiers compose. */
    #[Test]
    public function testDetectsFrankenPhpImageInComposeFile(): void
    {
        $this->writeFile('compose.yaml', <<<'YAML'
            services:
              php:
                image: dunglas/frankenphp:1.4-php8.3.20
              database:
                image: postgres:16-alpine
            YAML);

        $issues = $this->analyze();

        self::assertCount(1, $issues);
        self::assertStringContainsString('PHP 8.3.20', $issues[0]->getMessage());
    }

    /** Verifie la lecture de config.platform.php et de .tool-versions. */
    #[Test]
    public function testReadsComposerPlatformAndToolVersions(): void
    {
        $this->writeFile('composer.json', json_encode(['config' => ['platform' => ['php' => '8.2.20']]], \JSON_THROW_ON_ERROR));
        $this->writeFile('.tool-versions', "nodejs 20.11.0\nphp 8.3.36\n");

        $issues = $this->analyze();

        self::assertCount(1, $issues);
        self::assertSame(Severity::SUGGESTION, $issues[0]->getSeverity());
        self::assertStringContainsString('composer.json config.platform', $issues[0]->getMessage());
        self::assertStringContainsString('8.2.35', $issues[0]->getMessage());
    }

    /**
     * Verifie qu'une declaration qui ne decrit pas le PHP execute n'est jamais bloquante,
     * meme sous le correctif ou sur une branche sans support.
     */
    #[Test]
    #[DataProvider('unpinnedDeclarations')]
    public function testOnlyAsksToVerifyDeclarationsThatDoNotDescribeTheRuntime(string $file, string $content): void
    {
        $this->writeFile($file, $content);

        $issues = $this->analyze();

        self::assertCount(1, $issues);
        self::assertSame(Severity::SUGGESTION, $issues[0]->getSeverity());
        self::assertStringContainsString('php -v', $issues[0]->getSuggestion());
        self::assertStringContainsString('GHSA-w8hw-w9fx-j79w', $issues[0]->getDetail());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unpinnedDeclarations(): iterable
    {
        yield 'minimum de resolution composer' => ['composer.json', '{"config":{"platform":{"php":"8.2.0"}}}'];
        yield 'poste du developpeur' => ['.php-version', "8.3.10\n"];
        yield 'asdf ou mise' => ['.tool-versions', "php 8.4.1\n"];
        yield 'branche sans support dans composer' => ['composer.json', '{"config":{"platform":{"php":"8.1.31"}}}'];
    }

    /** Verifie que les images non PHP et les dependances de vendor/ sont ignorees. */
    #[Test]
    public function testIgnoresNonPhpImagesAndVendorDirectory(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  db:\n    image: mariadb:10.11\n  node:\n    image: node:20-alpine\n");
        $this->writeFile('vendor/acme/package/Dockerfile', "FROM php:7.4-cli\n");

        self::assertSame([], $this->analyze());
        self::assertFalse((new PhpSecurityPatchAnalyzer())->supports(new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: null,
            targetVersion: '2.2',
            projectPath: $this->tempDir,
        )));
    }

    /** Verifie qu'une meme version declaree deux fois dans un fichier n'est signalee qu'une fois. */
    #[Test]
    public function testReportsSameVersionOncePerFile(): void
    {
        $this->writeFile('Dockerfile', "FROM php:8.3.10-fpm AS base\nFROM php:8.3.10-fpm AS prod\n");

        self::assertCount(1, $this->analyze());
    }
}
