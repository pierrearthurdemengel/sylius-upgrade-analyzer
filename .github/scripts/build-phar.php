<?php

declare(strict_types=1);

/*
 * Construit l'archive PHAR autonome a partir d'un repertoire prepare par prepare-dist.sh.
 *
 * Usage : php -d phar.readonly=0 build-phar.php <repertoire de distribution> <fichier .phar>
 */

if ($argc !== 3 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage : php -d phar.readonly=0 build-phar.php <repertoire> <fichier.phar>\n");
    exit(2);
}

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly doit valoir 0 : php -d phar.readonly=0 build-phar.php ...\n");
    exit(2);
}

$sourceDirectory = realpath($argv[1]);
$output = $argv[2];

if (file_exists($output)) {
    unlink($output);
}

$phar = new Phar($output, 0, 'sylius-upgrade-analyzer.phar');
$phar->startBuffering();

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
);

$count = 0;
foreach ($files as $file) {
    $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDirectory) + 1));

    // Les tests, la documentation et les metadonnees des dependances n'ont rien a faire dans l'archive
    if (preg_match('#(^|/)(tests?|Tests?|docs?|\.git[^/]*|\.github)(/|$)#', $relativePath) === 1
        && !str_starts_with($relativePath, 'src/')
        && !str_starts_with($relativePath, 'templates/')
    ) {
        continue;
    }

    $phar->addFile($file->getPathname(), $relativePath);
    $count++;
}

// Le binaire est lu sans sa ligne shebang, qui serait affichee a l'execution depuis l'archive
$binary = (string) file_get_contents($sourceDirectory . '/bin/sylius-upgrade-analyzer');
$phar->addFromString('bin/sylius-upgrade-analyzer', (string) preg_replace('/^#!.*\n/', '', $binary));

$phar->setStub(<<<'STUB'
    #!/usr/bin/env php
    <?php
    Phar::mapPhar('sylius-upgrade-analyzer.phar');
    require 'phar://sylius-upgrade-analyzer.phar/bin/sylius-upgrade-analyzer';
    __HALT_COMPILER();
    STUB);

$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();

chmod($output, 0755);
printf("%s : %d fichiers, %.1f Mo\n", $output, $count, filesize($output) / 1048576);
