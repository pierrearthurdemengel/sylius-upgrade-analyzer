<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;

/**
 * Ajoute #[AsOrderProcessor] ou #[AsCartContext] sur les classes que Sylius 2.0
 * n'enregistre plus par interface.
 *
 * L'attribut sans argument porte la priorite 0, celle du tag pose par l'interface en
 * Sylius 1.x : l'ordre d'execution reste identique. L'analyseur n'emet l'issue que pour
 * des classes sans tag explicite, il n'y a donc pas de double enregistrement.
 */
final class OrderProcessorAutoconfigurationFixer implements AutoFixInterface
{
    private const TARGET_ANALYZER = 'Order Processor Autoconfiguration';

    /** @var array<string, string> Interface citee dans l'issue => attribut */
    private const ATTRIBUTES = [
        'OrderProcessorInterface' => 'Sylius\\Bundle\\OrderBundle\\Attribute\\AsOrderProcessor',
        'CartContextInterface' => 'Sylius\\Bundle\\OrderBundle\\Attribute\\AsCartContext',
    ];

    public function getName(): string
    {
        return 'Order Processor Autoconfiguration Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        return $issue->getAnalyzer() === self::TARGET_ANALYZER
            && $issue->getFile() !== null
            && str_ends_with(strtolower($issue->getFile()), '.php')
            && $issue->getLine() !== null;
    }

    public function fix(MigrationIssue $issue, string $projectPath): ?MigrationFix
    {
        $filePath = $issue->getFile();
        $line = $issue->getLine();
        if ($filePath === null || $line === null) {
            return null;
        }

        $attribute = $this->attributeFor($issue->getMessage());
        $absolutePath = $this->resolveAbsolutePath($filePath, $projectPath);
        if ($attribute === null || !file_exists($absolutePath)) {
            return null;
        }

        $originalContent = (string) file_get_contents($absolutePath);
        $lines = explode("\n", $originalContent);
        $classIndex = $line - 1;

        if (!isset($lines[$classIndex]) || preg_match('/^(\s*)(?:final\s+|readonly\s+)*class\s/', $lines[$classIndex], $indent) !== 1) {
            return null;
        }

        $shortName = substr((string) strrchr($attribute, '\\'), 1);
        array_splice($lines, $classIndex, 0, [$indent[1] . '#[' . $shortName . ']']);
        $fixedContent = $this->addUseStatement(implode("\n", $lines), $attribute);

        return new MigrationFix(
            confidence: FixConfidence::HIGH,
            filePath: $absolutePath,
            originalContent: $originalContent,
            fixedContent: $fixedContent,
            description: sprintf('Ajout de #[%s] dans %s.', $shortName, basename($absolutePath)),
        );
    }

    private function attributeFor(string $message): ?string
    {
        foreach (self::ATTRIBUTES as $interface => $attribute) {
            if (str_contains($message, $interface)) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Ajoute l'import apres le dernier use de premier niveau, ou apres le namespace.
     */
    private function addUseStatement(string $content, string $class): string
    {
        if (preg_match('/^use\s+\\\\?' . preg_quote($class, '/') . ';/m', $content) === 1) {
            return $content;
        }

        $use = 'use ' . $class . ';';

        $last = preg_match_all('/^use\s+[^;(]+;[ \t]*$/m', $content, $uses, PREG_OFFSET_CAPTURE) > 0 ? end($uses[0]) : false;
        if ($last !== false) {
            $position = $last[1] + \strlen($last[0]);

            return substr($content, 0, $position) . "\n" . $use . substr($content, $position);
        }

        if (preg_match('/^namespace\s+[^;]+;[ \t]*$/m', $content, $namespace, PREG_OFFSET_CAPTURE) === 1) {
            $position = $namespace[0][1] + \strlen($namespace[0][0]);

            return substr($content, 0, $position) . "\n\n" . $use . substr($content, $position);
        }

        return (string) preg_replace('/^<\?php[ \t]*\n/', "<?php\n\n" . $use . "\n", $content, 1);
    }

    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
