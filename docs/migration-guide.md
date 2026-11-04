# Migration Guide: From Detection to Resolution

This guide walks through the complete workflow of using Sylius Upgrade Analyzer to prepare and execute a migration from Sylius 1.x to 2.x.

---

## Overview

The migration process follows five phases:

1. **Audit** -- Run the analyzer and understand the scope
2. **Plan** -- Prioritize and schedule the work
3. **Fix** -- Apply automatic fixes and manually resolve remaining issues
4. **Verify** -- Re-run the analyzer to confirm progress
5. **Finalize** -- Clean up and validate the migrated project

---

## Phase 1: Audit

### Step 1.1: Install the analyzer

```bash
cd /path/to/your-sylius-project
composer require --dev pierre-arthur/sylius-upgrade-analyzer
```

### Step 1.2: Run the initial analysis

```bash
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze . --target-version=2.2
```

This produces a console report showing:

- Detected Sylius version
- Total estimated migration effort in hours
- Complexity rating (TRIVIAL / MODERATE / COMPLEX / MAJOR)
- Breakdown by category (Twig, Deprecation, Plugin, Frontend, API, etc.)
- List of all breaking issues

### Step 1.3: Generate a full report

For sharing with the team or stakeholders:

```bash
# JSON for tooling and CI
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=json --output=migration-audit.json

# Markdown for documentation
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=markdown --output=migration-audit.md

# SARIF for GitHub Code Scanning
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --format=sarif --output=migration-audit.sarif
```

### Step 1.4: Save the baseline

Save the current state as a reference point to track progress:

```bash
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --save-baseline
```

### Step 1.5: Review the detailed output

Use verbose mode to see all warnings and suggestions:

```bash
# Show warnings
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze -v

# Show everything including suggestions
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze -vv
```

---

## Phase 2: Plan

### Step 2.1: Generate a sprint plan

If your team works in sprints, generate a migration roadmap:

```bash
# With a team velocity of 40 hours per sprint
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --sprint-plan --velocity=40
```

This produces a sprint-by-sprint breakdown showing:

- Which issues to tackle in each sprint
- Estimated hours per sprint
- Dependencies between tasks

### Step 2.2: Prioritize by impact

The recommended order of migration is:

1. **Breaking changes first** -- These will prevent the project from running on Sylius 2.x
2. **High-effort items early** -- state machine conversion, removed payment gateways, plugin replacements
3. **Frontend last** -- Semantic UI / jQuery migration can be done incrementally
4. **Suggestions optional** -- Address as time permits

### Step 2.3: Understand the categories

| Category | Typical effort | Can be parallelized? |
|----------|---------------|---------------------|
| Deprecation (PHP) | High | Yes, per analyzer |
| Twig templates | Medium | Yes, per template |
| Plugin compatibility | Variable | Depends on plugin |
| Frontend | High | Partially |
| API | Medium | Yes |
| Grid / Resource | Low | Yes |

---

## Phase 3: Fix

### Step 3.1: Apply automatic fixes

Start with the safe automatic fixes:

```bash
# Preview what will change
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --fix --dry-run

# Apply the fixes
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --fix
```

The auto-fixers handle:

