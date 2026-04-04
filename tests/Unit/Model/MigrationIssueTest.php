<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Tests unitaires pour la classe MigrationIssue.
 * Vérifie la construction et les accesseurs de l'objet immuable.
 */
final class MigrationIssueTest extends TestCase
{
    /**
     * Vérifie qu'un problème créé avec tous les champs retourne les bonnes valeurs.
     */
    #[Test]
    public function testCreatesIssueWithAllFields(): void
    {
        $issue = new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::TWIG,
            analyzer: 'Twig Template Override',
            message: 'Template surchargé détecté',
            detail: 'Ce template surcharge un template natif.',
            suggestion: 'Migrer vers les hooks Twig.',
            file: 'templates/bundles/SyliusShopBundle/layout.html.twig',
            line: 42,
            codeSnippet: '{% extends "@SyliusShop/layout.html.twig" %}',
            docUrl: 'https://docs.sylius.com/en/latest/the-book/frontend/twig-hooks.html',
            estimatedMinutes: 120,
        );

        /* Vérification de chaque champ */
        self::assertSame(Severity::BREAKING, $issue->getSeverity());
        self::assertSame(Category::TWIG, $issue->getCategory());
        self::assertSame('Twig Template Override', $issue->getAnalyzer());
        self::assertSame('Template surchargé détecté', $issue->getMessage());
        self::assertSame('Ce template surcharge un template natif.', $issue->getDetail());
        self::assertSame('Migrer vers les hooks Twig.', $issue->getSuggestion());
        self::assertSame('templates/bundles/SyliusShopBundle/layout.html.twig', $issue->getFile());
        self::assertSame(42, $issue->getLine());
        self::assertSame('{% extends "@SyliusShop/layout.html.twig" %}', $issue->getCodeSnippet());
        self::assertSame('https://docs.sylius.com/en/latest/the-book/frontend/twig-hooks.html', $issue->getDocUrl());
        self::assertSame(120, $issue->getEstimatedMinutes());
    }

    /**
     * Vérifie qu'un problème peut être créé avec uniquement les champs obligatoires.
     */
    #[Test]
    public function testCreatesIssueWithMinimalFields(): void
    {
        $issue = new MigrationIssue(
            severity: Severity::SUGGESTION,
            category: Category::DEPRECATION,
            analyzer: 'Deprecation Analyzer',
            message: 'Méthode dépréciée détectée',
            detail: 'La méthode est dépréciée.',
            suggestion: 'Utiliser la nouvelle API.',
        );

        /* Les champs optionnels doivent avoir leurs valeurs par défaut */
        self::assertSame(Severity::SUGGESTION, $issue->getSeverity());
        self::assertSame(Category::DEPRECATION, $issue->getCategory());
        self::assertSame('Deprecation Analyzer', $issue->getAnalyzer());
        self::assertSame('Méthode dépréciée détectée', $issue->getMessage());
        self::assertNull($issue->getFile());
        self::assertNull($issue->getLine());
        self::assertNull($issue->getCodeSnippet());
        self::assertNull($issue->getDocUrl());
        self::assertSame(0, $issue->getEstimatedMinutes());
    }

    /**
     * Vérifie que getSeverity retourne la bonne valeur pour chaque niveau.
     */
    #[Test]
    public function testGetSeverityReturnsCorrectValue(): void
    {
        /* Test avec chaque niveau de sévérité */
        foreach (Severity::cases() as $severity) {
            $issue = new MigrationIssue(
                severity: $severity,
                category: Category::TWIG,
                analyzer: 'test',
                message: 'test',
                detail: 'test',
                suggestion: 'test',
            );

            self::assertSame($severity, $issue->getSeverity());
        }
    }

    /**
     * Vérifie que getCategory retourne la bonne valeur pour chaque catégorie.
     */
    #[Test]
    public function testGetCategoryReturnsCorrectValue(): void
    {
        /* Test avec chaque catégorie disponible */
        foreach (Category::cases() as $category) {
            $issue = new MigrationIssue(
                severity: Severity::WARNING,
                category: $category,
                analyzer: 'test',
                message: 'test',
                detail: 'test',
                suggestion: 'test',
            );

            self::assertSame($category, $issue->getCategory());
        }
    }

    /**
     * Vérifie que getEstimatedMinutes retourne 0 par défaut si non spécifié.
     */
    #[Test]
    public function testGetEstimatedMinutesDefaultsToZero(): void
    {
        $issue = new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::TWIG,
            analyzer: 'test',
            message: 'test',
            detail: 'test',
            suggestion: 'test',
        );

        self::assertSame(0, $issue->getEstimatedMinutes());
    }

    /**
     * Vérifie que getFile retourne null par défaut si aucun fichier n'est spécifié.
     */
    #[Test]
    public function testGetFileReturnsNullByDefault(): void
    {
        $issue = new MigrationIssue(
            severity: Severity::BREAKING,
            category: Category::PLUGIN,
            analyzer: 'test',
            message: 'test',
            detail: 'test',
            suggestion: 'test',
        );

        self::assertNull($issue->getFile());
    }

    /**
     * Vérifie que getLine retourne null par défaut si aucun numéro de ligne n'est spécifié.
     */
    #[Test]
    public function testGetLineReturnsNullByDefault(): void
    {
        $issue = new MigrationIssue(
            severity: Severity::WARNING,
            category: Category::GRID,
            analyzer: 'test',
            message: 'test',
            detail: 'test',
            suggestion: 'test',
        );

        self::assertNull($issue->getLine());
    }
}
