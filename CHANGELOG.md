# Changelog

Tous les changements notables de ce projet sont documentés dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.0] - 2026-11-04

Version consacrée à la fiabilité du diagnostic : chaque détection a été confrontée à UPGRADE-2.0.md
et aux sources de Sylius 2.0. Les contrôles reposant sur des changements inexistants ont été retirés.

### Added
- `ResourceBundleAnalyzer` : détection des classes qui étendent un repository Sylius concret (par exemple `ProductRepository`), des interfaces `Sylius\Resource\*` (ResourceBundle 1.11+) et des surcharges de services déclarées en XML
- `GridCustomizationAnalyzer` : détection des templates d'action personnalisés (`sylius_grid.templates.action`)
- `MultiStoreChannelAnalyzer` : détection des appels nullsafe `?->findOneByHostname()`
- `PayumAnalyzer` : estimation des fichiers dépendant de Payum et prise en charge des imports groupés
- `RemovedFormTypeExtensionAnalyzer` : détection des références aux services `sylius.form.extension.type.country`, `.customer` et `.locale`, supprimés en 2.0
- Seuil de couverture de 80 % bloquant dans la CI ; la suite passe de 668 à 843 tests

### Changed
- `FormTypeExtensionPriorityAnalyzer` devient `RemovedFormTypeExtensionAnalyzer` : il signale comme bloquantes les classes qui héritent des cinq extensions de formulaire supprimées en 2.0, au lieu des tags `form.type_extension` sans priorité, une obligation qui n'existe pas
- Les appels à `findOneByHostname()`, supprimée en 2.0, sont désormais bloquants et la correction proposée est `findOneEnabledByHostname()`
- La CI installe les dépendances depuis `composer.lock` ; les jobs Symfony 6.4 testent réellement Symfony 6.4 (ils installaient Symfony 7.4)

### Fixed
- Les fichiers YAML contenant une balise personnalisée (`!tagged_iterator`, `!service`...) étaient entièrement ignorés par les analyseurs
- Imports avec alias non reconnus et faux positif sur `Doctrine\ORM\EntityRepository` (ResourceBundle, Grid)
- `GridCustomizationAnalyzer` cherchait des interfaces inexistantes (`ColumnTypeInterface`, `FilterTypeInterface`, `ActionTypeInterface`) au lieu de `FieldTypeInterface` et `FilterInterface`
- `PackagistClient` évalue les contraintes de version comme des intervalles : `^1.12 <2.0` n'est plus jugé compatible Sylius 2, `>=1.0 <3.0` l'est
- `RemovedPaymentGatewayAnalyzer` ne signale plus toute classe dont le nom contient « Stripe » ou « Paypal », ni un nom de factory cité en commentaire
- `MessageBusRenameAnalyzer` comptait deux fois les paramètres de constructeur et proposait de renommer `$syliusEventBus` en lui-même
- `CustomFixtureAnalyzer` estimait les fixtures Doctrine comme des fixtures Sylius et comptait deux fois les suites déclarées en `.yaml` et `.yml`
- `MultiStoreChannelAnalyzer` interprétait `sylius_channel.resources`, puis la clé inexistante `sylius_channel.channels`, comme une liste de canaux
- Compteurs du README : 48 analyseurs et 41 auto-fixers

### Removed
- `TranslationKeyAnalyzer` et `TranslationKeyFixer` : les renommages de préfixes signalés (`sylius.ui.shop`, `sylius.email`, `sylius.form.channel`...) n'existent pas dans Sylius 2.0, et le fixer réécrivait les clés du projet vers des préfixes inexistants
- `FormTypeExtensionPriorityFixer`, qui ajoutait une méthode `getPriority()` absente de l'API Form de Symfony

## [2.0.0] - 2026-11-04

L'outil fonctionne désormais entièrement en local : toutes les dépendances au service hébergé ont été retirées et la génération PDF est intégrée à la CLI.

### Added
- Format `pdf` généré entièrement en local (`--format=pdf`) : synthèse, facteurs de risque, feuille de route, problèmes bloquants détaillés, avertissements et suggestions. Repose sur `dompdf/dompdf`, proposé en dépendance optionnelle (`suggest`)
- Option `--tjm` sur `sylius-upgrade:analyze` et `sylius-upgrade:multi-analyze` : estimation budgétaire sur une base de 7 h par jour, arrondie à la demi-journée supérieure
- `CostEstimator` et `CostEstimate` pour le calcul budgétaire
- `MultiProjectReporter` : rapport consolidé multi-projets en console, JSON, Markdown ou PDF