- **Security config** -- Renames `security.encoders` to `security.password_hashers` and simplifies `getSalt()` methods
- **Message bus** -- Renames `sylius_default.bus` to `sylius.command_bus` and `sylius_event.bus` to `sylius.event_bus`
- **Command handler namespaces** -- Updates `App\Message\` namespaces to `App\Command\`
- **Twig hooks** -- Generates `sylius_twig_hooks.yaml` configuration for template overrides
- **Workflow config** -- Converts winzou state machine YAML to Symfony Workflow format

Review the changes after applying fixes:

```bash
git diff
```

### Step 3.2: Migrate state machines manually

The auto-fixer generates the basic Symfony Workflow structure, but callbacks require manual migration:

1. For each winzou callback, create an event subscriber
2. Map `before` callbacks to `workflow.<name>.transition.*` events
3. Map `after` callbacks to `workflow.<name>.completed.*` events
4. Map `guard` callbacks to `workflow.<name>.guard.*` events

### Step 3.3: Review payment gateways

Sylius 2.0 still supports Payum through `SyliusPayumBundle`, so existing gateways keep working. What must change:

1. Replace the Stripe Checkout and PayPal Express gateways, removed from the core, with their payment plugins
2. Import `@SyliusPayumBundle/Resources/config/app/config.yaml` and move the `gateway_config` resource from `sylius_payum` to `sylius_payment`, as shown in `UPGRADE-2.0.md`
3. Optionally, convert Payum gateways to Payment Request handlers (the analyzer lists them as suggestions)
4. Test payment flows thoroughly

### Step 3.4: Migrate templates to Twig hooks

For each overridden template:

1. The auto-fixer has generated the hook configuration in `config/packages/sylius_twig_hooks.yaml`
2. Move the template content to the new hook template location
3. Adapt the template to use hook-specific variables and conventions
4. Remove the old template override
5. Test the page visually

### Step 3.5: Update plugins

For each incompatible plugin:

1. Check if a Sylius 2.x compatible version exists
2. If yes, update the version constraint in `composer.json`
3. If no compatible version exists, consider:
   - Contacting the plugin maintainer
   - Finding an alternative plugin
   - Re-implementing the functionality in your project

### Step 3.6: Migrate SwiftMailer to Symfony Mailer

1. Install Symfony Mailer: `composer require symfony/mailer`
2. Replace `swiftmailer:` config with `framework.mailer:` and a `MAILER_DSN`
3. Replace `Swift_Message` with `Symfony\Component\Mime\Email`
4. Replace `Swift_Mailer` injection with `Symfony\Component\Mailer\MailerInterface`
5. Remove SwiftMailer: `composer remove swiftmailer/swiftmailer`

### Step 3.7: Migrate frontend

1. Remove Semantic UI dependencies
2. Replace jQuery patterns with vanilla JavaScript or the new Sylius frontend toolkit
3. Update Webpack Encore configuration if needed
4. Test all interactive features (cart, checkout, admin panels)

---

## Phase 4: Verify

### Step 4.1: Re-run the analyzer

After making changes, re-run the analyzer to check progress:

```bash
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze
```

### Step 4.2: Compare with baseline

See what has been resolved since the initial audit:

```bash
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --diff
```

This shows:

- Issues that have been resolved
- New issues introduced (if any)
- Remaining issues

### Step 4.3: Iterate

Repeat Phase 3 and Phase 4 until no breaking issues remain.

### Step 4.4: Run with strict filtering

Verify specific areas are clean:

```bash
# Check only Payum issues
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --only="Payum"

# Check only state machine issues
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --only="Winzou State Machine"

# Check only frontend
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --only="Semantic UI" --only="jQuery" --only="Webpack Encore"
```

---

## Phase 5: Finalize

### Step 5.1: Update Sylius version

Once all breaking issues are resolved:

```bash
composer require sylius/sylius:^2.2
```

### Step 5.2: Run the Sylius upgrade command

```bash
bin/console sylius:install
bin/console doctrine:migrations:migrate
```

### Step 5.3: Run the full test suite

```bash
# PHPUnit
vendor/bin/phpunit

# Behat (if applicable)
vendor/bin/behat
```

### Step 5.4: Final analyzer check

Run the analyzer one last time on the upgraded project:

```bash
vendor/bin/sylius-upgrade-analyzer sylius-upgrade:analyze --target-version=2.2
```

The expected result: zero breaking issues and only minor suggestions.

### Step 5.5: Remove the analyzer

Once the migration is complete:

```bash
composer remove --dev pierre-arthur/sylius-upgrade-analyzer
```

---

## CI Integration

For ongoing migration tracking, integrate the analyzer in your CI pipeline:

```yaml
# .github/workflows/migration-audit.yaml
name: Migration Audit
on: [push]

jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pierrearthurdemengel/sylius-upgrade-analyzer@v1
        with:
          target-version: '2.2'
          fail-on-breaking: 'true'
          post-pr-comment: 'true'
```

This ensures no new breaking patterns are introduced while the migration is in progress.

---

## Tips

- **Start early.** Run the analyzer before beginning any migration work to establish a baseline.
- **Migrate incrementally.** Tackle one category at a time. Commit and test after each category.
- **Keep the analyzer in CI.** It prevents regression while the migration is ongoing.
- **Use `--dry-run` first.** Always preview auto-fixes before applying them.
- **Read the linked documentation.** Each issue includes a `docUrl` pointing to the relevant Sylius or Symfony documentation.
- **Track velocity.** Use `--diff` regularly to measure progress against the baseline.
