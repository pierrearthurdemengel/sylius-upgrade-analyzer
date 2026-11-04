<?php

declare(strict_types=1);

/*
 * Regenere docs/fixer-examples.md a partir de tests/Fixtures/fixer-examples.php.
 * FixerExamplesTest echoue si le document n'est plus a jour.
 *
 *     php .github/scripts/generate-fixer-examples.php
 */

use PierreArthur\SyliusUpgradeAnalyzer\Tests\Support\FixerExamples;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

file_put_contents($root . '/docs/fixer-examples.md', FixerExamples::render(FixerExamples::load()));
echo "docs/fixer-examples.md regenere\n";
