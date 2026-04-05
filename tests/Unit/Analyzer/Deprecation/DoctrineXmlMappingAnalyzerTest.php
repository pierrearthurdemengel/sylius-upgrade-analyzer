<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Analyzer\Deprecation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation\DoctrineXmlMappingAnalyzer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;

/**
 * Tests unitaires pour l'analyseur des mappings Doctrine XML.
 * Verifie la detection des fichiers *.orm.xml dans les repertoires de mapping.
 */
final class DoctrineXmlMappingAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid('docxml_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir((string) $item->getRealPath());
            } else {
                unlink((string) $item->getRealPath());
            }
        }

        rmdir($path);
    }

    private function createReport(): MigrationReport
    {
        return new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: '1.12',
            targetVersion: '2.0',
            projectPath: $this->tempDir,
        );
    }

    /**
     * Verifie que supports retourne false sans fichiers *.orm.xml.
     */
    #[Test]
    public function testSupportsReturnsFalseWithoutOrmXml(): void
    {
        $analyzer = new DoctrineXmlMappingAnalyzer();
        $report = $this->createReport();

        self::assertFalse($analyzer->supports($report));
    }

    /**
     * Verifie que supports retourne true quand config/doctrine/ contient un *.orm.xml.
     */
    #[Test]
    public function testSupportsReturnsTrueWithOrmXml(): void
    {
        $doctrineDir = $this->tempDir . '/config/doctrine';
        mkdir($doctrineDir, 0755, true);
        file_put_contents($doctrineDir . '/Product.orm.xml', '<entity name="App\Entity\Product" />');

        $analyzer = new DoctrineXmlMappingAnalyzer();
        $report = $this->createReport();

        self::assertTrue($analyzer->supports($report));
    }

    /**
     * Verifie la detection d'un fichier de mapping XML.
     */
    #[Test]
    public function testDetectsXmlMappingFile(): void
    {
        $doctrineDir = $this->tempDir . '/config/doctrine';
        mkdir($doctrineDir, 0755, true);
        file_put_contents($doctrineDir . '/Product.orm.xml', '<entity name="App\Entity\Product" />');

        $analyzer = new DoctrineXmlMappingAnalyzer();
        $report = $this->createReport();

        $analyzer->analyze($report);

        $mappingIssues = array_filter(
            $report->getIssues(),
            static fn ($issue): bool => str_contains($issue->getMessage(), 'Product'),
        );
        self::assertNotEmpty($mappingIssues);
    }

    /**
     * Verifie que getName retourne le nom attendu.
     */
    #[Test]
    public function testGetNameReturnsExpected(): void
    {
        $analyzer = new DoctrineXmlMappingAnalyzer();

        self::assertSame('Doctrine XML Mapping', $analyzer->getName());
    }
}
