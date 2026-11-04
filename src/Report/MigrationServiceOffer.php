<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Report;

use PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimator;

/**
 * Mention de l'offre d'accompagnement a la migration, affichee en fin de rapport.
 *
 * La mention reste sobre et n'apparait que dans les formats lus par des humains
 * (console, Markdown, PDF) : JSON, SARIF et CSV sont consommes par des outils et ne
 * doivent jamais etre pollues. Elle se desactive avec l'option --no-promo, qui se
 * traduit par la cle de contexte CONTEXT_KEY a false.
 */
final class MigrationServiceOffer
{
    /** Cle du contexte des reporters indiquant si la mention est autorisee */
    public const CONTEXT_KEY = 'promo';

    /** Page de presentation de l'offre, en anglais comme le README et l'outil */
    private const LANDING_URL = 'https://creation-site-pro.fr/en/sylius-2-upgrade';

    public const AUTHOR = 'Pierre-Arthur Demengel';

    /**
     * Indique si la mention est autorisee pour ce contexte de generation.
     * Elle l'est par defaut : seule une desactivation explicite la retire.
     *
     * @param array<string, mixed> $context
     */
    public static function isEnabled(array $context): bool
    {
        return ($context[self::CONTEXT_KEY] ?? true) !== false;
    }

    /**
     * Construit le lien vers la page de l'offre, marque par le support d'origine
     * (console, markdown, pdf...) pour savoir lequel amene les visiteurs.
     */
    public function url(string $medium): string
    {
        return self::LANDING_URL . '?utm_source=analyzer-' . rawurlencode($medium);
    }

    /**
     * Ligne affichee en fin de sortie console, juste apres l'estimation.
     * Recoit la charge totale en heures (un projet ou un portefeuille de projets) ;
     * retourne null quand aucune charge n'est prevue : rien a proposer.
     */
    public function consoleLine(float $hours): ?string
    {
        if ($hours <= 0) {
            return null;
        }

        return sprintf(
            'Estimated effort: about %s. Need help with the migration? Free diagnosis by a Sylius migration specialist: %s',
            $this->formatDays($hours),
            $this->url('console'),
        );
    }

    /**
     * Ligne de pied de rapport Markdown. Le Markdown finit souvent dans un ticket
     * ou une pull request : une seule ligne, en citation, sans titre.
     */
    public function markdownLine(): string
    {
        return sprintf(
            '> Need help migrating to Sylius 2? %s is a freelance developer specialised in Sylius 1.x to 2.x migrations: [get a free diagnosis](%s).',
            self::AUTHOR,
            $this->url('markdown'),
        );
    }

    /**
     * Formate une charge en jours ouvres (7 h par jour, arrondi a la demi-journee superieure).
     */
    public function formatDays(float $hours): string
    {
        $days = ceil($hours / CostEstimator::HOURS_PER_DAY * 2) / 2;
        $formatted = rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');

        return sprintf('%s working day%s', $formatted, $days > 1 ? 's' : '');
    }
}
