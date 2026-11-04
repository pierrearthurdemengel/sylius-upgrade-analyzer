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

        /* En PHP, seuls les noms de classes changent : les API different (setSubject() devient
           subject(), Mailer et Transport ne s'instancient plus avec new), le code reste a reecrire.
           En YAML, la configuration generee est correcte mais la variable MAILER_DSN reste a definir. */
        return new MigrationFix(
            confidence: $extension === 'php' ? FixConfidence::LOW : FixConfidence::MEDIUM,
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
        /* Noms toujours pleinement qualifies : dans un fichier avec namespace, un nom relatif
           se resoudrait en App\...\Symfony\Component\Mime\Email. */
        foreach (self::CLASS_MAPPING as $old => $new) {
            $content = (string) preg_replace(
                '/(?<![\\\\\w])\\\\?' . preg_quote(ltrim($old, '\\'), '/') . '\b/',
                '\\\\' . str_replace('\\', '\\\\', ltrim($new, '\\')),
                $content,
            );
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
        /* Le bloc racine swiftmailer est remplace par framework.mailer.dsn ; l'ancien bloc est
           conserve en commentaire pour reporter les options particulieres (spool, delivery_addresses). */
        $content = (string) preg_replace_callback(
            '/^swiftmailer:[^\n]*\n(?:(?:[ \t]+[^\n]*|[ \t]*)(?:\n|$))*/m',
            static function (array $match): string {
                $oldBlock = rtrim($match[0]);
                $commented = implode("\n", array_map(
                    static fn (string $line): string => '# ' . $line,
                    explode("\n", $oldBlock),
                ));

                return "framework:\n    mailer:\n        dsn: '%env(MAILER_DSN)%'\n\n"
                    . "# TODO: ancienne configuration SwiftMailer, a reporter dans framework.mailer puis supprimer\n"
                    . $commented . "\n";
            },
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
