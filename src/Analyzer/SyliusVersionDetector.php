<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Analyzer;

/**
 * Determine la version de Sylius d'un projet.
 *
 * La version installee est lue dans composer.lock. Sans lock (squelette, plugin, depot qui ne
 * le versionne pas), la contrainte declaree dans composer.json est retournee, suffixee de
 * "(composer.json)" pour qu'elle ne soit pas confondue avec une version installee.
 */
final class SyliusVersionDetector
{
    /** Paquets dont la version reflete celle de Sylius */
    private const SYLIUS_PACKAGES = ['sylius/sylius', 'sylius/core-bundle'];

    /**
     * Retourne la version installee, a defaut la contrainte declaree, ou null.
     */
    public function detect(string $projectPath): ?string
    {
        return $this->fromLock($projectPath . '/composer.lock')
            ?? $this->fromComposerJson($projectPath . '/composer.json');
    }

    private function fromLock(string $path): ?string
    {
        $data = $this->readJson($path);
        if ($data === null) {
            return null;
        }

        $packages = array_merge(
            is_array($data['packages'] ?? null) ? $data['packages'] : [],
            is_array($data['packages-dev'] ?? null) ? $data['packages-dev'] : [],
        );

        foreach ($packages as $package) {
            if (is_array($package)
                && in_array($package['name'] ?? null, self::SYLIUS_PACKAGES, true)
                && is_string($package['version'] ?? null)
            ) {
                return $package['version'];
            }
        }

        return null;
    }

    private function fromComposerJson(string $path): ?string
    {
        $data = $this->readJson($path);
        if ($data === null) {
            return null;
        }

        foreach (['require', 'require-dev'] as $section) {
            $requirements = $data[$section] ?? null;
            if (!is_array($requirements)) {
                continue;
            }

            foreach (self::SYLIUS_PACKAGES as $package) {
                if (is_string($requirements[$package] ?? null)) {
                    return $requirements[$package] . ' (composer.json)';
                }
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
