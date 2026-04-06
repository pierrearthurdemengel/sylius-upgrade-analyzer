<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Remplace la configuration "encoders:" par "password_hashers:" dans security.yaml
 * et simplifie les methodes getSalt() dans les fichiers PHP.
 */
final class UserEncoderFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'User Encoder';

    public function getName(): string
    {
        return 'User Encoder Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        if ($issue->getAnalyzer() !== self::TARGET_ANALYZER) {
            return false;
        }

        $file = $issue->getFile();
        if ($file === null) {
            return false;
        }

        return (bool) preg_match('/\.(yaml|yml|php)$/i', $file);
    }

    public function fix(MigrationIssue $issue, string $projectPath): ?MigrationFix
    {
        $filePath = $issue->getFile();
        if ($filePath === null) {
            return null;
        }

        $absolutePath = $this->resolveAbsolutePath($filePath, $projectPath);
        if (!file_exists($absolutePath)) {
            return null;
        }

        $originalContent = (string) file_get_contents($absolutePath);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $fixedContent = match ($extension) {
            'yaml', 'yml' => $this->fixYamlFile($originalContent),
            'php' => $this->fixPhpFile($originalContent),
            default => $originalContent,
        };

        if ($fixedContent === $originalContent) {
            return null;
        }

        $confidence = $extension === 'php' ? FixConfidence::MEDIUM : FixConfidence::HIGH;

        return new MigrationFix(
            confidence: $confidence,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Migration des encoders vers password_hashers dans %s.',
                basename($absolutePath),
            ),
        );
    }

    /**
     * Remplace "encoders:" par "password_hashers:" dans les fichiers YAML.
     */
    private function fixYamlFile(string $content): string
    {
        /* Remplacement de la cle racine */
        $content = (string) preg_replace('/^(\s*)encoders:/m', '$1password_hashers:', $content);

        /* Remplacement des algorithmes deprecies */
        $content = str_replace('algorithm: bcrypt', 'algorithm: auto', $content);
        $content = str_replace('algorithm: argon2i', 'algorithm: auto', $content);

        return $content;
    }

    /**
     * Simplifie les methodes getSalt() dans les fichiers PHP (retourne null).
     */
    private function fixPhpFile(string $content): string
    {
        /* Remplacement des implementations de getSalt() par un retour null */
        $content = (string) preg_replace(
            '/public\s+function\s+getSalt\s*\(\s*\)\s*(?::\s*\??\s*string\s*)?\{[^}]*\}/s',
            "public function getSalt(): ?string\n    {\n        return null;\n    }",
            $content,
        );

        return $content;
    }

    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
