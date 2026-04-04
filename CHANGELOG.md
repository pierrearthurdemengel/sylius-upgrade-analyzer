# Changelog

Tous les changements notables de ce projet sont documentés dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-04-04

### Added
- Documentation complète : README, CONTRIBUTING, guides d'utilisation
- 27 analyseurs couvrant tous les breaking changes Sylius 2.0
- 5 reporters : Console, JSON, SARIF, Markdown, Baseline
- 5 fixers automatiques avec mode interactif
- GitHub Action pour intégration CI/CD
- Système de règles custom (.sylius-upgrade-rules.yaml)
- Planification de sprints et roadmap de migration
- Matrice de compatibilité des plugins

## [0.18.0] - 2026-04-04

### Added
- Base de données des alternatives de plugins (data/plugin-alternatives.yaml)
- PluginAlternativeSuggester pour recommander des remplacements
- CustomRuleLoader et CustomRuleAnalyzer pour les règles personnalisées
- StatisticsCollector pour métriques anonymes opt-in
- CompatibilityMatrixCommand (sylius-upgrade:matrix)

## [0.17.0] - 2026-04-04

### Added
- Script d'entrypoint pour la GitHub Action (action/entrypoint.sh)
- Script Node.js de commentaire PR (action/post-comment.js)

## [0.16.0] - 2026-04-04

### Added
- SarifReporter (format GitHub Code Scanning)
- MarkdownReporter avec badges et tableaux
- BaselineStorage pour suivi de progression
- RiskScorer avec facteurs de risque et recommandations
- MigrationRoadmapGenerator avec graphe de dépendances
- SprintPlanner avec répartition par vélocité

## [0.15.0] - 2026-04-04

### Added
- AutoFixInterface et MigrationFix value object
- FixEngine avec mode interactif et dry-run
- TwigHookFixer (confiance HIGH)
- WorkflowMigrationFixer (confiance MEDIUM)
- SecurityConfigFixer (confiance HIGH)
- MessageBusFixer (confiance HIGH)
- CommandHandlerFixer (confiance HIGH)

## [0.14.0] - 2026-04-04

### Added
- AdminMenuEventAnalyzer
- TranslationKeyAnalyzer
- PromotionRuleCheckerAnalyzer
- ShippingCalculatorAnalyzer
- DoctrineXmlMappingAnalyzer
- CustomFixtureAnalyzer
- MultiStoreChannelAnalyzer

## [0.13.0] - 2026-04-04

### Added
- ServiceDecoratorAnalyzer
- OrderProcessorPriorityAnalyzer
- FormTypeExtensionPriorityAnalyzer
- BehatContextDeprecationAnalyzer

## [0.12.0] - 2026-04-04

### Added
- MessageBusRenameAnalyzer
- CommandHandlerRenameAnalyzer
- DeprecatedEmailManagerAnalyzer
- RemovedPaymentGatewayAnalyzer

## [0.11.0] - 2026-04-04

### Added
- ReportUploader pour envoi vers le service PDF
- UploadCommand (sylius-upgrade:upload)
- Option --pdf dans AnalyzeCommand

## [0.10.0] - 2026-04-04

### Added
- SyliusUpgradeAnalyzerBundle pour intégration Symfony
- SyliusUpgradeAnalyzerExtension avec chargement services.yaml
- Configuration TreeBuilder avec toggles par analyseur
- AnalyzerCompilerPass pour collecte des services taggués

## [0.9.0] - 2026-04-04

### Added
- ApiPlatformMigrationAnalyzer
  - Détection @ApiResource, DataProvider, DataPersister
  - Détection namespace ApiPlatform\Core déprécié

## [0.8.0] - 2026-04-04

### Added
- SemanticUiAnalyzer (classes CSS Semantic UI dans les templates)
- JQueryAnalyzer (patterns jQuery dans les assets JS)
- WebpackEncoreAnalyzer (détection webpack.config.js + @symfony/webpack-encore)

## [0.7.0] - 2026-04-04

### Added
- GridCustomizationAnalyzer (grids YAML + custom columns/filters/actions)
- ResourceBundleAnalyzer (ressources YAML + repos/factories custom)

## [0.6.0] - 2026-04-04

### Added
- PluginCompatibilityStatus enum (COMPATIBLE, INCOMPATIBLE, PARTIALLY_COMPATIBLE, UNKNOWN, ABANDONED)
- PluginCompatibility value object
- AddonsMarketplaceClient (API addons.sylius.com)
- PackagistClient (fallback packagist.org)
- PluginCompatibilityAnalyzer avec cross-référencement

## [0.5.0] - 2026-04-04

### Added
- JsonReporter avec structure meta/summary/issues/estimated_hours_by_category

## [0.4.0] - 2026-04-04

### Added
- WinzouStateMachineAnalyzer (composer.json, YAML, PHP AST)
- SwiftMailerAnalyzer (composer.json, YAML, PHP AST)
- UserEncoderAnalyzer (security.yaml, getSalt(), encoders)
- PayumAnalyzer (composer.json, config, GatewayFactory)

## [0.3.0] - 2026-04-04

### Added
- ReporterInterface
- ConsoleReporter avec gauge ASCII et sortie colorée
- AnalyzeCommand (sylius-upgrade:analyze) avec options --format, --output, --only, --target-version

## [0.2.0] - 2026-04-04

### Added
- AnalyzerInterface (analyze, getName, supports)
- TwigTemplateOverrideAnalyzer avec scan des bundles overridés
- TwigHookMigrationMapper pour mapping vers Twig Hooks
- Fixtures de test : project-trivial, project-moderate, project-complex, project-major

## [0.1.0] - 2026-04-04

### Added
- Enum Severity (BREAKING, WARNING, SUGGESTION)
- Enum Category (TWIG, DEPRECATION, PLUGIN, GRID, RESOURCE, FRONTEND, API)
- Enum Complexity (TRIVIAL, MODERATE, COMPLEX, MAJOR)
- MigrationIssue (readonly, constructeur promu)
- MigrationReport avec calcul de complexité et heures estimées
- Exceptions métier (ProjectNotFoundException, ComposerJsonNotFoundException, etc.)

## [0.0.1] - 2026-04-04

### Added
- Configuration initiale du projet
- composer.json, phpunit.xml.dist, phpstan.neon, .php-cs-fixer.php
- Makefile avec targets install, test, lint, analyse
- GitHub Actions CI (PHP 8.2/8.3/8.4 × Symfony 6.4/7.2)
- Git hooks anti-co-auteur (prepare-commit-msg, commit-msg, pre-push)
- .github/action.yml pour la GitHub Action
