<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Générateur de rapport au format CSV.
 * Produit un fichier CSV avec BOM UTF-8 et séparateur point-virgule
 * pour une compatibilité optimale avec Excel.
 */
final class CsvReporter implements ReporterInterface
{
    /** BOM UTF-8 pour compatibilité Excel */
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /** Séparateur compatible Excel FR */
    private const SEPARATOR = ';';

    /** En-têtes des colonnes */
    private const HEADERS = [
        'Catégorie',
        'Sévérité',
        'Analyseur',
        'Message',
        'Détail',
        'Fichier',
        'Ligne',
        'Minutes estimées',
        'URL documentation',
    ];

    public function getFormat(): string
    {
        return 'csv';
    }

    public function generate(MigrationReport $report, OutputInterface $output, array $context = []): void
    {
        $outputFile = $context['output_file'] ?? null;

        if (!is_string($outputFile) || $outputFile === '') {
            $outputFile = 'migration-report.csv';
        }

        /* Remplacement de l'extension si l'utilisateur a fourni un .json ou .pdf */
        if (!str_ends_with($outputFile, '.csv')) {
            $replaced = preg_replace('/\.(json|pdf|sarif|md)$/i', '.csv', $outputFile);
            $outputFile = is_string($replaced) ? $replaced : $outputFile;
            if (!str_ends_with($outputFile, '.csv')) {
                $outputFile .= '.csv';
            }
        }

        $directory = dirname($outputFile);
        if (!is_dir($directory) && $directory !== '.') {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($outputFile, 'w');
        if ($handle === false) {
            $output->writeln(sprintf('<error>Impossible de créer le fichier CSV : %s</error>', $outputFile));

            return;
        }

        /* BOM UTF-8 pour qu'Excel détecte l'encodage correctement */
        fwrite($handle, self::UTF8_BOM);

        /* En-têtes */
        fputcsv($handle, self::HEADERS, self::SEPARATOR);

        /* Données : toutes les issues, toutes sévérités confondues */
        foreach ($report->getIssues() as $issue) {
            fputcsv($handle, $this->issueToRow($issue), self::SEPARATOR);
        }

        fclose($handle);

        $output->writeln(sprintf(
            '<info>Rapport CSV généré dans %s (%d lignes)</info>',
            $outputFile,
            count($report->getIssues()),
        ));
    }

    /**
     * Convertit un problème en ligne CSV.
     *
     * @return list<string>
     */
    private function issueToRow(MigrationIssue $issue): array
    {
        return [
            $issue->getCategory()->value,
            $issue->getSeverity()->value,
            $issue->getAnalyzer(),
            $issue->getMessage(),
            $issue->getDetail(),
            $issue->getFile() ?? '',
            $issue->getLine() !== null ? (string) $issue->getLine() : '',
            (string) $issue->getEstimatedMinutes(),
            $issue->getDocUrl() ?? '',
        ];
    }
}
