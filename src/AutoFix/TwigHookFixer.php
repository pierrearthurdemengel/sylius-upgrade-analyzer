<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

use PierreArthur\SyliusUpgradeAnalyzer\Analyzer\Twig\TwigHookMigrationMapper;
use PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Fixer pour la migration des surcharges de templates Twig vers les hooks Twig Sylius 2.x.
 * Genere la configuration YAML necessaire pour enregistrer un hook Twig
 * en remplacement d'une surcharge de template.
 */
final class TwigHookFixer implements AutoFixInterface
{
    /** Nom de l'analyseur cible */
    private const TARGET_ANALYZER = 'Twig Template Override';

    private readonly TwigHookMigrationMapper $mapper;

    public function __construct(?TwigHookMigrationMapper $mapper = null)
    {
        $this->mapper = $mapper ?? new TwigHookMigrationMapper();
    }

    public function getName(): string
    {
        return 'Twig Hook Fixer';
    }

    public function supports(MigrationIssue $issue): bool
    {
        return $issue->getAnalyzer() === self::TARGET_ANALYZER;
    }

    public function fix(MigrationIssue $issue, string $projectPath): ?MigrationFix
    {
        $filePath = $issue->getFile();
        if ($filePath === null) {
            return null;
        }

        /* Extraction du chemin du bundle depuis le chemin du fichier */
        $bundlePath = $this->extractBundlePath($filePath);
        if ($bundlePath === null) {
            return null;
        }

        /* Recherche du hook correspondant via le mapper */
        $hookMapping = $this->mapper->mapTemplateToHook($bundlePath);
        if ($hookMapping === null) {
            return null;
        }

        $hookName = $hookMapping['hook'];

        /* Generation du nom unique du composant hook */
        $hookComponentName = $this->generateHookComponentName($bundlePath);

        /* Lecture du contenu original du template */
        $absolutePath = $this->resolveAbsolutePath($filePath, $projectPath);
        $originalContent = '';
        if (file_exists($absolutePath)) {
            $originalContent = (string) file_get_contents($absolutePath);
        }

        /* Generation du chemin de la configuration YAML du hook */
        $configFilePath = $projectPath . '/config/packages/sylius_twig_hooks.yaml';

        /* Lecture de la configuration existante ou creation d'une nouvelle */
        $existingConfig = '';
        if (file_exists($configFilePath)) {
            $existingConfig = (string) file_get_contents($configFilePath);
        }

        /* Generation de la configuration YAML pour le hook */
        $hookConfig = $this->generateHookYamlConfig($hookName, $hookComponentName, $bundlePath);

        /* Ajout de la configuration au fichier existant */
        $fixedContent = $this->mergeHookConfig($existingConfig, $hookConfig);

        /* La fusion est textuelle : si le hook n'arrive pas a sa place (fichier existant
           structure autrement), aucun correctif plutot qu'une configuration ignoree ou invalide. */
        if (!$this->registersHook($fixedContent, $hookName, $hookComponentName)) {
            return null;
        }

        /* Le template du composant (hooks/...) reste a creer a partir de la surcharge :
           tant qu'il n'existe pas, le rendu de la page echoue. */
        return new MigrationFix(
            confidence: FixConfidence::MEDIUM,
            filePath: $configFilePath,
            originalContent: $existingConfig,
            fixedContent: $fixedContent,
            description: sprintf(
                'Configuration du hook Twig "%s" pour remplacer la surcharge du template "%s". '
                . 'Le composant "%s" est enregistre dans le hook.',
                $hookName,
                $bundlePath,
                $hookComponentName,
            ),
        );
    }

    /**
     * Extrait le chemin relatif au bundle depuis le chemin complet du fichier.
     */
    private function extractBundlePath(string $filePath): ?string
    {
        $normalized = str_replace('\\', '/', $filePath);

        /* Extraction depuis templates/bundles/ */
        if (preg_match('#templates/bundles/(Sylius\w+Bundle/.+)$#', $normalized, $matches)) {
            return $matches[1];
        }

        /* Extraction depuis app/Resources/.../views/ */
        if (preg_match('#app/Resources/(Sylius\w+Bundle)/views/(.+)$#', $normalized, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }

        return null;
    }

    /**
     * Genere un nom unique pour le composant hook a partir du chemin du template.
     */
    private function generateHookComponentName(string $bundlePath): string
    {
        /* Suppression de l'extension */
        $name = preg_replace('/\.html\.twig$|\.twig$/', '', $bundlePath);

        /* Remplacement des separateurs par des underscores */
        $name = str_replace(['/', '\\'], '_', (string) $name);

        /* Conversion en snake_case */
        $name = (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);

        return 'app.' . strtolower($name);
    }

    /**
     * Genere la configuration YAML pour un hook Twig.
     */
    private function generateHookYamlConfig(string $hookName, string $componentName, string $bundlePath): string
    {
        /* Suppression de l'extension pour le chemin du template du hook */
        $templateName = preg_replace('/\.html\.twig$/', '', $bundlePath);
        $hookTemplatePath = sprintf('hooks/%s.html.twig', str_replace('/', '_', (string) $templateName));

        /* Indentation de sylius_twig_hooks > hooks > nom du hook > composant */
        $yaml = sprintf(
            "        %s:\n"
            . "            %s:\n"
            . "                template: '%s'\n"
            . "                # priority: 0\n",
            $hookName,
            $componentName,
            $hookTemplatePath,
        );

        return $yaml;
    }

    /**
     * Fusionne la nouvelle configuration de hook avec la configuration existante.
     */
    /**
     * Verifie que la configuration produite enregistre le composant sous sylius_twig_hooks.hooks.
     */
    private function registersHook(string $yaml, string $hookName, string $componentName): bool
    {
        try {
            $config = Yaml::parse($yaml);
        } catch (ParseException) {
            return false;
        }

        return \is_array($config)
            && \is_array($config['sylius_twig_hooks']['hooks'][$hookName][$componentName] ?? null);
    }

    private function mergeHookConfig(string $existingConfig, string $hookConfig): string
    {
        /* Si le fichier est vide, creation de la structure de base */
        if (trim($existingConfig) === '') {
            return "sylius_twig_hooks:\n"
                . "    hooks:\n"
                . $hookConfig;
        }

        /* Verification que la structure de base existe */
        if (!str_contains($existingConfig, 'sylius_twig_hooks:')) {
            return $existingConfig . "\n\nsylius_twig_hooks:\n"
                . "    hooks:\n"
                . $hookConfig;
        }

        /* Ajout de la configuration a la fin de la section hooks existante */
        if (str_contains($existingConfig, 'hooks:')) {
            return $existingConfig . $hookConfig;
        }

        /* Ajout de la section hooks sous sylius_twig_hooks */
        return $existingConfig . "\n    hooks:\n" . $hookConfig;
    }

    /**
     * Resout le chemin absolu d'un fichier a partir du chemin relatif et du projet.
     */
    private function resolveAbsolutePath(string $filePath, string $projectPath): string
    {
        /* Si le chemin est deja absolu */
        if (str_starts_with($filePath, '/') || preg_match('/^[A-Z]:/i', $filePath)) {
            return $filePath;
        }

        return rtrim($projectPath, '/') . '/' . $filePath;
    }
}
