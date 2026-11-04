<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\PdfGenerationException;

/**
 * Convertit un document HTML en PDF avec dompdf.
 *
 * dompdf est une dépendance optionnelle (section "suggest" du composer.json) :
 * la majorité des utilisateurs n'exportent qu'en console, JSON ou SARIF, et
 * l'imposer créerait des conflits de versions avec les projets Sylius audités.
 */
final class PdfRenderer
{
    /**
     * Indique si dompdf est installé et si la génération PDF est donc possible.
     */
    public function isAvailable(): bool
    {
        return class_exists(Dompdf::class);
    }

    /**
     * Rend le HTML fourni en document PDF A4 et retourne son contenu binaire.
     *
     * @throws PdfGenerationException Si dompdf n'est pas installé ou si le rendu échoue
     */
    public function render(string $html): string
    {
        if (!$this->isAvailable()) {
            throw new PdfGenerationException(
                'La génération PDF nécessite dompdf. Installez-le avec : composer require --dev dompdf/dompdf',
            );
        }

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        // Le rapport est entièrement autonome : aucune ressource distante ne doit être chargée.
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');

        try {
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();
        } catch (\Throwable $exception) {
            throw new PdfGenerationException(sprintf('Échec du rendu PDF : %s', $exception->getMessage()), 0, $exception);
        }

        $this->addPageNumbers($dompdf);

        $pdf = $dompdf->output();
        if ($pdf === '') {
            throw new PdfGenerationException('Le rendu PDF a produit un document vide.');
        }

        return $pdf;
    }

    /**
     * Ajoute la pagination en pied de page, sauf sur la page de couverture.
     */
    private function addPageNumbers(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');

        // La pagination est un confort : sans police résolue, le document reste valide sans elle.
        if ($font === null) {
            return;
        }

        $canvas->page_script(static function (int $pageNumber, int $pageCount, \Dompdf\Canvas $canvas) use ($font): void {
            if ($pageNumber === 1) {
                return;
            }

            $canvas->text(
                $canvas->get_width() - 90,
                $canvas->get_height() - 28,
                sprintf('%d / %d', $pageNumber, $pageCount),
                $font,
                8,
                [0.45, 0.48, 0.55],
            );
        });
    }
}
