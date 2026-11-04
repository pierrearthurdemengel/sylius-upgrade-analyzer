<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Deprecation;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\AnalyzerInterface;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

/**
 * Analyseur des variables d'environnement pour le transport Payment Request.
 * Sylius 2.0 introduit un nouveau transport Messenger pour les Payment Requests
 * qui necessite des variables d'environnement specifiques.
 * Cet analyseur verifie la presence de ces variables dans les fichiers .env
 * et la configuration du transport dans messenger.yaml.
 */
final class PaymentRequestEnvAnalyzer implements AnalyzerInterface
{
    /** Estimation en minutes pour la configuration */
    private const MINUTES_PER_ISSUE = 30;

    /** URL de la documentation de migration */
    private const DOC_URL = 'https://docs.sylius.com/migration-2.0';

    /** Variables d'environnement requises */
    private const REQUIRED_ENV_VARS = [
        'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN',
        'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN',
    ];

    /** Fichiers .env a verifier */
    private const ENV_FILES = [
        '.env',
        '.env.local',
        '.env.dist',
    ];

    public function getName(): string
    {
        return 'Payment Request Env';
    }

    public function supports(MigrationReport $report): bool
    {
        $projectPath = $report->getProjectPath();

        /* Verifier qu'au moins un fichier .env existe */
        foreach (self::ENV_FILES as $envFile) {
            if (file_exists($projectPath . '/' . $envFile)) {
                return true;
            }
        }

        /* Verifier la presence d'un fichier messenger.yaml */
        $messengerYaml = $projectPath . '/config/packages/messenger.yaml';
        if (file_exists($messengerYaml)) {
            return true;
        }

        return false;
    }

    public function analyze(MigrationReport $report): void
    {
        $projectPath = $report->getProjectPath();

        /* Etape 1 : verification des variables d'environnement dans les fichiers .env */
        $this->analyzeEnvFiles($report, $projectPath);

        /* Etape 2 : verification de la configuration messenger.yaml */
        $this->analyzeMessengerConfig($report, $projectPath);
    }

    /**
     * Verifie la presence des variables d'environnement requises dans les fichiers .env.
     */
    private function analyzeEnvFiles(MigrationReport $report, string $projectPath): void
    {
        $foundVars = [];

        /* Lecture de tous les fichiers .env pour collecter les variables definies */
        foreach (self::ENV_FILES as $envFile) {
            $envFilePath = $projectPath . '/' . $envFile;
            if (!file_exists($envFilePath)) {
                continue;
            }

            $content = (string) file_get_contents($envFilePath);
            foreach (self::REQUIRED_ENV_VARS as $envVar) {
                if (str_contains($content, $envVar)) {
                    $foundVars[$envVar] = true;
                }
            }
        }

        /* Signalement des variables manquantes */
        foreach (self::REQUIRED_ENV_VARS as $envVar) {
            if (isset($foundVars[$envVar])) {
                continue;
            }

            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: sprintf(
                    'Variable d\'environnement %s manquante',
                    $envVar,
                ),
                detail: sprintf(
                    'La variable d\'environnement %s n\'a ete trouvee dans aucun fichier .env. '
                    . 'Cette variable est requise par le nouveau systeme de Payment Requests de Sylius 2.0 '
                    . 'pour configurer le transport Messenger.',
                    $envVar,
                ),
                suggestion: sprintf(
                    'Ajouter %s=sync:// dans le fichier .env du projet.',
                    $envVar,
                ),
                docUrl: self::DOC_URL,
                estimatedMinutes: self::MINUTES_PER_ISSUE,
            ));
        }
    }

    /**
     * Verifie la configuration du transport payment_request dans messenger.yaml.
     */
    private function analyzeMessengerConfig(MigrationReport $report, string $projectPath): void
    {
        $messengerYaml = $projectPath . '/config/packages/messenger.yaml';
        if (!file_exists($messengerYaml)) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: 'Fichier config/packages/messenger.yaml absent',
                detail: 'Le fichier de configuration Messenger n\'existe pas. '
                    . 'Sylius 2.0 necessite une configuration de transport pour les Payment Requests.',
                suggestion: 'Creer le fichier config/packages/messenger.yaml avec la configuration '
                    . 'du transport sylius_payment_request.',
                docUrl: self::DOC_URL,
                estimatedMinutes: self::MINUTES_PER_ISSUE,
            ));

            return;
        }

        $content = (string) file_get_contents($messengerYaml);

        /* Verification de la presence de la configuration payment_request */
        if (!str_contains($content, 'sylius_payment_request')
            && !str_contains($content, 'payment_request')
        ) {
            $report->addIssue(new MigrationIssue(
                severity: Severity::WARNING,
                category: Category::DEPRECATION,
                analyzer: $this->getName(),
                message: 'Configuration du transport payment_request absente dans messenger.yaml',
                detail: 'Le fichier config/packages/messenger.yaml ne contient pas de configuration '
                    . 'pour le transport payment_request. Ce transport est requis par le nouveau '
                    . 'systeme de Payment Requests de Sylius 2.0.',
                suggestion: 'Ajouter la configuration du transport sylius_payment_request '
                    . 'et sylius_payment_request_failed dans le fichier messenger.yaml.',
                file: $messengerYaml,
                docUrl: self::DOC_URL,
                estimatedMinutes: self::MINUTES_PER_ISSUE,
            ));
        }
    }
}
