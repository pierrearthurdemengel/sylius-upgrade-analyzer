# Changelog

Tous les changements notables de ce projet sont documentés dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.5.0] - 2026-11-04

Chaque correctif automatique a été rejoué sur une entrée réelle et chaque règle confrontée aux sources de Sylius 1.14 et 2.0. Plusieurs règles produisaient un résultat faux : elles sont corrigées ou retirées.

### Added
- `--fix` affiche le patch complet avant de demander confirmation dans un terminal interactif
- Option `--min-confidence=high|medium|low` : n'applique que les correctifs au moins aussi fiables que le niveau demandé (défaut `low`, comportement inchangé)
- `docs/fixers.md` : niveau de confiance, effet et limites de chaque correctif
- `docs/fixer-examples.md` : entrée et sortie réelles de chaque correctif, générées depuis `tests/Fixtures/fixer-examples.php` et rejouées par `FixerExamplesTest`
- Analyseur `Order Processor Autoconfiguration` (remplace `Order Processor Priority`) : signale les order processors et cart contexts sans `#[AsOrderProcessor]` / `#[AsCartContext]` ni tag, que Sylius 2.0 n'enregistre plus par interface ; le correctif ajoute l'attribut
- `Renamed Service ID` : contrôleurs boutique renommés (`contact`, `currency_switch`, `locale_switch`, `register_thank_you`), trouvés dans les routes de Sylius-Standard 1.14

### Changed
- `Payum` : SUGGESTION hors total d'heures au lieu de BREAKING. Sylius 2.0 conserve Payum (`payum/payum-bundle` requis) ; la dépendance n'est plus signalée et le conseil de la retirer est supprimé
- `Admin Menu Event` : ne signale plus que les cinq menus admin supprimés en 2.0 ; `sylius.menu.admin.main` est inchangé
- `Behat Context Deprecation` : signale le service supprimé `sylius.behat.api_security` ; les contextes `calendar`, `doctrine_orm`, `managing_orders` et `checkout` existent toujours en 2.0
- `Shipping Calculator` : signale les références aux services renommés `sylius.shipping_calculator*` ; les interfaces et le tag sont identiques en 2.0
- `Command Handler Rename` : signale les références aux espaces de noms `Message*` d'AdminBundle et CoreBundle renommés `Command*` ; le code du projet (`src/Message/`) n'est plus visé
- `Renamed Service ID` et `Service Decorator` : seuls les renommages listés mot pour mot dans `UPGRADE-2.0.md` sont appliqués
- `SwiftMailer` : niveau `low` en PHP (API différente) et `medium` en YAML
- `Twig Hook` : niveau `medium`, le template du hook restant à créer
- Variables Payment Request : valeurs `sync://` de `UPGRADE-2.0.md` au lieu de `doctrine://default`

### Removed
- Correctif `Payum` : il commentait toute la configuration Payum, toujours supportée par Sylius 2.0
- Correctif `Admin Menu Event` : il renommait `sylius.menu.admin.main`, inchangé en 2.0
- Correctif `Behat Context` : il remplaçait les contextes `calendar` par des contextes `clock` inexistants
- Correctif `Grid Customization` : il réécrivait `driver: doctrine/orm`, valeur toujours correcte, et toutes les clés `field:`

### Fixed
- `Deprecated Bundle Package` laissait une virgule finale et rendait `composer.json` invalide
- `SwiftMailer` produisait un YAML où les options restaient hors de `framework.mailer`, et `new Email('Hello')`, appel invalide
- `Twig Hook` générait un YAML mal indenté, ignoré par Sylius
- `Removed Payment Gateway` ne commentait que la clé de la passerelle, rattachant `factory_name` à `gateways`
- `Resource Bundle` remplaçait `doctrine/orm` par `doctrine_orm`, valeur invalide
- `Renamed Service ID` renommait les commandes `sylius.console.command.*`, qui sont déjà les noms de Sylius 2.0
- `API Serialization Group` ne traitait que quatre noms qui ne sont pas des groupes Sylius
- `API Query Extension Signature` produisait un paramètre implicitement nullable, déprécié en PHP 8.4
- README : un correctif commentait des imports PHP Payum, contrairement à la garantie « aucun code PHP de paiement modifié »

## [2.4.0] - 2026-11-04

L'outil s'installe désormais sans toucher au projet audité et fonctionne quelle que soit la version de PHP de ce projet.

