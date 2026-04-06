<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\AutoFix;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixConfidence;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\SecurityFirewallFixer;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour le fixer de renommage des firewalls de sécurité.
 */
final class SecurityFirewallFixerTest extends TestCase
{
    private SecurityFirewallFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixer = new SecurityFirewallFixer();
        $this->tempDir = sys_get_temp_dir() . '/firewall-fixer-test-' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createIssue(string $file = 'config/security.yaml'): MigrationIssue
    {
        return new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::DEPRECATION,
            analyzer: 'Security Firewall',
            message: 'Nom de firewall deprecie : new_api_admin_user',
            detail: '',
            suggestion: '',
            file: $file,
            line: null,
            codeSnippet: null,
            docUrl: null,
            estimatedMinutes: 30,
        );
    }

    #[Test]
    public function testRenamesFirewalls(): void
    {
        $filePath = $this->tempDir . '/config/security.yaml';
        file_put_contents($filePath, "security:\n    firewalls:\n        new_api_admin_user:\n            pattern: ^/api/v2/admin\n        new_api_shop_user:\n            pattern: ^/api/v2/shop\n");

        $fix = $this->fixer->fix($this->createIssue(), $this->tempDir);

        self::assertNotNull($fix);
        self::assertSame(FixConfidence::HIGH, $fix->confidence);
        self::assertStringContainsString('api_admin:', $fix->fixedContent);
        self::assertStringContainsString('api_shop:', $fix->fixedContent);
        self::assertStringNotContainsString('new_api_admin_user', $fix->fixedContent);
        self::assertStringNotContainsString('new_api_shop_user', $fix->fixedContent);
    }

    #[Test]
    public function testRenamesSecurityParameters(): void
    {
        $filePath = $this->tempDir . '/config/security.yaml';
        file_put_contents($filePath, "parameters:\n    sylius.security.new_api_route: /api/v2\n    sylius.security.new_api_regex: ^/api/v2\n");

        $fix = $this->fixer->fix($this->createIssue(), $this->tempDir);

        self::assertNotNull($fix);
        self::assertStringContainsString('sylius.security.api_route', $fix->fixedContent);
        self::assertStringContainsString('sylius.security.api_regex', $fix->fixedContent);
    }

    #[Test]
    public function testSupportsSecurityYaml(): void
    {
        self::assertTrue($this->fixer->supports($this->createIssue('config/security.yaml')));
    }

    #[Test]
    public function testDoesNotSupportNonSecurityFiles(): void
    {
        self::assertFalse($this->fixer->supports($this->createIssue('config/services.yaml')));
    }
}
