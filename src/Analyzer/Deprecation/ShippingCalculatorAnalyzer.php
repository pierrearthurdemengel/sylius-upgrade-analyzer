<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Detecte les references aux services de calcul des frais de port renommes en Sylius 2.0.
 *
 * Les interfaces CalculatorInterface et DelegatingCalculatorInterface, le tag
 * sylius.shipping_calculator et l'attribut #[AsShippingCalculator] sont identiques en 1.14
 * et en 2.0 : un calculateur personnalise continue de fonctionner. Seuls les identifiants des
 * services du coeur changent (UPGRADE-2.0.md) ; une configuration qui les injecte ou les
 * decore echoue au demarrage du conteneur.
 */
final class ShippingCalculatorAnalyzer implements AnalyzerInterface
{
    private const MINUTES_PER_REFERENCE = 15;

    private const DOC_URL = 'https://github.com/Sylius/Sylius/blob/2.0/UPGRADE-2.0.md';

    /**
     * Ancien identifiant => nouvel identifiant (UPGRADE-2.0.md). Les plus longs d'abord, pour
     * ne pas confondre sylius.shipping_calculator avec sylius.shipping_calculator.flat_rate.
     *
     * @var array<string, string>
     */
    public const RENAMED_SERVICES = [
        'sylius.shipping_calculator.per_unit_rate' => 'sylius.calculator.shipping.per_unit_rate',
        'sylius.shipping_calculator.flat_rate' => 'sylius.calculator.shipping.flat_rate',
        'sylius.shipping_calculator' => 'sylius.calculator.shipping',
    ];

    /**
     * Reference a un service (et non au tag du meme nom) : "@id" en argument, ou cible de
     * decorates / alias.
     */
    public const REFERENCE_PATTERN = '/(?:@|(?:decorates|alias)\s*:\s*[\'"]?)(%s)(?![\w.])/';

    public function getName(): string
    {
        return 'Shipping Calculator';
    }

    public function supports(MigrationReport $report): bool
    {
        return is_dir($report->getProjectPath() . '/config');
    }

    public function analyze(MigrationReport $report): void
    {
        $configDir = $report->getProjectPath() . '/config';
        if (!is_dir($configDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($configDir)->name(['*.yaml', '*.yml']);

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($filePath)) as $index => $line) {
                $oldId = $this->referencedService($line);
                if ($oldId === null) {
                    continue;
                }

                $report->addIssue(new MigrationIssue(
                    severity: Severity::BREAKING,
                    category: Category::DEPRECATION,
                    analyzer: $this->getName(),
                    message: sprintf('Service renomme "%s" reference dans %s', $oldId, $file->getRelativePathname()),
                    detail: sprintf(
                        'Sylius 2.0 renomme le service %s en %s : la reference ligne %d ne se resout plus.',
                        $oldId,
                        self::RENAMED_SERVICES[$oldId],
                        $index + 1,
                    ),
                    suggestion: sprintf('Remplacer %s par %s.', $oldId, self::RENAMED_SERVICES[$oldId]),
                    file: $filePath,
                    line: $index + 1,
                    docUrl: self::DOC_URL,
                    estimatedMinutes: self::MINUTES_PER_REFERENCE,
                ));
            }
        }
    }

    /**
     * Retourne l'ancien identifiant reference sur la ligne, ou null.
     */
    private function referencedService(string $line): ?string
    {
        foreach (array_keys(self::RENAMED_SERVICES) as $oldId) {
            if (preg_match(sprintf(self::REFERENCE_PATTERN, preg_quote($oldId, '/')), $line) === 1) {
                return $oldId;
            }
        }

        return null;
    }
}