### Changed
- `sylius-upgrade:multi-analyze` fonctionne désormais en local, sans clé API : tableau comparatif des projets (complexité, risque, bloquants, charge, budget) avec totaux, options `--format`, `--output`, `--tjm` et `--no-marketplace`
- Les projets partageant le même nom Composer sont distingués par le nom de leur répertoire dans le rapport consolidé

### Removed
- Option `--api-key` de `sylius-upgrade:multi-analyze`, `ApiClient`, `ApiKeyResolverTrait`, `LicenseExpiredException` et `ServiceUnavailableException`
- Commandes `sylius-upgrade:upload`, `sylius-upgrade:history` et `sylius-upgrade:webhook` : elles dépendaient du service hébergé, qui n'existe plus
- Mode `--before-id` / `--after-id` / `--api-key` de `sylius-upgrade:compare` (la comparaison de fichiers JSON locaux reste disponible)
- Options `--pdf` et `--api-key` de `sylius-upgrade:analyze`, qui n'avaient aucun effet
- `ReportUploader` et `StatisticsCollector` (envoi de données vers des domaines qui ne sont plus exploités)

### Fixed
- `sylius-upgrade:matrix` est désormais enregistrée dans le binaire (la commande existait mais n'était pas câblée)
- Le pied de page console ne propose plus une génération PDF inexistante

## [1.5.0] - 2026-04-07

### Added
- 4 nouveaux fixers automatiques (total : 45 fixers) couvrant les derniers analyseurs restants :
  - MultiStoreChannelFixer (HIGH) : remplace `findOneByHostname()` → `findOneEnabledByHostname()`
  - ConstructorSignatureFixer (MEDIUM) : ajoute un marqueur TODO sur les constructeurs surchargés de 24 classes Sylius modifiées
  - UserModelFieldFixer (MEDIUM) : commente les propriétés/méthodes dépréciées (`locked`, `expiresAt`, `credentialsExpireAt`, `Serializable`)
  - DoctrineXmlMappingFixer (LOW) : ajoute un marqueur TODO de conversion XML → attributs PHP sur les fichiers `*.orm.xml`
- 21 nouveaux tests, 45 assertions

### Changed
- Version bumped de 1.4.0 à 1.5.0

## [1.4.0] - 2026-04-06

### Added
- 29 nouveaux fixers automatiques (total : 41 fixers) couvrant tous les cas automatisables :
  - **Haute confiance (11)** :
    - CalendarToClockFixer : DateTimeProviderInterface → ClockInterface, sylius/calendar → symfony/clock
    - ClassMoveFixer : 9 FQCN déplacés entre bundles
    - BundleConfigFixer : suppression de 7 bundles + ajout de 5 nouveaux dans bundles.php
    - DeprecatedBundlePackageFixer : suppression de 6 packages dans composer.json
    - UserEncoderFixer : encoders → password_hashers en YAML, getSalt() → return null en PHP
    - ApiEndpointRestructureFixer : 3 chemins d'endpoint API mis à jour
    - PaymentRequestEnvFixer : ajout de 2 variables d'env Messenger manquantes
    - RoutingImportFixer : 7 imports de routing et paramètres renommés
    - RemovedConfigKeyFixer : commentaire de 7 clés de configuration supprimées
    - RemovedRouteFixer : commentaire des routes supprimées en PHP et Twig
    - RemovedClassFixer : commentaire des use statements de classes supprimées
  - **Confiance moyenne (18)** :
    - TranslationKeyFixer : 5 préfixes de clés de traduction renommés
    - BehatContextFixer : renommage des contextes Behat dépréciés
    - ApiSerializationGroupFixer : ajout du préfixe sylius: sur les groupes de sérialisation
    - ApiQueryExtensionSignatureFixer : $operationName → Operation $operation
    - FormTypeExtensionPriorityFixer : ajout de getPriority() manquant
    - OrderProcessorPriorityFixer : ajout de priority dans les tags YAML
    - DeprecatedEmailManagerFixer : 4 FQCN d'interface déplacés
    - AdminMenuEventFixer : 3 événements de menu admin renommés
    - ServiceDecoratorFixer : préfixes de services renommés dans decorates:
    - RemovedPaymentGatewayFixer : commentaire des gateways Stripe/PayPal supprimées
    - ShippingCalculatorFixer : DelegatingCalculatorInterface → CalculatorInterface
    - PromotionRuleCheckerFixer : CartQuantityRuleChecker déplacé vers Core
    - PayumConfigFixer : commentaire de la config Payum en YAML et PHP
    - GridCustomizationFixer : field: → fields: + doctrine/orm → doctrine_orm
    - ResourceBundleFixer : format driver doctrine/orm → doctrine_orm, PHPCR/MongoDB commentés
    - ApiPlatformMigrationFixer : 7 remplacements de namespace Core → Metadata/Symfony

### Changed
- Version bumped de 1.3.0 à 1.4.0
- 665 tests, 1815 assertions

## [1.3.0] - 2026-04-06

### Added
- 7 nouveaux fixers automatiques (total : 12 fixers) :
  - SwiftMailerFixer : Swift_Message → Email, Swift_Attachment → DataPart, config YAML (HIGH)
  - RenamedServiceIdFixer : 11 service IDs + 2 préfixes renommés dans YAML (HIGH)
  - SecurityFirewallFixer : new_api_admin_user → api_admin, new_api_shop_user → api_shop (HIGH)
  - LiipImagineConfigFixer : resolver/loader "default" → "sylius_image" (HIGH)
  - SonataBlockEventFixer : sonata_block_render_event() / sylius_template_event() → hook() (MEDIUM)
  - GridFilterEntityFixer : type entities → entity dans les grilles YAML (HIGH)
  - UseWebpackConfigFixer : suppression de use_webpack des YAML et Twig (HIGH)
- Intégration FixEngine dans AnalyzeCommand avec options `--fix` et `--dry-run`
- Les 5 fixers existants (TwigHook, Workflow, Security, MessageBus, CommandHandler) sont désormais câblés dans le binaire CLI

### Changed
- Version bumped de 1.2.0 à 1.3.0
- 558 tests, 1540 assertions

## [1.2.0] - 2026-04-05

### Added
- Option `--project-name` sur la commande `analyze` (déduit depuis composer.json ou nom du répertoire)
- Champ `meta.project_name` dans le rapport JSON
- CsvReporter : export CSV avec BOM UTF-8, séparateur `;`, compatible Excel (`--format=csv`)
- ApiClient centralisé pour les appels vers le service distant
- ReportLoader : utilitaire de chargement/reconstruction de rapports depuis JSON
- ApiKeyResolverTrait : résolution clé API partagée entre commandes
- Commande `sylius-upgrade:upload` enregistrée dans le binaire (existait mais n'était pas câblée)
- Commande `sylius-upgrade:multi-analyze` : analyse N projets, PDF consolidé (Agency, `POST /v1/reports/multi`)
- Commande `sylius-upgrade:compare` : diff de deux rapports (fichiers locaux ou IDs via API)
- Commande `sylius-upgrade:history` : historique des rapports (`GET /v1/reports/history`)
- Commande `sylius-upgrade:webhook` : gestion webhook get/set/delete (`/v1/settings/webhook`)

### Changed
- `JsonReporter::buildReportData()` rendu public pour réutilisation par multi-analyze
- Version bumped de 1.1.0 à 1.2.0

## [1.1.0] - 2026-04-04

### Added
- 20 nouveaux analyseurs pour couverture exhaustive de l'UPGRADE-2.0.md officiel :
  - BundleConfigurationAnalyzer : bundles supprimés/manquants dans bundles.php
  - CalendarClockAnalyzer : sylius/calendar vers symfony/clock
  - SecurityFirewallAnalyzer : renommage firewalls new_api_* vers api_*
  - UserModelFieldAnalyzer : champs supprimés (locked, expiresAt, credentialsExpireAt)
  - RemovedClassAnalyzer : 150+ classes supprimées
  - RenamedServiceIdAnalyzer : 21 service IDs renommés/supprimés
  - RemovedRouteAnalyzer : 43 routes supprimées
  - SonataBlockEventAnalyzer : sonata_block_render_event vers hook()
  - LiipImagineConfigAnalyzer : resolver/loader "default" vers "sylius_image"
  - ApiSerializationGroupAnalyzer : préfixe sylius: manquant sur les groupes
  - ApiEndpointRestructureAnalyzer : 8+ endpoints API restructurés
  - ConstructorSignatureAnalyzer : 24 classes avec constructeurs modifiés
  - GridFilterEntityAnalyzer : type entities vers entity
  - UseWebpackConfigAnalyzer : suppression de use_webpack
  - PhpNodeVersionAnalyzer : PHP 8.2+, Node 20+, Symfony 5.4
  - ApiQueryExtensionSignatureAnalyzer : operationName vers Operation
  - ClassMoveAnalyzer : 14 classes déplacées entre bundles
  - ServiceVisibilityAnalyzer : accès direct au container (services privés)
  - PaymentRequestEnvAnalyzer : variables d'env Messenger manquantes
  - DeprecatedBundlePackageAnalyzer : 7 paquets supprimés
- Total : 47 analyseurs, 458 tests, 1266 assertions

## [1.0.0] - 2026-04-04

### Added
- Documentation complète : README, CONTRIBUTING, guides d'utilisation
- 27 analyseurs couvrant les breaking changes principaux Sylius 2.0
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
