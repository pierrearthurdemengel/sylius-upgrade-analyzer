<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Corrige les configurations SwiftMailer en remplaçant les références
 * par les équivalents Symfony Mailer dans les fichiers YAML et PHP.
 */
final class SwiftMailerFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'SwiftMailer';

    /** Mapping des classes SwiftMailer vers Symfony Mailer */
    private const CLASS_MAPPING = [
        'Swift_Message' => 'Symfony\\Component\\Mime\\Email',
        'Swift_Attachment' => 'Symfony\\Component\\Mime\\Part\\DataPart',
        'Swift_Mailer' => 'Symfony\\Component\\Mailer\\MailerInterface',
        'Swift_SmtpTransport' => 'Symfony\\Component\\Mailer\\Transport',
        '\\Swift_Message' => '\\Symfony\\Component\\Mime\\Email',
        '\\Swift_Attachment' => '\\Symfony\\Component\\Mime\\Part\\DataPart',
        '\\Swift_Mailer' => '\\Symfony\\Component\\Mailer\\MailerInterface',
        '\\Swift_SmtpTransport' => '\\Symfony\\Component\\Mailer\\Transport',
    ];

    public function getName(): string
    {
        return 'SwiftMailer Fixer';
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

        /* Supporte les fichiers PHP et YAML contenant des références SwiftMailer */
        return (bool) preg_match('/\.(php|yaml|yml)$/i', $file);
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
            'php' => $this->fixPhpFile($originalContent),
            'yaml', 'yml' => $this->fixYamlFile($originalContent),
            default => $originalContent,
        };

        if ($fixedContent === $originalContent) {
            return null;
        }

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf(
                'Remplacement des references SwiftMailer par Symfony Mailer dans %s.',
                basename($absolutePath),
            ),
        );
    }

    /**
     * Remplace les classes SwiftMailer par les équivalents Symfony Mailer dans un fichier PHP.
     */
    private function fixPhpFile(string $content): string
    {
        foreach (self::CLASS_MAPPING as $old => $new) {
            $content = str_replace($old, $new, $content);
        }

        /* Remplacement des use statements */
        $content = (string) preg_replace(
            '/^use\s+Swiftmailer\\\\[^;]+;$/m',
            '',
            $content,
        );

        return $content;
    }

    /**
     * Remplace la configuration swiftmailer par symfony/mailer dans les fichiers YAML.
     */
    private function fixYamlFile(string $content): string
    {
        /* Remplacement du bloc de configuration racine */
        $content = (string) preg_replace(
            '/^swiftmailer:/m',
            'framework:' . "\n" . '    mailer:',
            $content,
        );

        /* Remplacement des paramètres courants */
        $content = str_replace(
            "'swiftmailer.mailer'",
            "'mailer.mailer'",
            $content,
        );
        $content = str_replace(
            '@swiftmailer.mailer',
            '@mailer',
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
