# Analyzers Reference

This document describes every built-in analyzer included in Sylius Upgrade Analyzer. Each section covers what the analyzer detects, how it estimates effort, and where to find documentation for the underlying migration.

---

## Table of Contents

1. [Twig Template Override](#1-twig-template-override)
2. [Winzou State Machine](#2-winzou-state-machine)
3. [SwiftMailer](#3-swiftmailer)
4. [User Encoder](#4-user-encoder)
5. [Payum](#5-payum)
6. [Plugin Compatibility](#6-plugin-compatibility)
7. [Grid Customization](#7-grid-customization)
8. [Resource Bundle](#8-resource-bundle)
9. [Semantic UI](#9-semantic-ui)
10. [jQuery](#10-jquery)
11. [Webpack Encore](#11-webpack-encore)
12. [API Platform Migration](#12-api-platform-migration)
13. [Message Bus Rename](#13-message-bus-rename)
14. [Command Handler Rename](#14-command-handler-rename)
15. [Deprecated Email Manager](#15-deprecated-email-manager)
16. [Removed Payment Gateway](#16-removed-payment-gateway)
17. [Service Decorator](#17-service-decorator)
18. [Order Processor Priority](#18-order-processor-priority)
19. [Removed Form Type Extension](#19-removed-form-type-extension)
20. [Behat Context Deprecation](#20-behat-context-deprecation)
21. [Admin Menu Event](#21-admin-menu-event)
22. [Promotion Rule Checker](#22-promotion-rule-checker)
23. [Shipping Calculator](#23-shipping-calculator)
24. [Doctrine XML Mapping](#24-doctrine-xml-mapping)
25. [Custom Fixture](#25-custom-fixture)
26. [Multi-Store Channel](#26-multi-store-channel)
27. [Bundle Configuration](#27-bundle-configuration)
28. [Calendar to Clock](#28-calendar-to-clock)
29. [Security Firewall](#29-security-firewall)
30. [User Model Field](#30-user-model-field)
31. [Removed Class](#31-removed-class)
32. [Renamed Service ID](#32-renamed-service-id)
33. [Removed Route](#33-removed-route)
34. [Sonata Block Event](#34-sonata-block-event)
35. [LiipImagine Config](#35-liipimagine-config)
36. [API Serialization Group](#36-api-serialization-group)
37. [API Endpoint Restructure](#37-api-endpoint-restructure)
38. [Constructor Signature](#38-constructor-signature)
39. [Grid Filter Entity](#39-grid-filter-entity)
40. [Use Webpack Config](#40-use-webpack-config)
41. [PHP Node Version](#41-php-node-version)
42. [API Query Extension Signature](#42-api-query-extension-signature)
43. [Class Move](#43-class-move)
44. [Service Visibility](#44-service-visibility)
45. [Payment Request Env](#45-payment-request-env)
46. [Deprecated Bundle Package](#46-deprecated-bundle-package)

---

## 1. Twig Template Override

**Class:** `TwigTemplateOverrideAnalyzer`
**Category:** Twig
**Severity:** WARNING

### What it detects

Scans the following directories for overridden Sylius templates:

- `templates/bundles/SyliusShopBundle/`
- `templates/bundles/SyliusAdminBundle/`
- `templates/bundles/SyliusUiBundle/`
- `app/Resources/SyliusShopBundle/views/` (legacy Symfony 3 convention)
- `src/*/Resources/views/` (bundle views)

In Sylius 2.x, the template override system is replaced by **Twig Hooks**. Every overridden template must be migrated to a hook-based component.

### Estimation formula

Each template is scored based on its complexity (number of blocks, Twig functions, lines of code). The mapper cross-references known Sylius templates with their corresponding Twig hook names. Typical range: 30-120 minutes per template.

### Documentation

- [Sylius Twig Hooks](https://docs.sylius.com/en/latest/the-book/frontend/twig-hooks.html)

---

## 2. Winzou State Machine

**Class:** `WinzouStateMachineAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- `winzou/state-machine-bundle` dependency in `composer.json`
- `winzou_state_machine:` configuration blocks in YAML files under `config/packages/`
- PHP code importing or referencing `SM\` namespace classes

Sylius 2.0 replaces winzou/state-machine-bundle with Symfony's native Workflow component.

### Estimation formula

**240 minutes per state machine definition.** Each definition requires converting states to places, transitions to Symfony transitions, and callbacks to event subscribers.

### Documentation

- [Symfony Workflow](https://symfony.com/doc/current/workflow.html)

---

## 3. SwiftMailer

**Class:** `SwiftMailerAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- `swiftmailer/swiftmailer` dependency in `composer.json`
- `swiftmailer:` configuration in YAML
- PHP usage of `Swift_Message`, `Swift_Attachment`, `Swift_Mailer`, `Swift_SmtpTransport`
- Email templates in `templates/emails/`

SwiftMailer is abandoned and replaced by Symfony Mailer.

### Estimation formula

**120 minutes per SwiftMailer usage** (dependency, config, each PHP usage, template batch).

### Documentation

- [Symfony Mailer](https://symfony.com/doc/current/mailer.html)

---

## 4. User Encoder

**Class:** `UserEncoderAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- `security.encoders:` configuration key in `security.yaml` (deprecated since Symfony 5.3, removed in 6.0)
- `getSalt()` method implementations in entity classes under `src/Entity/`

### Estimation formula

**60 minutes per affected User class.** Involves renaming the config key and removing or simplifying `getSalt()`.

### Documentation

- [Symfony Password Hashing](https://symfony.com/doc/current/security/passwords.html)

---

## 5. Payum

**Class:** `PayumAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- `payum/core` and `payum/payum-bundle` dependencies in `composer.json`
- Gateway definitions under `payum.gateways:` in YAML configuration
- PHP classes extending `GatewayFactory` or importing `Payum\` namespace

Sylius 2.0 introduces a new Payment Requests system that replaces Payum entirely.

### Estimation formula

- **120 minutes per standard gateway** (defined in YAML only)
- **480 minutes per custom gateway** (PHP class extending GatewayFactory)

### Documentation

- [Sylius Payment Requests](https://docs.sylius.com/migration-2.0/payment-requests)

---

## 6. Plugin Compatibility

**Class:** `PluginCompatibilityAnalyzer`
**Category:** Plugin
**Severity:** BREAKING / WARNING / SUGGESTION

### What it detects

Identifies all Sylius plugins in `composer.json` (packages with "sylius" in the vendor or package name, excluding core Sylius packages) and checks their compatibility with the target Sylius version using:

1. Sylius Addons Marketplace API
2. Packagist API (fallback)

### Estimation formula

| Status | Minutes |
|--------|---------|
| Incompatible | 960 (16h) |
| Abandoned | 480 (8h) |
| Partially compatible | 240 (4h) |
| Unknown | 240 (4h) |
| Compatible | 30 (0.5h) |

### Documentation

- [Sylius Plugin Guide](https://docs.sylius.com/en/latest/book/plugins/guide.html)

---

## 7. Grid Customization

**Class:** `GridCustomizationAnalyzer`
**Category:** Grid
**Severity:** WARNING

### What it detects

- Custom grid configurations in `sylius_grid:` YAML blocks
- PHP grid classes and custom filter/action types
- Grid configurations that reference deprecated field types or options

Sylius 2.x updates the grid system with new configuration options and deprecates several old patterns.

### Estimation formula

Based on the number of custom grid definitions and the complexity of custom filters/actions. Typically 60-120 minutes per customized grid.

### Documentation

- [Sylius Grid Bundle](https://docs.sylius.com/en/latest/components_and_bundles/bundles/SyliusGridBundle/)

---

## 8. Resource Bundle

**Class:** `ResourceBundleAnalyzer`
**Category:** Resource
**Severity:** WARNING

### What it detects

- `sylius_resource:` configuration blocks in YAML
- Custom resource definitions with deprecated options
- Classes extending deprecated resource base classes

SyliusResourceBundle has significant configuration changes in Sylius 2.x.

### Estimation formula

Based on the number of custom resource definitions. Typically 30-90 minutes per resource.

### Documentation

- [Sylius Resource Bundle](https://docs.sylius.com/en/latest/components_and_bundles/bundles/SyliusResourceBundle/)

---

## 9. Semantic UI

**Class:** `SemanticUiAnalyzer`
**Category:** Frontend
**Severity:** BREAKING

### What it detects

- `semantic-ui` or `fomantic-ui` dependencies in `package.json`
- CSS class names specific to Semantic UI in Twig templates (e.g., `ui button`, `ui segment`, `ui grid`)
- Semantic UI JavaScript initialization in asset files

Sylius 2.x drops Semantic UI in favor of a new frontend architecture.

### Estimation formula

Based on the number of templates and JS files using Semantic UI classes and components. Each detected file contributes to the total.

### Documentation

- [Sylius Frontend Architecture](https://docs.sylius.com/en/latest/the-book/frontend/)

---

## 10. jQuery

**Class:** `JQueryAnalyzer`
**Category:** Frontend
**Severity:** WARNING

### What it detects

- `jquery` dependency in `package.json`
- jQuery usage patterns in JavaScript files: `$()`, `jQuery()`, `$.ajax()`, `$(document).ready()`
- Semantic UI jQuery plugins

### Estimation formula

Based on the number of JavaScript files and the density of jQuery calls. Each file with jQuery usage is scored individually.

### Documentation

- [Sylius Frontend Architecture](https://docs.sylius.com/en/latest/the-book/frontend/)

---

## 11. Webpack Encore

**Class:** `WebpackEncoreAnalyzer`
**Category:** Frontend
**Severity:** WARNING

### What it detects

- `webpack.config.js` configuration
- Webpack Encore configuration patterns that need updating
- Asset entry points and loaders that may need migration

### Estimation formula

Based on the complexity of the Webpack configuration and the number of entry points.

### Documentation

- [Symfony Webpack Encore](https://symfony.com/doc/current/frontend/encore/installation.html)

---

## 12. API Platform Migration

**Class:** `ApiPlatformMigrationAnalyzer`
**Category:** API
**Severity:** BREAKING

### What it detects

- API Platform 2.x dependencies in `composer.json`
- Deprecated annotations (`@ApiResource`, `@ApiFilter`) that must be converted to PHP 8 attributes
- Configuration files using old API Platform 2.x format
- Custom data providers/persisters that need migration to state providers/processors

Sylius 2.x requires API Platform 3.x which has significant breaking changes.

### Estimation formula

Based on the number of API resources, custom providers/persisters, and deprecated annotations found.

### Documentation

- [API Platform Migration Guide](https://api-platform.com/docs/core/upgrade-guide/)

---

## 13. Message Bus Rename

**Class:** `MessageBusRenameAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- References to `sylius_default.bus` (renamed to `sylius.command_bus`)
- References to `sylius_event.bus` (renamed to `sylius.event_bus`)
- Both in YAML configuration files and PHP source code

### Estimation formula

Per-file basis, typically 15-30 minutes per affected file.

### Documentation

- [Sylius 2.0 Upgrade Guide](https://docs.sylius.com/en/latest/book/upgrading.html)

---

## 14. Command Handler Rename

**Class:** `CommandHandlerRenameAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Existence of `src/Message/` directory (should be renamed to `src/Command/`)
- PHP files with `App\Message\` namespace that need updating
- Handler classes following the old naming convention

### Estimation formula

Per-file basis. Includes namespace updates and file moves. Typically 15 minutes per file.

### Documentation

- [Sylius 2.0 Upgrade Guide](https://docs.sylius.com/en/latest/book/upgrading.html)

---

## 15. Deprecated Email Manager

**Class:** `DeprecatedEmailManagerAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Usage of `Sylius\Bundle\CoreBundle\Mailer\EmailManagerInterface` (deprecated)
- References to `sylius.email_manager.*` service IDs
- Direct usage of the old email sending API

### Estimation formula

Per-usage basis, typically 60 minutes per email manager reference.

### Documentation

- [Sylius Email Configuration](https://docs.sylius.com/en/latest/the-book/architecture/emails.html)

---

## 16. Removed Payment Gateway

**Class:** `RemovedPaymentGatewayAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- References to payment gateways that have been removed from Sylius core
- Configuration for offline, cash-on-delivery, or other gateways no longer bundled

### Estimation formula

**120-480 minutes** depending on whether a direct replacement exists or custom implementation is needed.

### Documentation

- [Sylius Payment Requests](https://docs.sylius.com/migration-2.0/payment-requests)

---

## 17. Service Decorator

**Class:** `ServiceDecoratorAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Service definitions using `decorates:` with deprecated Sylius service IDs
- PHP classes using `#[AsDecorator]` attribute with deprecated targets
- Service definitions referencing Sylius internal services that have been renamed or removed

### Estimation formula

Per-decorator basis, typically 60 minutes per affected service decorator.

### Documentation

- [Symfony Service Decoration](https://symfony.com/doc/current/service_container/service_decoration.html)

---

## 18. Order Processor Priority

**Class:** `OrderProcessorPriorityAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Order processor services with explicit priority tags
- Priority values that conflict with the new default ordering in Sylius 2.x

### Estimation formula

Per-processor basis, typically 30-60 minutes per affected order processor.

### Documentation

- [Sylius Order Processing](https://docs.sylius.com/en/latest/the-book/orders/orders.html)

---

## 19. Removed Form Type Extension

**Class:** `RemovedFormTypeExtensionAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- Classes extending one of the five form type extensions removed from Sylius 2.0 core (`CountryTypeExtension`, `CustomerTypeExtension`, `LocaleTypeExtension`, `CatalogPromotionActionTypeExtension`, `CatalogPromotionScopeTypeExtension`), including aliased imports
- Service configuration referencing a removed extension service (`sylius.form.extension.type.country`, `.customer`, `.locale`): redefinition, decoration, alias or injection

### Estimation formula

60 minutes per inheriting class or removed service reference.

### Documentation

- [UPGRADE-2.0.md](https://github.com/Sylius/Sylius/blob/2.0/UPGRADE-2.0.md)

---

## 20. Behat Context Deprecation

**Class:** `BehatContextDeprecationAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Behat context classes extending deprecated Sylius contexts
- Step definitions using deprecated Sylius Behat steps
- Feature files referencing deprecated context configurations
- Files in `features/` directory using old Sylius Behat patterns

### Estimation formula

Per-context and per-feature file. Typically 30-60 minutes per affected Behat context.

### Documentation

- [Sylius BDD Guide](https://docs.sylius.com/en/latest/bdd/index.html)

---

## 21. Admin Menu Event

**Class:** `AdminMenuEventAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Event subscribers/listeners for `sylius.menu.admin.main` events
- PHP classes using `Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent`
- Deprecated menu builder service registrations

### Estimation formula

Per-subscriber basis, typically 60 minutes per affected menu customization.

### Documentation

- [Sylius Admin Customization](https://docs.sylius.com/en/latest/customization/menu.html)

---

## 22. Promotion Rule Checker

**Class:** `PromotionRuleCheckerAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Classes implementing deprecated promotion rule checker interfaces
- Custom rule checkers using the old API
- Configuration referencing deprecated rule checker service IDs

### Estimation formula

Per-checker basis, typically 120 minutes per custom promotion rule checker.

### Documentation

- [Sylius Promotions](https://docs.sylius.com/en/latest/the-book/promotions/promotions.html)

---

## 23. Shipping Calculator

**Class:** `ShippingCalculatorAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Classes implementing deprecated shipping calculator interfaces
- Custom shipping calculators using the old API
- Configuration referencing deprecated calculator service IDs

### Estimation formula

Per-calculator basis, typically 120 minutes per custom shipping calculator.

### Documentation

- [Sylius Shipping](https://docs.sylius.com/en/latest/the-book/shipping/shipping.html)

---

## 24. Doctrine XML Mapping

**Class:** `DoctrineXmlMappingAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Doctrine XML mapping files (`.orm.xml`) in `src/*/Resources/config/doctrine/`
- Mapping overrides for Sylius entities
- Deprecated mapping configurations that need updating for Doctrine ORM changes in Sylius 2.x

### Estimation formula

Per-mapping-file basis, typically 30-60 minutes per affected mapping file.

### Documentation

- [Doctrine ORM Mapping](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/xml-mapping.html)

---

## 25. Custom Fixture

**Class:** `CustomFixtureAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Custom fixture classes implementing deprecated `FixtureInterface`
- Fixture suite configurations using deprecated options
- YAML fixture definitions with deprecated syntax

### Estimation formula

Per-fixture basis, typically 60 minutes per custom fixture class.

### Documentation

- [Sylius Fixtures](https://docs.sylius.com/en/latest/components_and_bundles/bundles/SyliusFixturesBundle/)

---

## 26. Multi-Store Channel

**Class:** `MultiStoreChannelAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING / WARNING

### What it detects

- Calls to `ChannelRepositoryInterface::findOneByHostname()`, deprecated since Sylius 1.11 and removed in 2.0 (BREAKING), including nullsafe calls
- Custom channel and locale contexts, whose dependencies changed signature in 2.0 (WARNING)

### Estimation formula

240 minutes per detected call or custom context.

### Documentation

- [UPGRADE-2.0.md](https://github.com/Sylius/Sylius/blob/2.0/UPGRADE-2.0.md)

---

## 27. Bundle Configuration

**Class:** `BundleConfigurationAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- 7 bundles to remove from `config/bundles.php`: SyliusCalendarBundle, winzouStateMachineBundle, BazingaHateoasBundle, JMSSerializerBundle, FOSRestBundle, old ApiPlatformBundle namespace, SyliusLabsPolyfillBundle
- 6 bundles required to add: new ApiPlatformBundle, SyliusTwigHooksBundle, TwigComponentBundle, StimulusBundle, LiveComponentBundle, AutocompleteBundle

### Estimation: 30 minutes per bundle issue

---

## 28. Calendar to Clock

**Class:** `CalendarClockAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- `sylius/calendar` in composer.json
- Usage of `Sylius\Calendar\Provider\DateTimeProviderInterface` in PHP files
- Replace with `Symfony\Component\Clock\ClockInterface`

### Estimation: 60 minutes per usage

---

## 29. Security Firewall

**Class:** `SecurityFirewallAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- Firewall names `new_api_admin_user` (rename to `api_admin`) and `new_api_shop_user` (rename to `api_shop`)
- 8 deprecated `sylius.security.new_api_*` parameter references

### Estimation: 60 minutes per reference

---

## 30. User Model Field

**Class:** `UserModelFieldAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- Properties/methods: `locked`, `isLocked`, `expiresAt`, `credentialsExpireAt` and their getters/setters
- Implementation of `\Serializable` interface on User entities
- `getSalt()` method usage

### Estimation: 60 minutes per field/method

---

## 31. Removed Class

**Class:** `RemovedClassAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

150+ removed classes including:
- All `Templating\Helper\*` classes (CoreBundle, CurrencyBundle, MoneyBundle, etc.)
- `UiBundle\Registry\*`, `UiBundle\Renderer\*`, `UiBundle\ContextProvider\*`
- `UiBundle\Twig\*` extensions (TemplateEventExtension, SortByExtension, etc.)
- `AdminBundle\Controller\NotificationController`, `Dashboard\StatisticsController`
- `UserBundle\Security\UserLogin`, `UserPasswordHasher`
- `Component\Core\Dashboard\*` classes
- `ProductBundle\Controller\ProductSlugController`, `ProductAttributeController`

### Estimation: 60 minutes per removed class usage

---

## 32. Renamed Service ID

**Class:** `RenamedServiceIdAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

21 renamed or removed Sylius service IDs in YAML config files, including:
- `sylius.zone_matcher` → `sylius.matcher.zone`
- `sylius.province_naming_provider` → `sylius.provider.province_naming`
- `sylius.form_registry.payum_gateway_config` → `sylius.form_registry.payment_gateway_config`
- Removed: `sylius.security.password_hasher`, `sylius.controller.payum`, etc.

### Estimation: 30 minutes per service

---

## 33. Removed Route

**Class:** `RemovedRouteAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

43 removed routes referenced in PHP (`src/`) and Twig (`templates/`) files:
- All `sylius_admin_partial_*` routes
- All `sylius_admin_ajax_*` routes
- All `sylius_shop_partial_*` routes
- All `sylius_shop_ajax_*` routes

### Estimation: 30 minutes per route reference

---

## 34. Sonata Block Event

**Class:** `SonataBlockEventAnalyzer`
**Category:** Twig
**Severity:** BREAKING

### What it detects

- `sonata_block_render_event()` calls in Twig templates (replaced by `hook()`)
- `sylius_template_event()` calls in Twig templates
- `BlockEventListener` references in PHP and YAML config files

### Estimation: 60 minutes per usage

---

## 35. LiipImagine Config

**Class:** `LiipImagineConfigAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- Resolver named `"default"` (must change to `"sylius_image"`)
- Loader named `"default"` (must change to `"sylius_image"`)
- `resolve_cache_relative` filter references

### Estimation: 60 minutes per config change

---

## 36. API Serialization Group

**Class:** `ApiSerializationGroupAnalyzer`
**Category:** API
**Severity:** BREAKING

### What it detects

- Serialization groups in PHP attributes/annotations using `admin:` or `shop:` prefix without `sylius:` prefix
- Non-prefixed groups in YAML serialization config

### Estimation: 30 minutes per group reference

---

## 37. API Endpoint Restructure

**Class:** `ApiEndpointRestructureAnalyzer`
**Category:** API
**Severity:** BREAKING

### What it detects

8+ restructured or removed API endpoint paths:
- `/api/v2/admin/avatar-images/` → nested under administrators
- `/api/v2/shop/reset-password-requests` → `/api/v2/shop/reset-password`
- `/api/v2/shop/account-verification-requests` → `/api/v2/shop/verify-shop-user`
- Removed: gateway-configs, channel-price-history-configs, shop-billing-datas, zone-members, order-item-units

### Estimation: 60 minutes per endpoint reference

---

## 38. Constructor Signature

**Class:** `ConstructorSignatureAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

PHP classes extending 24 Sylius classes with changed constructor signatures:
- Twig extensions: CheckoutStepsExtension, PriceExtension, CurrencyExtension, etc.
- Controllers: ImpersonateUserController, ContactController, SecurityController
- Listeners: ShipmentShipListener, OrderCompleteListener
- Services: ZoneMatcher, ImageUploader, ProductVariantPriceCalculator, etc.

### Estimation: 120 minutes per class

---

## 39. Grid Filter Entity

**Class:** `GridFilterEntityAnalyzer`
**Category:** Grid
**Severity:** BREAKING

### What it detects

- Grid filter `type: entities` (must change to `type: entity`)
- Grid filter option `field:` singular (must change to `fields:` array)

### Estimation: 30 minutes per filter

---

## 40. Use Webpack Config

**Class:** `UseWebpackConfigAnalyzer`
**Category:** Frontend
**Severity:** BREAKING

### What it detects

- `use_webpack` key in `sylius_ui` YAML config
- `use_webpack` variable in Twig templates

### Estimation: 60 minutes per reference

---

## 41. PHP Node Version

**Class:** `PhpNodeVersionAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

- PHP version constraint allowing < 8.2
- Node.js engine constraint allowing < 20
- Symfony 5.4 dependencies without 6.4

### Estimation: 30 minutes per version issue

---

## 42. API Query Extension Signature

**Class:** `ApiQueryExtensionSignatureAnalyzer`
**Category:** API
**Severity:** BREAKING

### What it detects

- Classes implementing `QueryCollectionExtensionInterface` or `QueryItemExtensionInterface`
- Old method signature using `string $operationName` (must change to `Operation $operation`)

### Estimation: 60 minutes per extension class

---

## 43. Class Move

**Class:** `ClassMoveAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

14 classes moved between bundles:
- `ShopBundle\EmailManager\ContactEmailManager` → `CoreBundle\Mailer\ContactEmailManager`
- `AdminBundle\EmailManager\ShipmentEmailManager` → `CoreBundle\Mailer\ShipmentEmailManager`
- `CoreBundle\Theme\ChannelBasedThemeContext` → `ShopBundle\Theme\ChannelBasedThemeContext`
- `UiBundle\Storage\FilterStorageInterface` → `GridBundle\Storage\FilterStorageInterface`
- API Bundle command handler renames (VerifyCustomerAccount → VerifyShopUser, etc.)

### Estimation: 30 minutes per class move

---

## 44. Service Visibility

**Class:** `ServiceVisibilityAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

Direct container access to Sylius services (all now private by default):
- `$container->get('sylius.*')`
- `$this->get('sylius.*')`
- `$this->container->get('sylius.*')`

### Estimation: 60 minutes per direct access

---

## 45. Payment Request Env

**Class:** `PaymentRequestEnvAnalyzer`
**Category:** Deprecation
**Severity:** WARNING

### What it detects

- Missing `SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` env var
- Missing `SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN` env var
- Missing `payment_request` transport in messenger.yaml

### Estimation: 30 minutes

---

## 46. Deprecated Bundle Package

**Class:** `DeprecatedBundlePackageAnalyzer`
**Category:** Deprecation
**Severity:** BREAKING

### What it detects

7 removed/replaced packages in composer.json:
- `friendsofsymfony/rest-bundle` (removed, API Platform handles API)
- `jms/serializer-bundle` (removed, use Symfony Serializer)
- `willdurand/hateoas-bundle` / `bazinga/hateoas-bundle` (removed)
- `sylius/calendar` (replaced by symfony/clock)
- `sylius-labs/polyfill-symfony-security` (no longer needed)
- `stripe/stripe-php` (Stripe gateway removed from core)

### Estimation: 60 minutes per package

---

## 47. PHP Security Patch

**Class:** `PhpSecurityPatchAnalyzer`
**Category:** Security
**Severity:** BREAKING / SUGGESTION

### What it detects

The PHP version declared by the project, compared with the first patched release of each branch (October 2026 security release: CVE-2026-109618 and CVE-2026-109619, actively exploited remote code execution, fixed in 8.2.35, 8.3.36, 8.4.27 and 8.5.12):

- `FROM php:8.3.10-fpm` in Dockerfiles, including versions passed through `ARG` and `FROM --platform=...`
- PHP images in compose files, including FrankenPHP tags such as `dunglas/frankenphp:1.4-php8.3`
- `config.platform.php` in `composer.json`, `.php-version`, `.tool-versions`

Only a Docker image pinned to a patch release (`php:8.3.10-fpm`) describes the PHP that actually runs: below the fix, or on a branch without security support (8.1 and older), it is BREAKING. Every other declaration is a SUGGESTION to verify the deployed version with `php -v`, never a blocking alert: `config.platform.php` often sets a minimum for dependency resolution, `.php-version` and `.tool-versions` describe the developer machine, a floating tag (`php:8.3-fpm`) may already be patched, and distribution packages receive fixes without changing their version number.

Each issue cites both CVE identifiers with the official advisories of the PHP team ([GHSA-w8hw-w9fx-j79w](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w), [GHSA-6wmf-4rv5-p3rp](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp)) and their listing in the CISA Known Exploited Vulnerabilities catalog (26/10/2026).

### Estimation: 60 minutes per vulnerable runtime, 15 minutes per runtime to verify
