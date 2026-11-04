# Sylius Upgrade Analyzer

[![Packagist Version](https://img.shields.io/packagist/v/pierre-arthur/sylius-upgrade-analyzer)](https://packagist.org/packages/pierre-arthur/sylius-upgrade-analyzer)
[![PHP Version](https://img.shields.io/packagist/php-v/pierre-arthur/sylius-upgrade-analyzer)](https://packagist.org/packages/pierre-arthur/sylius-upgrade-analyzer)
[![Symfony Version](https://img.shields.io/badge/symfony-6.4%20%7C%207.2-blue)](https://symfony.com)
[![CI](https://github.com/pierrearthurdemengel/sylius-upgrade-analyzer/actions/workflows/ci.yaml/badge.svg)](https://github.com/pierrearthurdemengel/sylius-upgrade-analyzer/actions/workflows/ci.yaml)
[![License](https://img.shields.io/packagist/l/pierre-arthur/sylius-upgrade-analyzer)](LICENSE)

**Automated migration audit & auto-fix CLI for Sylius 1.x to 2.x projects.**

Sylius Upgrade Analyzer scans your existing Sylius 1.x project, detects **every** breaking change, deprecated API, and incompatible pattern, then produces a detailed migration report with time estimates, fix suggestions, and **41 automatic corrections** covering the most common migration tasks.

Coverage is built from the official [UPGRADE-2.0.md](https://github.com/Sylius/Sylius/blob/2.0/UPGRADE-2.0.md), [UPGRADE-API-2.0.md](https://github.com/Sylius/Sylius/blob/2.1/UPGRADE-API-2.0.md), and [CHANGELOG-2.0.md](https://github.com/Sylius/Sylius/blob/2.1/CHANGELOG-2.0.md). Nothing is left unchecked.

---

## Features

### 48 Built-in Analyzers

#### Templates & Frontend (6)

| # | Analyzer | What it detects |
|---|----------|-----------------|
| 1 | Twig Template Override | Overridden Sylius templates to migrate to Twig Hooks |
| 2 | Sonata Block Event | `sonata_block_render_event()` / `sylius_template_event()` to replace with `hook()` |
| 3 | Semantic UI | Semantic UI CSS classes in templates (removed in 2.x) |
| 4 | jQuery | jQuery / Semantic UI JS usage in assets |
| 5 | Webpack Encore | `webpack.config.js` + `@symfony/webpack-encore` detection |
| 6 | Use Webpack Config | Removed `use_webpack` from `sylius_ui` config |

#### Deprecations & Breaking Changes (34)

| # | Analyzer | What it detects |
|---|----------|-----------------|
| 7 | Winzou State Machine | winzou/state-machine-bundle to Symfony Workflow |
| 8 | SwiftMailer | swiftmailer/swiftmailer to symfony/mailer |
| 9 | User Encoder | `security.encoders` and `getSalt()` methods |
| 10 | Payum | Payum gateway to Payment Requests |
| 11 | Message Bus Rename | `sylius_default.bus` / `sylius_event.bus` renames |
| 12 | Command Handler Rename | `src/Message/` to `src/Command/` |
| 13 | Deprecated Email Manager | Removed OrderEmailManager / ContactEmailManager |
| 14 | Removed Payment Gateway | Stripe / PayPal Express removed from core |
| 15 | Service Decorator | Decorators targeting renamed Sylius services |
| 16 | Order Processor Priority | Priority conflicts (40-60 range) |
| 17 | Removed Form Type Extension | Classes and services built on form extensions removed from Sylius core |
| 18 | Behat Context Deprecation | 10+ deprecated Behat contexts |
| 19 | Admin Menu Event | Event-based admin menu system changes |
| 20 | Promotion Rule Checker | PromotionRuleCheckerInterface changes |
| 21 | Shipping Calculator | CalculatorInterface changes |
| 22 | Doctrine XML Mapping | `*.orm.xml` to PHP attribute migration |
| 23 | Custom Fixture | Fixture system changes |
| 24 | Multi-Store Channel | Removed `findOneByHostname()`, custom channel and locale contexts |
| 25 | Bundle Configuration | 7 removed bundles + 6 required new bundles in `bundles.php` |
| 26 | Calendar to Clock | `sylius/calendar` to `symfony/clock` (`ClockInterface`) |
| 27 | Security Firewall | `new_api_admin_user` / `new_api_shop_user` firewall renames |
| 28 | User Model Field | Removed `locked`, `expiresAt`, `credentialsExpireAt`, `\Serializable` |
| 29 | Removed Class | 150+ removed classes (Templating Helpers, UiBundle, DataCollectors, etc.) |
| 30 | Renamed Service ID | 21 renamed/removed Sylius service IDs |
| 31 | Removed Route | 43 removed admin/shop routes (partials, AJAX, etc.) |
| 32 | LiipImagine Config | Resolver/loader `"default"` to `"sylius_image"` |
| 33 | Constructor Signature | 24 classes with changed constructor signatures |
| 34 | PHP Node Version | PHP 8.2+, Node.js 20+, Symfony 5.4 detection |
| 35 | Class Move | 14 classes moved between bundles |
| 36 | Service Visibility | Direct `$container->get('sylius.*')` calls (now private) |
| 37 | Payment Request Env | Missing Messenger transport env vars for payments |
| 38 | Deprecated Bundle Package | 7 removed packages (FOSRest, JMSSerializer, BazingaHateoas, etc.) |
| 39 | Removed Config Key | Removed YAML config keys (`sylius_core.*`, `sylius_api.*`, MongoDB/PHPCR ODM) |
| 40 | Routing Import | Legacy routing imports (Payum shop routes, API route parameter renames) |

#### Plugins (1)

| # | Analyzer | What it detects |
|---|----------|-----------------|
| 41 | Plugin Compatibility | Sylius plugins cross-referenced with Addons Marketplace + Packagist |

#### Grid & Resource (3)

| # | Analyzer | What it detects |
|---|----------|-----------------|
| 42 | Grid Customization | Custom grid YAML + PHP grid classes + custom columns/filters |
| 43 | Resource Bundle | SyliusResourceBundle config, custom factories/repositories |
| 44 | Grid Filter Entity | `type: entities` to `type: entity`, `field:` to `fields:` syntax |

#### API Platform (4)

| # | Analyzer | What it detects |
|---|----------|-----------------|
| 45 | API Platform Migration | @ApiResource, DataProvider/DataPersister, ApiPlatform\Core namespace |
| 46 | API Serialization Group | Missing `sylius:` prefix on serialization groups |
| 47 | API Endpoint Restructure | 8+ restructured/removed API endpoint paths |
| 48 | API Query Extension Signature | `$operationName` to `Operation $operation` parameter change |

### 6 Output Reporters

- **Console** -- Rich terminal output with ASCII gauge, colored severity levels, category breakdown
- **JSON** -- Machine-readable structured report
- **CSV** -- Excel-compatible export (UTF-8 BOM, semicolon separator) via `--format=csv`
- **SARIF** -- Static Analysis Results Interchange Format (GitHub Code Scanning compatible)
- **Markdown** -- Human-readable report with tables, suitable for PRs and wikis
- **PDF** -- Stakeholder report generated locally: executive summary, risk factors, roadmap, budget estimate (`--tjm`) and detailed issues (requires `dompdf/dompdf`)

### 41 Auto-Fixers

| Fixer | Confidence | What it fixes |
|-------|:----------:|---------------|
| Twig Hook Fixer | HIGH | Generates `sylius_twig_hooks` YAML config for template overrides |
| Workflow Migration Fixer | MEDIUM | Converts winzou state machine YAML to Symfony Workflow config |
| Security Config Fixer | HIGH | Replaces `security.encoders` with `security.password_hashers` and simplifies `getSalt()` |
| Message Bus Fixer | HIGH | Renames bus references in YAML and PHP files |
| Command Handler Fixer | HIGH | Updates namespaces from `Message\` to `Command\` |
| SwiftMailer Fixer | HIGH | Converts `Swift_Message` to `Email`, `Swift_Attachment` to `DataPart`, updates YAML config |
| Renamed Service ID Fixer | HIGH | Replaces 11 old Sylius service IDs + 2 prefix renames in YAML |
| Security Firewall Fixer | HIGH | Renames `new_api_admin_user` → `api_admin`, `new_api_shop_user` → `api_shop` |
| LiipImagine Config Fixer | HIGH | Replaces resolver/loader `"default"` with `"sylius_image"` |
| Sonata Block Event Fixer | MEDIUM | Replaces `sonata_block_render_event()` / `sylius_template_event()` with `hook()` |
| Grid Filter Entity Fixer | HIGH | Replaces `type: entities` with `type: entity` in grid YAML |
| Use Webpack Config Fixer | HIGH | Removes `use_webpack` from `sylius_ui` YAML config and Twig conditionals |
| Calendar to Clock Fixer | HIGH | Replaces `DateTimeProviderInterface` → `ClockInterface`, `sylius/calendar` → `symfony/clock` |
| Class Move Fixer | HIGH | Updates 9 moved FQCN references between Sylius bundles |
| Removed Class Fixer | MEDIUM | Comments out `use` statements for 150+ removed classes with TODO markers |
| Bundle Config Fixer | HIGH | Removes 7 deprecated bundles and adds 5 required new bundles in `bundles.php` |
| Deprecated Bundle Package Fixer | HIGH | Removes 6 deprecated packages from `composer.json` |
| User Encoder Fixer | HIGH | YAML: `encoders` → `password_hashers` + algorithm; PHP: `getSalt()` → `return null` |
| API Endpoint Restructure Fixer | HIGH | Replaces 3 restructured API endpoint paths |
| Removed Route Fixer | MEDIUM | Comments removed route references in PHP and Twig with TODO markers |
| Payment Request Env Fixer | HIGH | Adds 2 required Messenger transport env vars to `.env` |
| Routing Import Fixer | HIGH | Replaces 7 old routing imports and parameter names |
| Removed Config Key Fixer | HIGH | Comments 7 removed YAML config keys with TODO markers |
| Behat Context Fixer | MEDIUM | Renames deprecated Behat context classes |
| API Serialization Group Fixer | MEDIUM | Adds `sylius:` prefix to 4 serialization group pairs |
| API Query Extension Signature Fixer | MEDIUM | Updates `$operationName` → `Operation $operation` parameter + use statement |
| Order Processor Priority Fixer | MEDIUM | Adds `priority` in YAML tags + `getPriority()` in PHP |
| Deprecated Email Manager Fixer | MEDIUM | Replaces 4 deprecated email manager interface FQCNs |
| Admin Menu Event Fixer | MEDIUM | Replaces 3 old admin menu event names |
| Service Decorator Fixer | MEDIUM | Updates `decorates:` with renamed controller service prefixes |
| Removed Payment Gateway Fixer | MEDIUM | Comments `stripe_checkout` and `paypal_express_checkout` configs |
| Shipping Calculator Fixer | MEDIUM | Replaces `DelegatingCalculatorInterface` → `CalculatorInterface` |
| Promotion Rule Checker Fixer | MEDIUM | Moves `CartQuantityRuleChecker` from Promotion to Core namespace |
| Payum Config Fixer | MEDIUM | Comments `payum:` YAML root key and Payum `use` statements in PHP |
| Grid Customization Fixer | MEDIUM | Replaces `field:` → `fields:` + `doctrine/orm` → `doctrine_orm` |
| Resource Bundle Fixer | MEDIUM | Replaces `doctrine/orm` driver format, comments PHPCR/MongoDB drivers |
| API Platform Migration Fixer | MEDIUM | Updates 7 namespace replacements (Core → Metadata/Symfony) + annotation → attribute |
| Multi-Store Channel Fixer | HIGH | Replaces `findOneByHostname()` with `findOneEnabledByHostname()` |
| Constructor Signature Fixer | MEDIUM | Adds TODO markers on overridden constructors of 24 changed Sylius classes |
| User Model Field Fixer | MEDIUM | Comments deprecated `locked`, `expiresAt`, `credentialsExpireAt` fields and `Serializable` |
| Doctrine XML Mapping Fixer | LOW | Adds TODO marker for `*.orm.xml` to PHP attribute conversion |

### GitHub Action

Run the analyzer in your CI pipeline, post PR comments, and upload SARIF to GitHub Code Scanning.

### Additional Features

- **Custom rules** via `.sylius-upgrade-rules.yaml`
- **Baseline management** -- save and diff results across runs
- **Sprint planner** -- generate a migration roadmap with sprint breakdown
- **Plugin compatibility** -- checks Sylius Addons Marketplace and Packagist
- **Multi-project analysis** -- consolidated comparison table and budget for agencies managing several Sylius projects (console, JSON, Markdown or PDF)
- **Report comparison** -- diff two reports to track migration progress
- **Plugin compatibility matrix** -- check a list of plugins against the target version

---

## Installation

```bash
composer require --dev pierre-arthur/sylius-upgrade-analyzer
```

Requirements: PHP 8.2+, Symfony 6.4 or 7.2.

---

## Usage

### Basic Analysis

```bash
# Analyze the current directory
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze

# Analyze a specific project
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze /path/to/sylius-project

# Target a specific Sylius version
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --target-version=2.2
```

### Output Formats

```bash
# Console output (default)
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze

# JSON report
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=json --output=report.json

# SARIF report (for GitHub Code Scanning)
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=sarif --output=report.sarif

# Markdown report
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=markdown --output=report.md

# CSV export (Excel-compatible)
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=csv --output=report.csv

# PDF report, with a budget estimate based on a 600 EUR daily rate
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=pdf --output=report.pdf --tjm=600
```

PDF generation is fully local and relies on [dompdf](https://github.com/dompdf/dompdf), an optional dependency kept out of the default install to avoid version conflicts with your project:

```bash
composer require --dev dompdf/dompdf
```

The budget estimate converts the estimated hours into days on a 7-hour basis, rounded up to the next half day.

### Project Naming

```bash
# Explicit project name (included in meta.project_name)
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --project-name="My Shop"

# Auto-detected from composer.json "name" field or directory name
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze
```

### Filtering Analyzers

```bash
# Run only specific analyzers
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --only="Twig Template Override" --only="Payum"
```

### Verbose Output

```bash
# Show warnings
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze -v

# Show warnings and suggestions
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze -vv
```

### Offline Mode

```bash
# Skip marketplace compatibility checks
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --no-marketplace
```

### Custom Rules

```bash
# Use a custom rules file
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --rules=.sylius-upgrade-rules.yaml
```

### Baseline Management

```bash
# Save a baseline
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --save-baseline

# Compare with previous baseline
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --diff
```

### Sprint Planning

```bash
# Generate a sprint plan with team velocity of 40h/sprint
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --sprint-plan --velocity=40
```

### Multi-Project Analysis

Audit a whole portfolio of Sylius shops in one run and get a comparison table (complexity, risk, blocking issues, hours, budget) with totals:

```bash
# Comparison table in the terminal
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:multi-analyze \
  /path/to/project1 /path/to/project2 /path/to/project3 --tjm=600

# Consolidated PDF (one overview page, then one summary page per project)
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:multi-analyze \
  /path/to/project1 /path/to/project2 --format=pdf --output=portfolio.pdf --tjm=600

# Also available: --format=json and --format=markdown
```

Projects sharing the same Composer name (forks, per-country variants) are told apart by their directory name.

### Compare Reports

```bash
# Compare two JSON reports (e.g. before and after a migration sprint)
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=json --output=before.json
# ... fix some issues ...
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=json --output=after.json
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:compare before.json after.json
```

---

## Auto-Fix

The analyzer can automatically fix certain issues:

```bash
# Apply all available fixes
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --fix

# Preview fixes without modifying files
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --fix --dry-run
```

Each fix has a confidence level:

- **HIGH** -- Safe to apply automatically (e.g., renaming `security.encoders` to `security.password_hashers`)
- **MEDIUM** -- Likely correct but manual review recommended (e.g., workflow migration)
- **LOW** -- Uncertain, manual verification required

In `--dry-run` mode, the tool generates a unified diff patch showing what would change without writing any files.

---

## GitHub Action

Add the analyzer to your CI workflow:

```yaml
name: Sylius Migration Audit
on: [push, pull_request]

jobs:
  analyze:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: pierrearthurdemengel/sylius-upgrade-analyzer@v1
        with:
          project-path: '.'
          target-version: '2.2'
          fail-on-breaking: 'true'
          upload-sarif: 'true'
          post-pr-comment: 'true'
```

### Action Inputs

| Input | Default | Description |
|-------|---------|-------------|
| `project-path` | `.` | Path to the Sylius project |
| `target-version` | `2.2` | Target Sylius version |
| `fail-on-breaking` | `true` | Fail the job if breaking issues are found |
| `upload-sarif` | `false` | Upload SARIF report to GitHub Code Scanning |
| `post-pr-comment` | `false` | Post a summary comment on the PR |

### Action Outputs

| Output | Description |
|--------|-------------|
| `complexity` | Detected complexity level (trivial, moderate, complex, major) |
| `total-hours` | Estimated total hours |
| `breaking-count` | Number of breaking issues |

---

## Custom Rules

Create a `.sylius-upgrade-rules.yaml` file at the root of your project:

```yaml
rules:
  - name: legacy_payment_service
    type: php_class_usage
    pattern: 'App\\Service\\LegacyPaymentService'
    severity: breaking
    category: deprecation
    message: 'Legacy payment service must be replaced'
    suggestion: 'Migrate to the new PaymentProcessor service'
    estimated_minutes: 120

  - name: old_twig_filter
    type: twig_function
    pattern: 'sylius_price_format'
    severity: warning
    category: twig
    message: 'Deprecated Twig filter detected'
    suggestion: 'Use the new money_format filter instead'
    estimated_minutes: 15
```

Supported rule types: `php_class_usage`, `php_method_call`, `twig_function`, `yaml_key`.

See [docs/custom-rules.md](docs/custom-rules.md) for the full reference.

---

## Example Output

### Console

```
 Sylius Upgrade Analyzer - Migration Report
 ===========================================

 Projet        : /home/dev/my-sylius-shop
 Version       : 1.12.18
 Cible         : 2.2
 Date          : 04/04/2026 14:32:07

 Resume global
 -------------
   Problemes critiques (BREAKING) : 12
   Avertissements (WARNING) :        23
   Suggestions :                      8

   Temps total estime : 142.5 heures

   Complexite globale : COMPLEXE

   [████████████████████████████░░░░░░░░░░░░░] 142.5h / COMPLEX

 Estimation par categorie
 -------------------------
 +-----------------+--------------+-----------------+
 | Categorie       | Nb problemes | Heures estimees |
 +-----------------+--------------+-----------------+
 | Deprecations    | 18           | 64.0 h          |
 | Templates Twig  | 7            | 35.0 h          |
 | Front-end       | 6            | 24.0 h          |
 | Plugins         | 4            | 16.0 h          |
 | API             | 2            | 3.5 h           |
 +-----------------+--------------+-----------------+
```

### JSON

```json
{
  "summary": {
    "complexity": "complex",
    "total_hours": 142.5,
    "breaking_count": 12,
    "warning_count": 23,
    "suggestion_count": 8,
    "detected_version": "1.12.18",
    "target_version": "2.2"
  },
  "hours_by_category": {
    "deprecation": 64.0,
    "twig": 35.0,
    "frontend": 24.0,
    "plugin": 16.0,
    "api": 3.5
  },
  "issues": [
    {
      "severity": "breaking",
      "category": "deprecation",
      "analyzer": "Winzou State Machine",
      "message": "3 machine(s) a etats winzou detectee(s) necessitant une migration vers Symfony Workflow",
      "detail": "Chaque machine a etats doit etre convertie en definition Symfony Workflow.",
      "suggestion": "Migrer chaque definition winzou_state_machine vers framework.workflows.",
      "estimated_minutes": 720
    }
  ]
}
```

---

## Compatibility Matrix

Check the compatibility of Sylius plugins with the target version (Addons Marketplace + Packagist):

```bash
# Read the plugins from the composer.json of the current directory
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:matrix

# Check an explicit list, output as Markdown
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:matrix \
  bitbag/wishlist-plugin,sylius/invoicing-plugin --format=markdown
```

---

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on:

- Creating custom analyzers
- Creating custom fixers
- Coding standards and test requirements
- PR process

---

## Documentation

- [All Analyzers Reference](docs/analyzers.md)
- [Creating a Custom Analyzer](docs/custom-analyzer.md)
- [Creating a Custom Fixer](docs/custom-fixer.md)
- [Custom Rules Reference](docs/custom-rules.md)
- [Migration Guide: From Detection to Resolution](docs/migration-guide.md)

---

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

---

## Author

**Pierre-Arthur Demengel** -- [pierrearthur.demengel@gmail.com](mailto:pierrearthur.demengel@gmail.com)

- GitHub: [github.com/pierrearthurdemengel/sylius-upgrade-analyzer](https://github.com/pierrearthurdemengel/sylius-upgrade-analyzer)