### Added
- Image Docker `ghcr.io/pierrearthurdemengel/sylius-upgrade-analyzer`, publiée à chaque version : PHP 8.3.36 embarqué, tous les formats dont le PDF, utilisateur non privilégié, images de base épinglées par empreinte
- Archive PHAR autonome attachée à chaque release GitHub, avec son empreinte SHA-256 (PDF non disponible depuis le PHAR, qui oriente vers l'image Docker)
- Installation globale documentée (`composer global require`)
- Détection de la version de Sylius depuis la contrainte de `composer.json` lorsque le projet ne versionne pas son `composer.lock` (squelettes, plugins)
- `docs/benchmarks.md` : résultats reproductibles sur Sylius-Standard 1.14, Sylius/InvoicingPlugin et BitBag SyliusWishlistPlugin
- `SECURITY.md` : versions supportées et signalement privé des vulnérabilités
- README : compatibilité explicite (« Supports every Sylius 1.x release, from 1.0 to 1.14 LTS »), fin du support de sécurité de Sylius 1.14 en décembre 2026, garanties des correctifs automatiques, section qualité et sécurité

### Changed
- `--fix` demande confirmation avant d'écrire dans un terminal interactif ; en CI (sans interaction), le comportement est inchangé
- Le cache de polices de dompdf est écrit dans le répertoire temporaire du système

### Fixed
- `PhpSecurityPatchAnalyzer` : plus d'alerte bloquante sur une déclaration qui ne décrit pas le PHP exécuté (`config.platform.php`, `.php-version`, `.tool-versions`), signalée désormais pour vérification avec `php -v` ; seule une image Docker épinglée sur un correctif reste bloquante
- `PhpSecurityPatchAnalyzer` : chaque alerte cite les avis officiels de l'équipe PHP (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp) et l'inscription au catalogue KEV de la CISA ; le lien de documentation mène à l'avis au lieu du journal des versions
- `RemovedConfigKeyAnalyzer` ne cherchait que le dernier segment des clés : toute ligne `class:` ou `templates` était signalée comme paramètre supprimé (11 faux problèmes bloquants sur Sylius-Standard 1.14). Les clés sont désormais lues dans le YAML parsé, à leur chemin exact, y compris dans les blocs `when@env`, avec la bonne ligne
- Les paquets publiés par un éditeur Sylius mais qui ne dépendent pas du cœur (`sylius-labs/coding-standard`, `sylius-labs/suite-tags-extension`) ne sont plus déclarés plugins incompatibles, estimés à 16 h chacun
- Badges du README qui laissaient croire que le projet audité devait tourner en PHP 8.2 et Symfony 6.4

## [2.3.0] - 2026-11-04

### Added
- Workflow hebdomadaire qui ouvre une issue lorsque php.net publie une version de sécurité plus récente que la table de l'analyseur
- `PhpSecurityPatchAnalyzer` et catégorie `security` : signale les versions de PHP déclarées par le projet (Dockerfile, compose, `config.platform.php`, `.php-version`, `.tool-versions`) sans le correctif de sécurité d'octobre 2026 (CVE-2026-109618 et CVE-2026-109619, exécution de code à distance exploitée), ainsi que les branches sans support

## [2.2.0] - 2026-11-04

### Security
- Mise à jour des composants Symfony (7.4.8 → derniers correctifs 7.4) corrigeant 10 alertes, dont CVE-2026-109413 (haute, récursion non bornée du parseur YAML, atteignable via les fichiers des projets audités)
- Contrainte `symfony/yaml` relevée à `^6.4.45|^7.4.18` : une installation ne peut plus résoudre un parseur vulnérable
- GitHub Action : entrées transmises aux scripts par variables d'environnement (plus d'interpolation dans `run:`), actions tierces épinglées sur SHA, commentaire de PR posté sans shell
- CI : actions épinglées sur SHA, permissions du jeton limitées à la lecture, `composer audit` dans tous les jobs

### Added
- Mention de l'offre d'accompagnement à la migration en fin de rapport console, Markdown et PDF (encart final). Les sorties JSON, SARIF et CSV n'en contiennent jamais
- Option `--no-promo` sur `sylius-upgrade:analyze` et `sylius-upgrade:multi-analyze` pour retirer cette mention
- `composer.json` : liens `homepage` et `support`, site de l'auteur

### Changed
- GitHub Action : nouvelle entrée `analyzer-version` ; `jq` n'est plus requis
- `composer.json` déclare l'extension `ext-dom`, utilisée pour lire les services XML

### Removed
- `action/entrypoint.sh`, inutilisé par l'action composite

### Fixed
- GitHub Action inutilisable : elle appelait une commande `analyse` inexistante. Elle lance désormais `sylius-upgrade:analyze`, installe l'outil hors du projet audité, expose réellement ses sorties, publie SARIF et commentaire avant d'échouer sur les issues bloquantes, et prend en compte `target-version`
- Commentaire de PR tronqué à 50 issues pour rester sous la limite de taille de GitHub, et non bloquant sur les PR issues de forks
- Exemple d'utilisation de l'action : référence `@v1` inexistante et permissions manquantes
- `ResourceBundleAnalyzer` analyse aussi les projets dont la seule personnalisation est une surcharge de service Sylius (YAML ou XML)
- `CustomFixtureAnalyzer` analyse aussi les fixtures Sylius placées hors de `src/DataFixtures/` sans configuration `sylius_fixtures`

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
