<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Générateur de rapport au format SARIF 2.1.0.
 * Le format SARIF (Static Analysis Results Interchange Format) est un standard
 * OASIS pour l'échange de résultats d'analyse statique.
 */
final class SarifReporter implements ReporterInterface
{
    /** URL du schéma SARIF 2.1.0 */
    private const string SARIF_SCHEMA = 'https://docs.oasis-open.org/sarif/sarif/v2.1.0/cos02/schemas/sarif-schema-2.1.0.json';

    /** Version du schéma SARIF */
    private const string SARIF_VERSION = '2.1.0';

    /** Nom de l'outil */
    private const string TOOL_NAME = 'sylius-upgrade-analyzer';

    /** Version de l'outil */
    private const string TOOL_VERSION = '1.0.0';

    public function getFormat(): string
    {
        return 'sarif';
    }

    public function generate(MigrationReport $report, OutputInterface $output, array $context = []): void
    {
        $sarif = $this->buildSarifDocument($report);

        $json = json_encode($sarif, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $output->writeln('<error>Erreur lors de la génération du rapport SARIF.</error>');

            return;
        }

        /* Écriture dans un fichier si un chemin de sortie est fourni */
        if (isset($context['output_file']) && is_string($context['output_file'])) {
            $outputFile = $context['output_file'];
            $directory = dirname($outputFile);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($outputFile, $json . "\n");
            $output->writeln(sprintf(
                '<info>Rapport SARIF généré dans %s</info>',
                $outputFile,
            ));

            return;
        }

        /* Sortie directe sur la console */
        $output->writeln($json);
    }

    /**
     * Construit le document SARIF complet.
     *
     * @return array<string, mixed>
     */
    private function buildSarifDocument(MigrationReport $report): array
    {
        /* Extraction des règles uniques (une par analyseur) */
        $rules = $this->buildRules($report);
        $results = $this->buildResults($report, $rules);

        return [
            '$schema' => self::SARIF_SCHEMA,
            'version' => self::SARIF_VERSION,
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => self::TOOL_NAME,
                            'version' => self::TOOL_VERSION,
                            'informationUri' => 'https://github.com/pierre-arthur/sylius-upgrade-analyzer',
                            'rules' => array_values($rules),
                        ],
                    ],
                    'results' => $results,
                ],
            ],
        ];
    }

    /**
     * Construit les règles SARIF (une par analyseur unique).
     *
     * @return array<string, array<string, mixed>> Index par identifiant de règle
     */
    private function buildRules(MigrationReport $report): array
    {
        $rules = [];

        foreach ($report->getIssues() as $issue) {
            $ruleId = $this->buildRuleId($issue->getAnalyzer());

            if (isset($rules[$ruleId])) {
                continue;
            }

            $rules[$ruleId] = [
                'id' => $ruleId,
                'name' => $issue->getAnalyzer(),
                'shortDescription' => [
                    'text' => sprintf('Règle de l\'analyseur %s', $issue->getAnalyzer()),
                ],
                'defaultConfiguration' => [
                    'level' => $this->mapSeverityToLevel($issue->getSeverity()),
                ],
                'properties' => [
                    'category' => $issue->getCategory()->value,
                ],
            ];
        }

        return $rules;
    }

    /**
     * Construit les résultats SARIF à partir des problèmes du rapport.
     *
     * @param array<string, array<string, mixed>> $rules Index des règles par identifiant
     * @return list<array<string, mixed>>
     */
    private function buildResults(MigrationReport $report, array $rules): array
    {
        $results = [];
        $ruleIds = array_keys($rules);

        foreach ($report->getIssues() as $issue) {
            $ruleId = $this->buildRuleId($issue->getAnalyzer());
            $ruleIndex = array_search($ruleId, $ruleIds, true);

            $result = [
                'ruleId' => $ruleId,
                'ruleIndex' => $ruleIndex !== false ? $ruleIndex : 0,
                'level' => $this->mapSeverityToLevel($issue->getSeverity()),
                'message' => [
                    'text' => $issue->getMessage(),
                ],
            ];

            /* Ajout de la localisation dans le fichier si disponible */
            if ($issue->getFile() !== null) {
                $location = [
                    'physicalLocation' => [
                        'artifactLocation' => [
                            'uri' => $issue->getFile(),
                        ],
                    ],
                ];

                /* Ajout du numéro de ligne si disponible */
                if ($issue->getLine() !== null) {
                    $location['physicalLocation']['region'] = [
                        'startLine' => $issue->getLine(),
                    ];
                }

                $result['locations'] = [$location];
            }

            /* Ajout des corrections suggérées si disponibles */
            if ($issue->getSuggestion() !== '') {
                $result['fixes'] = [
                    [
                        'description' => [
                            'text' => $issue->getSuggestion(),
                        ],
                    ],
                ];
            }

            /* Ajout de l'URL de documentation si disponible */
            if ($issue->getDocUrl() !== null) {
                $result['relatedLocations'] = [];
                $result['properties'] = [
                    'docUrl' => $issue->getDocUrl(),
                    'estimatedMinutes' => $issue->getEstimatedMinutes(),
                    'category' => $issue->getCategory()->value,
                ];
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Génère un identifiant de règle à partir du nom de l'analyseur.
     * Convertit les espaces et caractères spéciaux en tirets.
     */
    private function buildRuleId(string $analyzerName): string
    {
        /* Conversion en identifiant compatible SARIF : minuscules, tirets */
        $ruleId = strtolower($analyzerName);
        $ruleId = (string) preg_replace('/[^a-z0-9]+/', '-', $ruleId);

        return trim($ruleId, '-');
    }

    /**
     * Convertit un niveau de sévérité en niveau SARIF.
     * BREAKING → error, WARNING → warning, SUGGESTION → note
     */
    private function mapSeverityToLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::BREAKING => 'error',
            Severity::WARNING => 'warning',
            Severity::SUGGESTION => 'note',
        };
    }
}
