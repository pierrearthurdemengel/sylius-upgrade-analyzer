<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Command;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\AutoFix\FixEngine;
use PierreArthur\SyliusUpgradeAnalyzer\Exception\ProjectNotFoundException;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;
use PierreArthur\SyliusUpgradeAnalyzer\Report\ReporterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande principale d'analyse de migration Sylius.
 * Orchestre l'exécution de tous les analyseurs et la génération du rapport.
 */
#[AsCommand(
    name: 'sylius-upgrade:analyze',
    description: 'Analyse un projet Sylius et génère un rapport de migration vers Sylius 2.x',
)]
final class AnalyzeCommand extends Command
{
    /** @var list<AnalyzerInterface> */
    private readonly array $analyzers;

    /** @var array<string, ReporterInterface> */
    private readonly array $reporters;

    /**
     * @param iterable<AnalyzerInterface>  $analyzers  Liste des analyseurs disponibles
     * @param iterable<ReporterInterface>  $reporters  Liste des générateurs de rapports disponibles
     * @param FixEngine|null               $fixEngine  Moteur de correctifs automatiques (optionnel)
     */
    public function __construct(iterable $analyzers, iterable $reporters, private readonly ?FixEngine $fixEngine = null)
    {
        /* Conversion des itérables en tableaux indexés */
        $analyzerList = [];
        foreach ($analyzers as $analyzer) {
            $analyzerList[] = $analyzer;
        }
        $this->analyzers = $analyzerList;

        $reporterMap = [];
        foreach ($reporters as $reporter) {
            $reporterMap[$reporter->getFormat()] = $reporter;
        }
        $this->reporters = $reporterMap;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'project-path',
                InputArgument::OPTIONAL,
                'Chemin vers le répertoire racine du projet Sylius à analyser',
                '.',
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Format de sortie du rapport (console, json)',
                'console',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Chemin du fichier de sortie pour le rapport',
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exécuter uniquement les analyseurs spécifiés (par nom)',
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
                'pdf',
                null,
                InputOption::VALUE_NONE,
                'Générer un rapport au format PDF',
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Clé API pour les services externes',
            )
            ->addOption(
                'fix',
                null,
                InputOption::VALUE_NONE,
                'Appliquer les corrections automatiques disponibles',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simuler les corrections sans modifier les fichiers',
            )
            ->addOption(
                'save-baseline',
                null,
                InputOption::VALUE_NONE,
                'Sauvegarder les résultats comme baseline de référence',
            )
            ->addOption(
                'diff',
                null,
                InputOption::VALUE_NONE,
                'Comparer avec la baseline précédente et afficher les différences',
            )
            ->addOption(
                'rules',
                null,
                InputOption::VALUE_REQUIRED,
                'Chemin vers un fichier de règles personnalisées',
            )
            ->addOption(
                'sprint-plan',
                null,
                InputOption::VALUE_NONE,
                'Générer un plan de sprint pour la migration',
            )
            ->addOption(
                'velocity',
                null,
                InputOption::VALUE_REQUIRED,
                'Vélocité de l\'équipe en heures par sprint (pour le plan de sprint)',
            )
            ->addOption(
                'project-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Nom du projet (déduit du composer.json ou du répertoire si non fourni)',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /* Résolution et validation du chemin du projet */
        $projectPath = $this->resolveProjectPath($input->getArgument('project-path'));
        $targetVersion = $input->getOption('target-version');
        $format = $input->getOption('format');

        /* Détection de la version de Sylius depuis composer.lock */
        $syliusVersion = $this->detectSyliusVersion($projectPath);

        /* Création du rapport de migration */
        $report = new MigrationReport(
            startedAt: new \DateTimeImmutable(),
            detectedSyliusVersion: $syliusVersion,
            targetVersion: $targetVersion,
            projectPath: $projectPath,
        );

        /* Résolution du nom de projet : option > composer.json > nom du répertoire */
        $projectName = $input->getOption('project-name');
        if (!is_string($projectName) || $projectName === '') {
            $projectName = $this->resolveProjectName($projectPath);
        }
        $report->setProjectName($projectName);

        /* Filtrage des analyseurs selon l'option --only */
        $onlyAnalyzers = $input->getOption('only');
        $applicableAnalyzers = $this->getApplicableAnalyzers($report, $onlyAnalyzers);

        if (count($applicableAnalyzers) === 0) {
            $io->warning('Aucun analyseur applicable trouvé pour ce projet.');

            return Command::SUCCESS;
        }

        /* Exécution des analyseurs avec barre de progression */
        $io->text(sprintf('Analyse du projet : <info>%s</info>', $projectPath));
        $io->text(sprintf('Version détectée : <info>%s</info>', $syliusVersion ?? 'Non détectée'));
        $io->text(sprintf('Version cible : <info>%s</info>', $targetVersion));
        $io->newLine();

        $progressBar = new ProgressBar($output, count($applicableAnalyzers));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->start();

        foreach ($applicableAnalyzers as $analyzer) {
            $progressBar->setMessage(sprintf('Exécution : %s', $analyzer->getName()));
            $progressBar->advance();

            try {
                $analyzer->analyze($report);
            } catch (\Throwable $exception) {
                $io->newLine();
                $io->warning(sprintf(
                    'L\'analyseur "%s" a rencontré une erreur : %s',
                    $analyzer->getName(),
                    $exception->getMessage(),
                ));
            }
        }

        $progressBar->setMessage('Terminé');
        $progressBar->finish();
        $io->newLine(2);

        /* Finalisation du rapport */
        $report->complete();

        /* Génération du rapport dans le format demandé */
        $reporter = $this->getReporter($format);
        if ($reporter === null) {
            $io->error(sprintf('Format de rapport non supporté : %s', $format));

            return Command::FAILURE;
        }

        /* Préparation du contexte pour le reporter */
        $context = [
            'output_file' => $input->getOption('output'),
            'pdf' => $input->getOption('pdf'),
        ];

        $reporter->generate($report, $output, $context);

        /* Application des correctifs automatiques si demandé */
        $wantsFix = $input->getOption('fix') || $input->getOption('dry-run');
        if ($wantsFix && $this->fixEngine !== null) {
            $this->runFixEngine($report, $projectPath, $input, $io);
        }

        /* Code de sortie : 1 si des problèmes BREAKING existent, 0 sinon */
        $hasBreakingIssues = count($report->getIssuesBySeverity(Severity::BREAKING)) > 0;

        return $hasBreakingIssues ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Résout et valide le chemin absolu du projet.
     *
     * @throws ProjectNotFoundException Si le répertoire n'existe pas
     */
    private function resolveProjectPath(string $path): string
    {
        /* Résolution du chemin absolu */
        $realPath = realpath($path);

        if ($realPath === false || !is_dir($realPath)) {
            throw new ProjectNotFoundException(
                sprintf('Le répertoire du projet est introuvable : %s', $path),
            );
        }

        return $realPath;
    }

    /**
     * Détecte la version de Sylius installée en lisant composer.lock.
     *
     * @return ?string Version détectée ou null si non trouvée
     */
    private function detectSyliusVersion(string $projectPath): ?string
    {
        $composerLockPath = $projectPath . '/composer.lock';

        if (!file_exists($composerLockPath)) {
            return null;
        }

        $lockContent = file_get_contents($composerLockPath);
        if ($lockContent === false) {
            return null;
        }

        $lockData = json_decode($lockContent, true);
        if (!is_array($lockData)) {
            return null;
        }

        /* Recherche du paquet sylius/sylius dans les dépendances */
        $packages = array_merge(
            $lockData['packages'] ?? [],
            $lockData['packages-dev'] ?? [],
        );

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $packageName = $package['name'] ?? '';

            /* Recherche de sylius/sylius ou sylius/core-bundle comme indicateurs de version */
            if ($packageName === 'sylius/sylius' || $packageName === 'sylius/core-bundle') {
                return $package['version'] ?? null;
            }
        }

        return null;
    }

