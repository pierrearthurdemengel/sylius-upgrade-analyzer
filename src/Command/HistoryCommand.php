<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Command;

use PierreArthur\SyliusUpgradeAnalyzer\Exception\LicenseExpiredException;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\ServiceUnavailableException;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande d'affichage de l'historique des rapports générés.
 * Interroge le service distant pour récupérer la liste des rapports
 * associés à la clé API fournie.
 */
#[AsCommand(
    name: 'sylius-upgrade:history',
    description: 'Affiche l\'historique des rapports de migration générés',
)]
final class HistoryCommand extends Command
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
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Clé API pour l\'authentification',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre de rapports à afficher',
                '20',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apiKey = $this->resolveApiKey($input);
        if ($apiKey === '') {
            $io->error('Clé API requise. Utilisez --api-key ou définissez SYLIUS_UPGRADE_API_KEY.');

            return Command::FAILURE;
        }

        $limit = (int) $input->getOption('limit');
        if ($limit <= 0) {
            $limit = 20;
        }

        try {
            $result = $this->apiClient->fetchHistory($apiKey, $limit);
        } catch (LicenseExpiredException $exception) {
            $io->error(sprintf('Erreur d\'authentification : %s', $exception->getMessage()));

            return Command::FAILURE;
        } catch (ServiceUnavailableException $exception) {
            $io->error(sprintf('Service indisponible : %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $reports = $result['reports'] ?? [];

        if (!is_array($reports) || $reports === []) {
            $io->info('Aucun rapport dans l\'historique.');

            return Command::SUCCESS;
        }

        $io->title('Historique des rapports');

        $rows = [];
        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }

            $rows[] = [
                $report['created_at'] ?? '-',
                $report['project_name'] ?? '-',
                sprintf('%s → %s', $report['sylius_version'] ?? '?', $report['target_version'] ?? '?'),
                $report['issues_count'] ?? '-',
                isset($report['total_hours']) ? sprintf('%.1fh', $report['total_hours']) : '-',
                $report['complexity'] ?? '-',
                $report['report_id'] ?? '-',
            ];
        }

        $io->table(
            ['Date', 'Projet', 'Versions', 'Issues', 'Heures', 'Complexité', 'ID'],
            $rows,
        );

        $total = $result['total'] ?? count($rows);
        $io->text(sprintf('%d rapport(s) affiché(s) sur %d au total.', count($rows), $total));

        return Command::SUCCESS;
    }
}
