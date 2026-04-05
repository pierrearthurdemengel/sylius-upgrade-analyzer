<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Command;

use PierreArthur\SyliusUpgradeAnalyzer\Exception\LicenseExpiredException;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\ServiceUnavailableException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ReportLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de comparaison de deux rapports de migration.
 * Accepte deux fichiers JSON locaux ou deux IDs de rapport (via l'API).
 * Affiche les issues résolues, nouvelles et la progression.
 */
#[AsCommand(
    name: 'sylius-upgrade:compare',
    description: 'Compare deux rapports de migration et affiche les différences',
)]
final class CompareCommand extends Command
{
    use ApiKeyResolverTrait;

    public function __construct(
        private readonly ReportLoader $reportLoader,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'before',
                InputArgument::OPTIONAL,
                'Fichier JSON du rapport de référence (avant)',
            )
            ->addArgument(
                'after',
                InputArgument::OPTIONAL,
                'Fichier JSON du rapport actuel (après)',
            )
            ->addOption(
                'before-id',
                null,
                InputOption::VALUE_REQUIRED,
                'ID du rapport de référence (API, nécessite --api-key)',
            )
            ->addOption(
                'after-id',
                null,
                InputOption::VALUE_REQUIRED,
                'ID du rapport actuel (API, nécessite --api-key)',
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Clé API Agency (nécessaire pour la comparaison par ID)',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $beforeId = $input->getOption('before-id');
        $afterId = $input->getOption('after-id');

        /* Mode API : comparaison par IDs via le service */
        if (is_string($beforeId) && $beforeId !== '' && is_string($afterId) && $afterId !== '') {
            return $this->compareViaApi($beforeId, $afterId, $input, $io);
        }

        /* Mode local : comparaison de fichiers JSON */
        $beforePath = $input->getArgument('before');
        $afterPath = $input->getArgument('after');

        if (!is_string($beforePath) || $beforePath === '' || !is_string($afterPath) || $afterPath === '') {
            $io->error('Fournissez deux fichiers JSON ou utilisez --before-id et --after-id.');

            return Command::FAILURE;
        }

        return $this->compareLocal($beforePath, $afterPath, $io);
    }

