<?php

declare(strict_types=1);

/*
 * Compare les dernieres versions de securite publiees par php.net avec la table
 * FIRST_PATCHED_VERSIONS de PhpSecurityPatchAnalyzer.
 *
 * Affiche une ligne par branche dont une version de securite plus recente que la table
 * est sortie, et termine avec le code 1 dans ce cas : l'analyseur doit etre mis a jour.
 *
 * Usage : php check-php-security-releases.php <chemin de PhpSecurityPatchAnalyzer.php>
 */

if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage : php check-php-security-releases.php <PhpSecurityPatchAnalyzer.php>\n");
    exit(2);
}

$source = (string) file_get_contents($argv[1]);
if (preg_match('/FIRST_PATCHED_VERSIONS = \[(.*?)\];/s', $source, $block) !== 1
    || preg_match_all("/'(\\d+\\.\\d+)' => '(\\d+\\.\\d+\\.\\d+)'/", $block[1], $entries, PREG_SET_ORDER) === 0
) {
    fwrite(STDERR, "Table FIRST_PATCHED_VERSIONS introuvable.\n");
    exit(2);
}

$outdated = [];

foreach ($entries as [, $branch, $recorded]) {
    $url = sprintf('https://www.php.net/releases/index.php?json&max=10&version=%s', $branch);
    $json = @file_get_contents($url);
    $releases = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($releases)) {
        fwrite(STDERR, sprintf("Reponse illisible pour la branche %s.\n", $branch));
        exit(2);
    }

    foreach ($releases as $version => $release) {
        $isSecurity = in_array('security', $release['tags'] ?? [], true);
        if ($isSecurity && version_compare((string) $version, $recorded, '>')) {
            $outdated[] = sprintf('- PHP %s : version de securite %s publiee le %s (table : %s)', $branch, $version, $release['date'] ?? '?', $recorded);
            break;
        }
    }
}

if ($outdated === []) {
    echo "Table a jour avec les versions de securite de php.net.\n";
    exit(0);
}

echo implode("\n", $outdated), "\n";
exit(1);
