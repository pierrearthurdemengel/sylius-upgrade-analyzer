<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Exception\PdfGenerationException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder;
use PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\PdfRenderer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Générateur de rapport au format PDF, entièrement local.
 *
 * Produit un document destiné aux décideurs : synthèse, risques, feuille de route
 * et détail des problèmes. Nécessite le paquet optionnel dompdf/dompdf.
 */
final class PdfReporter implements ReporterInterface
{
    /** Fichier produit quand aucun --output n'est fourni (un PDF ne s'affiche pas en console) */
    public const DEFAULT_OUTPUT_FILE = 'sylius-migration-report.pdf';

    public function __construct(
        private readonly HtmlReportBuilder $htmlBuilder = new HtmlReportBuilder(),
        private readonly PdfRenderer $renderer = new PdfRenderer(),
    ) {
    }

    public function getFormat(): string
    {
        return 'pdf';
    }

    /**
     * Génère le PDF et l'écrit dans le fichier de sortie.
     *
     * Contexte reconnu : 'output_file' (chemin du PDF), 'tjm' (taux journalier
     * en euros, ajoute une estimation budgétaire au rapport) et 'promo' (false retire
     * l'encart d'accompagnement).
     *
     * @throws PdfGenerationException Si dompdf est absent, si le rendu échoue ou si le fichier ne peut être écrit
     */
    public function generate(MigrationReport $report, OutputInterface $output, array $context = []): void
    {
        $outputFile = $this->resolveOutputFile($context);
        $dailyRate = isset($context['tjm']) && is_int($context['tjm']) ? $context['tjm'] : null;

        $pdf = $this->renderer->render($this->htmlBuilder->buildProjectReport(
            $report,
            $dailyRate,
            MigrationServiceOffer::isEnabled($context),
        ));
        $this->write($outputFile, $pdf);

        $output->writeln(sprintf('<info>Rapport PDF généré dans %s</info>', $outputFile));
    }

    /**
     * Écrit un contenu PDF sur le disque en créant le répertoire parent si besoin.
     *
     * @throws PdfGenerationException Si l'écriture échoue
     */
    public function write(string $outputFile, string $pdf): void
    {
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new PdfGenerationException(sprintf('Impossible de créer le répertoire %s', $directory));
        }

        if (file_put_contents($outputFile, $pdf) === false) {
            throw new PdfGenerationException(sprintf('Impossible d\'écrire le fichier %s', $outputFile));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveOutputFile(array $context): string
    {
        $outputFile = $context['output_file'] ?? null;
        if (!is_string($outputFile) || $outputFile === '') {
            return self::DEFAULT_OUTPUT_FILE;
        }

        // Un chemin du type rapport.json (copié d'un exemple) produirait un PDF mal nommé.
        return (string) preg_replace('/\.(json|sarif|md|csv)$/i', '.pdf', $outputFile);
    }
}
