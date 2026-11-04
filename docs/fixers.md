# Auto-Fixers: What Each One Changes

The analyzer ships 37 auto-fixers. Each fix it proposes carries a confidence level, read from the code of the fixer. Real before/after output of every fixer is in [fixer-examples.md](fixer-examples.md), replayed by the test suite on every run.

| Level | Meaning | What to do |
|-------|---------|------------|
| `high` | Rename or removal documented one-to-one in the official Sylius upgrade guides | Safe to apply; the diff is still shown |
| `medium` | Correct, but depends on how your project uses the code | Review the diff before committing |
| `low` | Starting point only | Rewrite by hand from the result |

## How fixes are applied

```bash
# See the patch, write nothing
sylius-upgrade-analyzer sylius-upgrade:analyze --dry-run

# In a terminal: shows the full patch, then asks before writing anything
sylius-upgrade-analyzer sylius-upgrade:analyze --fix

# Only the safe fixes
sylius-upgrade-analyzer sylius-upgrade:analyze --fix --min-confidence=high
```

- In a terminal, `--fix` always prints the unified diff and asks for confirmation; the default answer is no.
- In CI (no terminal, or `--no-interaction`), `--fix` applies directly, as scripts expect. Combine it with `--min-confidence=high` there.
- Every write goes through a single method, `FixEngine::applyFix()`, which only rewrites project files.

## What fixers never do

- Connect to a database, create or run a Doctrine migration.
- Run a command, install or remove a Composer package (they edit `composer.json`; you run `composer update` yourself).
- Edit PHP payment code. The two payment-related fixers only comment out YAML gateway definitions (`Removed Payment Gateway`) or append missing variables to `.env` files (`Payment Request Env`).
- Delete code that has no documented replacement: removed classes, routes, configuration keys, user fields and gateways are **commented out** with a `TODO`, never deleted.
- Produce a broken file on purpose: fixers that edit JSON or merge YAML check that the result still parses and propose nothing otherwise.

Run fixes on a clean Git working tree: `git diff` shows every change and `git checkout .` undoes them.

## High confidence (safe to apply)

| Fixer | Analyzer | What it changes |
|-------|----------|-----------------|
| API Endpoint Restructure | API Endpoint Restructure | Replaces API paths renamed in `UPGRADE-API-2.0.md` (avatar images, reset password, account verification) |
| Bundle Configuration | Bundle Configuration | Removes bundles dropped by Sylius 2.0 from `config/bundles.php` and adds the new ones (Twig Hooks, Symfony UX) |
| Calendar to Clock | Calendar to Clock | `Sylius\Calendar` `DateTimeProviderInterface` → `Symfony\Component\Clock\ClockInterface` |
| Class Move | Class Move | Updates the FQCN of classes moved between bundles |
| Command Handler Rename | Command Handler Rename | `Sylius\Bundle\{Admin,Core}Bundle\Message*` → `Command*` namespaces (the 18 classes keep their short name; your own code is not renamed) |
| Deprecated Bundle Package | Deprecated Bundle Package | Removes packages dropped by Sylius 2.0 from `composer.json`, keeping the JSON valid |
| Grid Filter Entity | Grid Filter Entity | Grid filter type `entities` → `entity` |
| Liip Imagine Config | Liip Imagine Config | Resolver and loader named `default` → `sylius_image`, the names Sylius 2.0 uses |
| Message Bus | Message Bus Rename | `sylius_default.bus` → `sylius.command_bus`, `sylius_event.bus` → `sylius.event_bus` |
| Multi-Store Channel | Multi-Store Channel | `findOneByHostname()` (deprecated since 1.11, removed from the interface) → `findOneEnabledByHostname()` |
| Order Processor Autoconfiguration | Order Processor Autoconfiguration | Adds `#[AsOrderProcessor]` or `#[AsCartContext]` to classes Sylius 2.0 no longer registers through their interface (priority 0, as in 1.x) |
| Payment Request Env | Payment Request Env | Appends the Payment Request transport variables to `.env` files, with the `sync://` values of `UPGRADE-2.0.md` |
| Removed Config Key | Removed Config Key | Comments out configuration keys removed in Sylius 2.0, with a `TODO` |
| Renamed Service ID | Renamed Service ID | Renames service IDs listed word for word in the `UPGRADE-2.0.md` tables |
| Routing Import | Routing Import | Updates routing imports moved to `SyliusPayumBundle` and the renamed `sylius.security.new_api_*` parameters |
| Security Config | User Encoder | Generates the equivalent `password_hashers` configuration |
| Security Firewall | Security Firewall | Renames `new_api_*` firewalls and parameters to their Sylius 2.0 names |
| Shipping Calculator | Shipping Calculator | Renames references (`@id`, `decorates`) to `sylius.shipping_calculator*` services; the tag of the same name is unchanged and left alone |
| Use Webpack Config | Use Webpack Config | Removes the `use_webpack` option and Twig conditions on it |
| User Encoder (YAML) | User Encoder | `encoders:` → `password_hashers:` in `security.yaml` |