    /**
     * Filtre les analyseurs applicables au projet, en tenant compte de l'option --only.
     *
     * @param list<string> $onlyNames Noms des analyseurs à exécuter exclusivement
     * @return list<AnalyzerInterface>
     */
    private function getApplicableAnalyzers(MigrationReport $report, array $onlyNames): array
    {
        $applicable = [];

        foreach ($this->analyzers as $analyzer) {
            /* Filtrage par nom si l'option --only est utilisée */
            if (count($onlyNames) > 0 && !in_array($analyzer->getName(), $onlyNames, true)) {
                continue;
            }

            /* Vérification que l'analyseur supporte le projet */
            if ($analyzer->supports($report)) {
                $applicable[] = $analyzer;
            }
        }

        return $applicable;
    }

    /**
     * Récupère le générateur de rapport correspondant au format demandé.
     */
    private function getReporter(string $format): ?ReporterInterface
    {
        return $this->reporters[$format] ?? null;
    }

    /**
     * Déduit le nom du projet depuis le champ "name" du composer.json
     * ou, à défaut, depuis le nom du répertoire racine.
     */
    private function resolveProjectName(string $projectPath): string
    {
        $composerJsonPath = $projectPath . '/composer.json';

        if (file_exists($composerJsonPath)) {
            $content = file_get_contents($composerJsonPath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && isset($data['name']) && is_string($data['name']) && $data['name'] !== '') {
                    return $data['name'];
                }
            }
        }

        return basename($projectPath);
    }

    /**
     * Exécute le moteur de correctifs automatiques sur le rapport.
     * En mode --dry-run, affiche les correctifs sans les appliquer.
     * En mode --fix, applique les correctifs après confirmation.
     */
    private function runFixEngine(MigrationReport $report, string $projectPath, InputInterface $input, SymfonyStyle $io): void
    {
        if ($this->fixEngine === null) {
            return;
        }

        $fixes = $this->fixEngine->generateFixes($report, $projectPath);

        if ($fixes === []) {
            $io->text('Aucun correctif automatique disponible pour les issues détectées.');

            return;
        }

        $io->newLine();
        $io->section(sprintf('Correctifs automatiques disponibles : %d', count($fixes)));

        foreach ($fixes as $i => $fix) {
            $io->text(sprintf(
                '  <info>[%d]</info> [%s] %s',
                $i + 1,
                $fix->confidence->value,
                $fix->description,
            ));
        }

        /* Mode dry-run : affiche le patch unifié sans appliquer */
        if ($input->getOption('dry-run')) {
            $io->newLine();
            $io->text('<comment>Mode dry-run : aucune modification appliquée.</comment>');
            $io->newLine();
            $patch = $this->fixEngine->generatePatch($fixes);
            if ($patch !== '') {
                $io->text($patch);
            }

            return;
        }

        /* Mode fix : applique les correctifs */
        $io->newLine();
        $applied = 0;

        foreach ($fixes as $fix) {
            $this->fixEngine->applyFix($fix);
            $applied++;
        }

        $io->success(sprintf('%d correctif(s) appliqué(s).', $applied));
    }
}
