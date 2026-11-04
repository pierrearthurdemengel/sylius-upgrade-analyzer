<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Detecte les references aux classes des dossiers Message, MessageHandler et
 * MessageDispatcher d'AdminBundle et CoreBundle, renommes en Command, CommandHandler et
 * CommandDispatcher en Sylius 2.0 (UPGRADE-2.0.md, Architecture changes).
 *
 * Les 18 classes concernees gardent leur nom court : seul l'espace de noms change. Le code
 * du projet (src/Message/, *MessageHandler) n'est pas concerne et n'est pas signale.
 */
final class CommandHandlerRenameAnalyzer implements AnalyzerInterface
{
    private const MINUTES_PER_FILE = 15;

    private const DOC_URL = 'https://github.com/Sylius/Sylius/blob/2.0/UPGRADE-2.0.md#architecture-changes';

    /**
     * Ancien espace de noms => nouveau, verifies classe par classe entre 1.14 et 2.0.
     *
     * @var array<string, string>
     */
    public const RENAMED_NAMESPACES = [
        'Sylius\\Bundle\\AdminBundle\\MessageHandler\\' => 'Sylius\\Bundle\\AdminBundle\\CommandHandler\\',
        'Sylius\\Bundle\\AdminBundle\\Message\\' => 'Sylius\\Bundle\\AdminBundle\\Command\\',
        'Sylius\\Bundle\\CoreBundle\\MessageDispatcher\\' => 'Sylius\\Bundle\\CoreBundle\\CommandDispatcher\\',
        'Sylius\\Bundle\\CoreBundle\\MessageHandler\\' => 'Sylius\\Bundle\\CoreBundle\\CommandHandler\\',
        'Sylius\\Bundle\\CoreBundle\\Message\\' => 'Sylius\\Bundle\\CoreBundle\\Command\\',
    ];

    public function getName(): string
    {
        return 'Command Handler Rename';
    }

    public function supports(MigrationReport $report): bool
    {
        return is_dir($report->getProjectPath() . '/src');
    }

    public function analyze(MigrationReport $report): void
    {
        $srcDir = $report->getProjectPath() . '/src';
        if (!is_dir($srcDir)) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $code = (string) file_get_contents($filePath);
            if (!str_contains($code, 'Bundle\\Message')) {
                continue;
            }

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new class (self::RENAMED_NAMESPACES) extends NodeVisitorAbstract {
                /** @var array<string, int> Classe referencee => premiere ligne */
                public array $references = [];

                /**
                 * @param array<string, string> $namespaces
                 */
                public function __construct(private readonly array $namespaces)
                {
                }

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Name) {
                        return null;
                    }

                    $name = ltrim($node->toString(), '\\');
                    foreach (array_keys($this->namespaces) as $namespace) {
                        if (str_starts_with($name, $namespace) && !isset($this->references[$name])) {
                            $this->references[$name] = $node->getStartLine();
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->references as $class => $line) {
                $newClass = self::renamed($class);
                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Classe %s deplacee vers %s', $class, $newClass),
                    detail: sprintf(
                        'Sylius 2.0 renomme les dossiers Message, MessageHandler et MessageDispatcher en Command, '
                        . 'CommandHandler et CommandDispatcher : %s n\'existe plus (%s ligne %d).',
                        $class,
                        $file->getRelativePathname(),
                        $line,
                    ),
                    suggestion: sprintf('Remplacer %s par %s.', $class, $newClass),
                    file: $filePath,
                    line: $line,
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_PER_FILE,
                ));
            }
        }
    }

    /**
     * Nom de la classe apres renommage de son espace de noms.
     */
    public static function renamed(string $class): string
    {
        foreach (self::RENAMED_NAMESPACES as $old => $new) {
            if (str_starts_with($class, $old)) {
                return $new . substr($class, \strlen($old));
            }
        }

        return $class;
    }
}