## Medium confidence (review the diff)

| Fixer | Analyzer | What it changes | Why it needs a review |
|-------|----------|-----------------|-----------------------|
| API Platform Migration | API Platform Migration | `ApiPlatform\Core` → API Platform 3 namespaces | Some classes changed behaviour, not just namespace |
| API Query Extension Signature | API Query Extension Signature | `string $operationName` → `?Operation $operation = null` | The method body may still use the old string |
| API Serialization Group | API Serialization Group | Adds the `sylius:` prefix to `admin:` and `shop:` groups | Your own groups are prefixed too: apply it to every flagged file together |
| Constructor Signature | Constructor Signature | Marks overridden constructors whose parent signature changed | The new arguments must be wired by hand |
| Deprecated Email Manager | Deprecated Email Manager | Updates references to `OrderEmailManager` and `ContactEmailManager` moved to CoreBundle | Custom email managers may override other methods |
| Promotion Rule Checker | Promotion Rule Checker | Updates promotion rule checker classes moved to `Sylius\Component\Core` | Business rules may rely on removed behaviour |
| Removed Class | Removed Class | Comments out `use` statements of removed classes | The code using them has no direct replacement |
| Removed Payment Gateway | Removed Payment Gateway | Comments out the whole Stripe Checkout or PayPal Express definition in YAML | A payment plugin must be installed instead |
| Removed Route | Removed Route | Comments out references to removed routes | Links and redirects must point elsewhere |
| Resource Bundle | Resource Bundle | Comments out the PHPCR and MongoDB drivers removed in Sylius 2.0 (`doctrine/orm` is untouched) | The resource needs another storage |
| Service Decorator | Service Decorator | Renames `decorates:` targets listed in the `UPGRADE-2.0.md` tables | The decorated service's constructor may have changed |
| Sonata Block Event | Sonata Block Event | `sonata_block_render_event()` and `sylius_template_event()` → `hook()` | Hook names must match the Sylius 2 templates |
| SwiftMailer (YAML) | SwiftMailer | Replaces the `swiftmailer:` block with `framework.mailer.dsn` and keeps the old block as a comment | `MAILER_DSN` must be defined and options such as spooling carried over |
| Twig Hook | Twig Template Override | Registers a Twig Hook in place of a template override, with a valid `sylius_twig_hooks` structure | The hook template (`templates/hooks/...`) must be created from the override, or the page fails to render |
| User Encoder (PHP) | User Encoder | Simplifies `getSalt()` methods | Only safe if no custom salt is used |
| User Model Field | User Model Field | Comments out `locked`, `expiresAt`, `credentialsExpireAt` and `Serializable` | Data in these fields must be migrated or dropped |
| Winzou State Machine | Winzou State Machine | Generates the Symfony Workflow configuration (states, transitions) from winzou YAML | Guards and callbacks must be ported by hand |

## Low confidence (starting point only)

| Fixer | Analyzer | What it changes | Why |
|-------|----------|-----------------|-----|
| Doctrine XML Mapping | Doctrine XML Mapping | Adds a `TODO` marker to each XML mapping, which is kept as is | Mapping conversion to attributes is done by hand |
| SwiftMailer (PHP) | SwiftMailer | Replaces SwiftMailer class names with fully qualified Symfony Mailer ones | The APIs differ (`setSubject()` becomes `subject()`, `Mailer` and `Transport` are not built with `new`): rewrite the calls |

## Fixers removed after verification

Each fixer was replayed on real input and checked against the Sylius 1.14 and 2.0 sources. These were removed because their change was wrong:

- **Payum**: commented out the whole `payum:` configuration, but Sylius 2.0 still ships Payum (`payum/payum-bundle` is required) and existing gateways keep working.
- **Admin Menu Event**: renamed `sylius.menu.admin.main`, whose name is unchanged in Sylius 2.0, so the listener would have stopped firing.
- **Behat Context**: renamed the `calendar` contexts to `clock` contexts that do not exist; the `calendar` contexts are still provided.
- **Grid Customization**: rewrote `driver: doctrine/orm`, still the correct value, and renamed every `field:` key without a documented reason.
