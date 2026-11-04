# Results on Public Projects

To check that findings match reality, the analyzer is run on public Sylius 1.x code bases. Every blocking issue below was reviewed by hand against the official [UPGRADE-2.0.md](https://github.com/Sylius/Sylius/blob/2.0/UPGRADE-2.0.md).

Run on November 4, 2026 with Sylius Upgrade Analyzer 2.4.0, target Sylius 2.2, marketplace checks enabled.

| Project | Commit analyzed | Sylius | Issues | Blocking | Estimated effort | Duration |
|---------|-----------------|--------|--------|----------|------------------|----------|
| [Sylius-Standard](https://github.com/Sylius/Sylius-Standard), branch `1.14` | `c67a639` (2025-10-15) | ~1.14.0 | 64 | 25 | 51.5 h | 5 s |
| [Sylius/InvoicingPlugin](https://github.com/Sylius/InvoicingPlugin), branch `1.0` | `3a77c15` (2026-02-03) | ~1.13.0 \|\| ~1.14.0 | 30 | 6 | 38.5 h | 4 s |
| [BitBag SyliusWishlistPlugin](https://github.com/BitBagCommerce/SyliusWishlistPlugin), default branch | `9846209` (2025-05-22) | ^1.13 \|\| ^1.14 | 17 | 2 | 18.5 h | 5 s |

These repositories do not commit a `composer.lock`, so the Sylius version shown is the constraint declared in `composer.json`.

## What is found

### Sylius-Standard 1.14 (official skeleton)

Even an untouched skeleton needs real work, because it ships configuration and templates that change in 2.0:

- **Bundles to remove from `config/bundles.php`**: `winzouStateMachineBundle`, `SyliusCalendarBundle`, `JMSSerializerBundle`, `BazingaHateoasBundle` and other removed or replaced bundles.
- **Payum routes** imported in `config/routes/sylius_shop.yaml`, replaced by Payment Requests.
- **Renamed API firewalls** `new_api_admin_user` and `new_api_shop_user` in `security.yaml`.
- **Template overrides** shipped for the payment plugins (14), which must move to Twig Hooks, including a `sylius_template_event()` call.
- **PHP**: the `^8.1` constraint is below the PHP 8.2 required by Sylius 2, and the provided `Dockerfile` uses the unsupported `php:8.1` branch.
- **Frontend**: Webpack Encore and Semantic UI classes, replaced by Symfony UX and Bootstrap.

### Sylius/InvoicingPlugin 1.0

- Three `sylius_template_event()` calls in admin and shop templates, to migrate to Twig Hooks.
- A `^8.1` PHP constraint below the Sylius 2 requirement.

### BitBag SyliusWishlistPlugin

- A `^8.1` PHP constraint below the Sylius 2 requirement; the rest is warnings (frontend, deprecations).

## False positives found and fixed by these runs

Running real code bases is how the analyzer is kept honest. These runs revealed and fixed:

- **Removed configuration keys**: the check matched only the last segment of a key, so any `class:` or `templates` line was reported as a removed `sylius.*.repository.class` or `sylius.mailer.templates` parameter (11 false blocking issues on Sylius-Standard). Keys are now read from the parsed YAML at their exact path.
- **Development tools reported as incompatible plugins**: `sylius-labs/coding-standard` and `sylius-labs/suite-tags-extension` do not depend on Sylius, so the upgrade does not constrain them; they were estimated at 16 hours each.

Together, these fixes removed 37.5 hours of overestimated effort on Sylius-Standard.

## Reproduce

```bash
git clone --depth 1 --branch 1.14 https://github.com/Sylius/Sylius-Standard.git
docker run --rm -v "$PWD/Sylius-Standard:/project" ghcr.io/pierrearthurdemengel/sylius-upgrade-analyzer \
  sylius-upgrade:analyze /project --format=json --output=/project/report.json
```
