<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Command;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\PdfGenerationException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Report\MultiProjectReporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande d'analyse multi-projets.
 * Analyse N projets Sylius indépendamment, puis produit un rapport consolidé
 * (console, JSON, Markdown ou PDF) avec un tableau comparatif et les totaux.
 */
#[AsCommand(
    name: 'sylius-upgrade:multi-analyze',
    description: 'Analyse plusieurs projets Sylius et génère un rapport consolidé',
)]
final class MultiAnalyzeCommand extends Command
{
    /** @var list<AnalyzerInterface> */
    private readonly array $analyzers;

    /**
     * @param iterable<AnalyzerInterface> $analyzers Liste des analyseurs
     */
    public function __construct(
        iterable $analyzers,
        private readonly MultiProjectReporter $reporter = new MultiProjectReporter(),
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
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                sprintf('Format du rapport consolidé (%s)', implode(', ', MultiProjectReporter::FORMATS)),
                'console',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Chemin du fichier de sortie (obligatoire en pratique pour le PDF, sinon ' . MultiProjectReporter::DEFAULT_PDF_FILE . ')',
            )
            ->addOption(
                'tjm',
                null,
                InputOption::VALUE_REQUIRED,
                'Taux journalier moyen en euros : ajoute une estimation budgétaire par projet',
            )
            ->addOption(
                'target-version',
                't',
                InputOption::VALUE_REQUIRED,
                'Version cible de Sylius pour la migration',
                '2.2',
            )
            ->addOption(
                'no-marketplace',
                null,
                InputOption::VALUE_NONE,
                'Désactiver la vérification de compatibilité via le marketplace',
            )
            ->addOption(
                'no-promo',
                null,
                InputOption::VALUE_NONE,
                'Ne pas afficher la mention d\'accompagnement a la migration en fin de rapport',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = (string) $input->getOption('format');
        if (!in_array($format, MultiProjectReporter::FORMATS, true)) {
            $io->error(sprintf('Format non supporté : %s (formats disponibles : %s)', $format, implode(', ', MultiProjectReporter::FORMATS)));

            return Command::FAILURE;
        }

        $tjm = $input->getOption('tjm');
        $dailyRate = null;
        if (is_string($tjm) && $tjm !== '') {
            if (!ctype_digit($tjm) || (int) $tjm <= 0) {
                $io->error('L\'option --tjm attend un nombre entier strictement positif (ex : --tjm=600).');

                return Command::FAILURE;
            }
            $dailyRate = (int) $tjm;
        }

        /** @var list<string> $projectPaths */
        $projectPaths = $input->getArgument('project-paths');
        $targetVersion = (string) $input->getOption('target-version');
        $outputFile = $input->getOption('output');
        $outputFile = is_string($outputFile) && $outputFile !== '' ? $outputFile : null;

        /* En sortie console brute (JSON ou Markdown sans fichier), la progression polluerait le document */
        $progress = $format === 'console' || $outputFile !== null ? $io : null;

        $reports = [];

        foreach ($projectPaths as $path) {
            $realPath = realpath($path);
            if ($realPath === false || !is_dir($realPath)) {
                $io->getErrorStyle()->warning(sprintf('Répertoire introuvable, ignoré : %s', $path));

                continue;
            }

            $progress?->text(sprintf('Analyse de <info>%s</info>...', $realPath));

            $report = $this->analyzeProject($realPath, $targetVersion, $io);
            $reports[] = $report;

            $progress?->text(sprintf(
                '  → %d issues, %.1fh estimées',
                count($report->getIssues()),
                $report->getTotalEstimatedHours(),
            ));
        }

        if ($reports === []) {
            $io->error('Aucun projet analysé avec succès.');

            return Command::FAILURE;
        }

        $progress?->newLine();

        $this->disambiguateProjectNames($reports);

        try {
            $this->reporter->generate($reports, $format, $output, $outputFile, $dailyRate, !$input->getOption('no-promo'));
        } catch (PdfGenerationException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Distingue les projets qui portent le même nom Composer (forks d'une même
     * base, déclinaisons par pays...) en ajoutant le nom de leur répertoire.
     *
     * @param list<MigrationReport> $reports
     */
    private function disambiguateProjectNames(array $reports): void
    {
        $occurrences = [];
        foreach ($reports as $report) {
            $name = (string) $report->getProjectName();
            $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
        }

        foreach ($reports as $report) {
            $name = (string) $report->getProjectName();
            if ($occurrences[$name] > 1) {
                $report->setProjectName(sprintf('%s (%s)', $name, basename($report->getProjectPath())));
            }
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
                $io->getErrorStyle()->warning(sprintf(
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