    /**
     * Compare deux rapports locaux et affiche le diff dans le terminal.
     */
    private function compareLocal(string $beforePath, string $afterPath, SymfonyStyle $io): int
    {
        try {
            $before = $this->reportLoader->loadFromFile($beforePath);
            $after = $this->reportLoader->loadFromFile($afterPath);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->displayDiff($before, $after, $io);

        return Command::SUCCESS;
    }

    /**
     * Compare deux rapports via l'API (Agency).
     */
    private function compareViaApi(string $beforeId, string $afterId, InputInterface $input, SymfonyStyle $io): int
    {
        $apiKey = $this->resolveApiKey($input);
        if ($apiKey === '') {
            $io->error('Clé API requise pour la comparaison par ID.');

            return Command::FAILURE;
        }

        try {
            $result = $this->apiClient->compareReports($beforeId, $afterId, $apiKey);
        } catch (LicenseExpiredException $exception) {
            $io->error(sprintf('Erreur d\'authentification : %s', $exception->getMessage()));

            return Command::FAILURE;
        } catch (ServiceUnavailableException $exception) {
            $io->error(sprintf('Service indisponible : %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->title('Comparaison de rapports (via API)');

        /* Affichage des résultats du service */
        $resolved = $result['resolved'] ?? [];
        $new = $result['new'] ?? [];
        $progress = $result['progress_percent'] ?? 0;

        $io->text(sprintf(
            'Progression : <info>%.1f%%</info> des issues résolues',
            $progress,
        ));
        $io->newLine();

        if (is_array($resolved) && $resolved !== []) {
            $io->text(sprintf('<fg=green>✓ %d issue(s) résolue(s) :</>', count($resolved)));
            foreach ($resolved as $issue) {
                if (is_array($issue)) {
                    $io->text(sprintf('  <fg=green>  - [%s] %s</>', $issue['category'] ?? '?', $issue['message'] ?? '?'));
                }
            }
            $io->newLine();
        }

        if (is_array($new) && $new !== []) {
            $io->text(sprintf('<fg=red>✗ %d nouvelle(s) issue(s) :</>', count($new)));
            foreach ($new as $issue) {
                if (is_array($issue)) {
                    $io->text(sprintf('  <fg=red>  + [%s] %s</>', $issue['category'] ?? '?', $issue['message'] ?? '?'));
                }
            }
            $io->newLine();
        }

        return Command::SUCCESS;
    }

    /**
     * Calcule et affiche le diff entre deux rapports locaux.
     */
    private function displayDiff(MigrationReport $before, MigrationReport $after, SymfonyStyle $io): void
    {
        $io->title('Comparaison de rapports');

        /* Construction des index par clé unique */
        $beforeIndex = $this->buildIssueIndex($before);
        $afterIndex = $this->buildIssueIndex($after);

        $resolved = [];
        foreach ($beforeIndex as $key => $issue) {
            if (!isset($afterIndex[$key])) {
                $resolved[] = $issue;
            }
        }

        $new = [];
        foreach ($afterIndex as $key => $issue) {
            if (!isset($beforeIndex[$key])) {
                $new[] = $issue;
            }
        }

        /* Résumé chiffré */
        $hoursBefore = $before->getTotalEstimatedHours();
        $hoursAfter = $after->getTotalEstimatedHours();
        $hoursSaved = $hoursBefore - $hoursAfter;

        $io->horizontalTable(
            ['', 'Avant', 'Après', 'Différence'],
            [
                ['Issues totales', count($before->getIssues()), count($after->getIssues()), $this->formatDelta(count($after->getIssues()) - count($before->getIssues()))],
                ['BREAKING', count($before->getIssuesBySeverity(Severity::BREAKING)), count($after->getIssuesBySeverity(Severity::BREAKING)), $this->formatDelta(count($after->getIssuesBySeverity(Severity::BREAKING)) - count($before->getIssuesBySeverity(Severity::BREAKING)))],
                ['Heures estimées', sprintf('%.1fh', $hoursBefore), sprintf('%.1fh', $hoursAfter), $this->formatHoursDelta(-$hoursSaved)],
            ],
        );

        $totalBefore = count($beforeIndex);
        $progressPercent = $totalBefore > 0
            ? round((count($resolved) / $totalBefore) * 100, 1)
            : 0.0;

        $io->text(sprintf('Progression : <info>%.1f%%</info> des issues résolues', $progressPercent));

        if ($hoursSaved > 0) {
            $io->text(sprintf('Heures économisées : <fg=green>%.1fh</>', $hoursSaved));
        }

        $io->newLine();

        /* Issues résolues */
        if ($resolved !== []) {
            $io->section(sprintf('Issues résolues (%d)', count($resolved)));
            foreach ($resolved as $issue) {
                $io->text(sprintf(
                    '  <fg=green>✓ [%s][%s] %s</>',
                    strtoupper($issue->getSeverity()->value),
                    $issue->getCategory()->value,
                    $issue->getMessage(),
                ));
            }
        }

        /* Nouvelles issues */
        if ($new !== []) {
            $io->section(sprintf('Nouvelles issues (%d)', count($new)));
            foreach ($new as $issue) {
                $io->text(sprintf(
                    '  <fg=red>✗ [%s][%s] %s</>',
                    strtoupper($issue->getSeverity()->value),
                    $issue->getCategory()->value,
                    $issue->getMessage(),
                ));
            }
        }

        if ($resolved === [] && $new === []) {
            $io->success('Les deux rapports sont identiques.');
        }
    }

    /**
     * Construit un index des issues par clé unique (analyseur + message + fichier + ligne).
     *
     * @return array<string, \PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue>
     */
    private function buildIssueIndex(MigrationReport $report): array
    {
        $index = [];

        foreach ($report->getIssues() as $issue) {
            $key = sprintf(
                '%s|%s|%s|%s',
                $issue->getAnalyzer(),
                $issue->getMessage(),
                $issue->getFile() ?? '',
                $issue->getLine() ?? '',
            );
            $index[$key] = $issue;
        }

        return $index;
    }

    /**
     * Formate un delta numérique avec signe et couleur.
     */
    private function formatDelta(int $delta): string
    {
        if ($delta < 0) {
            return sprintf('<fg=green>%d</>', $delta);
        }

        if ($delta > 0) {
            return sprintf('<fg=red>+%d</>', $delta);
        }

        return '0';
    }

    /**
     * Formate un delta d'heures avec signe et couleur.
     */
    private function formatHoursDelta(float $delta): string
    {
        if ($delta < -0.1) {
            return sprintf('<fg=red>+%.1fh</>', -$delta);
        }

        if ($delta > 0.1) {
            return sprintf('<fg=green>-%.1fh</>', $delta);
        }

        return '0h';
    }
}
