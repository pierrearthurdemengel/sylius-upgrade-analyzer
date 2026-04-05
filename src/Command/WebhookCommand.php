<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Command;

use PierreArthur\SyliusUpgradeAnalyzer\Exception\LicenseExpiredException;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\ServiceUnavailableException;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de gestion des webhooks.
 * Permet de consulter, configurer et supprimer un webhook
 * pour recevoir des notifications automatiques (Agency).
 */
#[AsCommand(
    name: 'sylius-upgrade:webhook',
    description: 'Gère la configuration du webhook (get, set, delete)',
)]
final class WebhookCommand extends Command
{
    use ApiKeyResolverTrait;

    public function __construct(
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action à effectuer : get, set, delete',
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Clé API Agency (obligatoire)',
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'URL du webhook (pour l\'action set)',
            )
            ->addOption(
                'secret',
                null,
                InputOption::VALUE_REQUIRED,
                'Secret de signature du webhook (pour l\'action set)',
            )
            ->addOption(
                'events',
                null,
                InputOption::VALUE_REQUIRED,
                'Événements déclencheurs, séparés par des virgules (pour l\'action set)',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apiKey = $this->resolveApiKey($input);
        if ($apiKey === '') {
            $io->error('Clé API Agency requise. Utilisez --api-key ou définissez SYLIUS_UPGRADE_API_KEY.');

            return Command::FAILURE;
        }

        $action = $input->getArgument('action');

        try {
            return match ($action) {
                'get' => $this->handleGet($apiKey, $io),
                'set' => $this->handleSet($apiKey, $input, $io),
                'delete' => $this->handleDelete($apiKey, $io),
                default => $this->handleUnknown($action, $io),
            };
        } catch (LicenseExpiredException $exception) {
            $io->error(sprintf('Erreur d\'authentification : %s', $exception->getMessage()));

            return Command::FAILURE;
        } catch (ServiceUnavailableException $exception) {
            $io->error(sprintf('Service indisponible : %s', $exception->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * Affiche la configuration webhook actuelle.
     */
    private function handleGet(string $apiKey, SymfonyStyle $io): int
    {
        $result = $this->apiClient->getWebhook($apiKey);

        $url = $result['url'] ?? null;

        if (!is_string($url) || $url === '') {
            $io->info('Aucun webhook configuré.');

            return Command::SUCCESS;
        }

        $io->title('Configuration webhook');

        $io->horizontalTable(
            ['URL', 'Secret', 'Événements', 'Statut'],
            [[
                $url,
                isset($result['secret']) ? '••••' . substr((string) $result['secret'], -4) : '-',
                is_array($result['events'] ?? null) ? implode(', ', $result['events']) : '-',
                $result['status'] ?? 'actif',
            ]],
        );

        return Command::SUCCESS;
    }

    /**
     * Configure un nouveau webhook.
     */
    private function handleSet(string $apiKey, InputInterface $input, SymfonyStyle $io): int
    {
        $url = $input->getOption('url');
        if (!is_string($url) || $url === '') {
            $io->error('L\'option --url est obligatoire pour l\'action set.');

            return Command::FAILURE;
        }

        $config = ['url' => $url];

        $secret = $input->getOption('secret');
        if (is_string($secret) && $secret !== '') {
            $config['secret'] = $secret;
        }

        $events = $input->getOption('events');
        if (is_string($events) && $events !== '') {
            $config['events'] = array_map('trim', explode(',', $events));
        }

        $this->apiClient->setWebhook($config, $apiKey);

        $io->success(sprintf('Webhook configuré : %s', $url));

        return Command::SUCCESS;
    }

    /**
     * Supprime la configuration webhook.
     */
    private function handleDelete(string $apiKey, SymfonyStyle $io): int
    {
        $this->apiClient->deleteWebhook($apiKey);

        $io->success('Webhook supprimé.');

        return Command::SUCCESS;
    }

    /**
     * Gère une action inconnue.
     */
    private function handleUnknown(string $action, SymfonyStyle $io): int
    {
        $io->error(sprintf('Action inconnue : %s. Actions disponibles : get, set, delete.', $action));

        return Command::FAILURE;
    }
}
