<?php

declare(strict_types=1);

/*
 * Fait échouer la CI si la couverture de lignes du rapport Clover est sous le seuil.
 * PHPUnit ne propose pas de seuil bloquant natif, d'où ce contrôle séparé.
 *
 * Usage : php check-coverage.php <clover.xml> <seuil en %>
 */

if ($argc !== 3 || !is_file($argv[1]) || !is_numeric($argv[2])) {
    fwrite(STDERR, "Usage : php check-coverage.php <clover.xml> <seuil en %>\n");
    exit(2);
}

$xml = simplexml_load_file($argv[1]);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, sprintf("Rapport Clover illisible : %s\n", $argv[1]));
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$threshold = (float) $argv[2];
$coverage = $statements > 0 ? $covered / $statements * 100 : 0.0;

printf("Couverture des lignes : %.2f %% (%d/%d), seuil : %.2f %%\n", $coverage, $covered, $statements, $threshold);

if ($coverage < $threshold) {
    fwrite(STDERR, "Couverture insuffisante.\n");
    exit(1);
}
