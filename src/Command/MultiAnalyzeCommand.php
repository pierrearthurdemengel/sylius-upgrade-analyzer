<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Command;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\LicenseExpiredException;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\ServiceUnavailableException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ApiClient;
use PierreArthur\SyliusUpgradeAnalyzer\Report\JsonReporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande d'analyse multi-projets.
 * Analyse N projets Sylius indépendamment, puis envoie le tout au service
 * pour génération d'un PDF consolidé (réservé aux clés Agency).
 */
#[AsCommand(
    name: 'sylius-upgrade:multi-analyze',
    description: 'Analyse plusieurs projets Sylius et génère un rapport PDF consolidé',
)]
final class MultiAnalyzeCommand extends Command
{
    use ApiKeyResolverTrait;

    /** @var list<AnalyzerInterface> */
    private readonly array $analyzers;

    /**
     * @param iterable<AnalyzerInterface> $analyzers Liste des analyseurs
     */
    public function __construct(
        iterable $analyzers,
        private readonly ApiClient $apiClient,
        private readonly JsonReporter $jsonReporter,
    ) {
        $analyzerList = [];
        foreach ($analyzers as $analyzer) {
            $analyzerList[] = $analyzer;
        }
        $this->analyzers = $analyzerList;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'project-paths',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Chemins vers les répertoires des projets Sylius à analyser',
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Clé API Agency (obligatoire)',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Chemin du fichier PDF de sortie',
                'multi-migration-report.pdf',
            )
            ->addOption(
                'tjm',
                null,
                InputOption::VALUE_REQUIRED,
                'Taux journalier moyen en euros (optionnel, inclus dans le rapport)',
            )
            ->addOption(
                'target-version',
                't',
                InputOption::VALUE_REQUIRED,
                'Version cible de Sylius pour la migration',
                '2.2',
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

        /** @var list<string> $projectPaths */
        $projectPaths = $input->getArgument('project-paths');
        $targetVersion = $input->getOption('target-version');
        $outputPath = $input->getOption('output');

        $reports = [];

        foreach ($projectPaths as $path) {
            $realPath = realpath($path);
            if ($realPath === false || !is_dir($realPath)) {
                $io->warning(sprintf('Répertoire introuvable, ignoré : %s', $path));

                continue;
            }

            $io->text(sprintf('Analyse de <info>%s</info>...', $realPath));

            $report = $this->analyzeProject($realPath, $targetVersion, $io);

            $reports[] = [
                'name' => $report->getProjectName() ?? basename($realPath),
                'report' => $this->serializeReport($report),
            ];

            $io->text(sprintf(
                '  → %d issues, %.1fh estimées',
                count($report->getIssues()),
                $report->getTotalEstimatedHours(),
            ));
        }

        if ($reports === []) {
            $io->error('Aucun projet analysé avec succès.');

            return Command::FAILURE;
        }

        $io->newLine();
        $io->text(sprintf('Envoi de %d rapport(s) au service...', count($reports)));

        /* Construction du payload multi-projets */
        $payload = ['reports' => $reports];

        $tjm = $input->getOption('tjm');
        if (is_string($tjm) && $tjm !== '') {
            $payload['options'] = ['tjm' => (int) $tjm];
        }

        try {
            $result = $this->apiClient->uploadMultiReport($payload, $apiKey);

            $pdfUrl = $result['pdf_url'] ?? '';
            if ($pdfUrl === '') {
                $io->error('Le service n\'a pas retourné d\'URL de PDF.');

                return Command::FAILURE;
            }

            $this->apiClient->downloadFile($pdfUrl, $outputPath);
            $io->success(sprintf('Rapport PDF consolidé téléchargé : %s', $outputPath));

            return Command::SUCCESS;
        } catch (LicenseExpiredException $exception) {
            $io->error(sprintf('Erreur d\'authentification : %s', $exception->getMessage()));

            return Command::FAILURE;
        } catch (ServiceUnavailableException $exception) {
            $io->error(sprintf('Service indisponible : %s', $exception->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * Analyse un projet individuel et retourne le rapport.
     */
    private function analyzeProject(string $projectPath, string $targetVersion, SymfonyStyle $io): MigrationReport
    {
        $syliusVersion = $this->detectSyliusVersion($projectPath);

        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: $syliusVersion,
            targetVersion: $targetVersion,
            projectPath: $projectPath,
        );

        $report->setProjectName($this->resolveProjectName($projectPath));

        foreach ($this->analyzers as $analyzer) {
            if (!$analyzer->supports($report)) {
                continue;
            }

            try {
                $analyzer->analyze($report);
            } catch (\Throwable $exception) {
                $io->warning(sprintf(
                    'Analyseur "%s" en erreur sur %s : %s',
                    $analyzer->getName(),
                    basename($projectPath),
                    $exception->getMessage(),
                ));
            }
        }

        $report->complete();

        return $report;
    }

    /**
     * Sérialise un rapport en tableau associatif pour l'envoi API.
     *
     * @return array<string, mixed>
     */
    private function serializeReport(MigrationReport $report): array
    {
        return $this->jsonReporter->buildReportData($report);
    }

    /**
     * Détecte la version de Sylius depuis composer.lock.
     */
    private function detectSyliusVersion(string $projectPath): ?string
    {
        $lockPath = $projectPath . '/composer.lock';
        if (!file_exists($lockPath)) {
            return null;
        }

        $content = file_get_contents($lockPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        $packages = array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = $package['name'] ?? '';
            if ($name === 'sylius/sylius' || $name === 'sylius/core-bundle') {
                return $package['version'] ?? null;
            }
        }

        return null;
    }

    /**
     * Déduit le nom du projet depuis composer.json ou le répertoire.
     */
    private function resolveProjectName(string $projectPath): string
    {
        $composerPath = $projectPath . '/composer.json';
        if (file_exists($composerPath)) {
            $content = file_get_contents($composerPath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && isset($data['name']) && is_string($data['name']) && $data['name'] !== '') {
                    return $data['name'];
                }
            }
        }

        return basename($projectPath);
    }
}
