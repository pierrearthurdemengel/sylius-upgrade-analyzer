# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 2.x     | Yes       |
| 1.x     | No        |

Security fixes are released for the latest 2.x minor version.

## Reporting a Vulnerability

Please do not open a public issue for a security problem.

Report it privately, either through [GitHub private vulnerability reporting](https://github.com/pierrearthurdemengel/sylius-upgrade-analyzer/security/advisories/new) or by e-mail to pierrearthur.demengel@gmail.com, with a description, the affected version and, if possible, steps to reproduce.

You will receive an acknowledgement within 3 working days. Once confirmed, a fix is released as soon as possible and credited in the changelog unless you prefer to stay anonymous.

## Scope

The analyzer reads the audited project's files locally and never uploads them. Network access is limited to optional plugin compatibility checks against the Sylius Addons Marketplace and Packagist, which can be disabled with `--no-marketplace`.

Auto-fixes only rewrite files of the audited project: they never connect to a database or execute commands.

Dependencies are audited with `composer audit` in every CI run, and the Docker image is built on a pinned, patched PHP release.
