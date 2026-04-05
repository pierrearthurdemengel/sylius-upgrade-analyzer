<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Résout la clé API depuis l'option --api-key ou la variable d'environnement
 * SYLIUS_UPGRADE_API_KEY. Partagé entre toutes les commandes nécessitant
 * une authentification vers le service distant.
 */
trait ApiKeyResolverTrait
{
    /**
     * Résout la clé API depuis l'option de la commande ou la variable d'environnement.
     */
    private function resolveApiKey(InputInterface $input): string
    {
        $apiKey = $input->getOption('api-key');
        if (is_string($apiKey) && $apiKey !== '') {
            return $apiKey;
        }

        $envKey = $_ENV['SYLIUS_UPGRADE_API_KEY'] ?? getenv('SYLIUS_UPGRADE_API_KEY');

        return is_string($envKey) ? $envKey : '';
    }
}
