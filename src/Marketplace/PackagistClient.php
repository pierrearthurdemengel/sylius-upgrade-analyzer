<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Marketplace;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client pour l'API Packagist.
 * Verifie la compatibilite des plugins Sylius en analysant les contraintes
 * de dependances declarees dans les versions publiees sur Packagist.
 */
final class PackagistClient
{
    /** Delai d'attente maximal pour les requetes HTTP en secondes */
    private const TIMEOUT = 5;

    /** URL de base de l'API Packagist */
    private const BASE_URL = 'https://packagist.org';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Verifie la compatibilite d'un plugin en analysant ses metadonnees Packagist.
     * En cas d'erreur reseau ou de reponse invalide, retourne un statut UNKNOWN.
     */
    public function checkCompatibility(string $packageName, string $targetSyliusVersion): PluginCompatibility
    {
        try {
            $url = sprintf('%s/packages/%s.json', self::BASE_URL, $packageName);
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                return new PluginCompatibility(
                    packageName: $packageName,
                    currentVersion: '',
                    status: PluginCompatibilityStatus::UNKNOWN,
                    notes: sprintf('Packagist a retourne le code HTTP %d pour %s', $statusCode, $packageName),
                );
            }

            $data = $response->toArray(false);

            return $this->parseResponse($packageName, $data, $targetSyliusVersion);
        } catch (\Throwable $e) {
            return new PluginCompatibility(
                packageName: $packageName,
                currentVersion: '',
                status: PluginCompatibilityStatus::UNKNOWN,
                notes: sprintf('Erreur lors de la requete vers Packagist : %s', $e->getMessage()),
            );
        }
    }

    /**
     * Analyse la reponse JSON de Packagist pour determiner le statut de compatibilite.
     *
     * @param array<mixed> $data Donnees JSON decodees de Packagist
     */
    private function parseResponse(string $packageName, array $data, string $targetSyliusVersion): PluginCompatibility
    {
        $packageData = $data['package'] ?? null;
        if (!is_array($packageData)) {
            return new PluginCompatibility(
                packageName: $packageName,
                currentVersion: '',
                status: PluginCompatibilityStatus::UNKNOWN,
                notes: 'Format de reponse inattendu de Packagist',
            );
        }

        /* Verification du statut d'abandon */
        $abandoned = $packageData['abandoned'] ?? false;
        if ($abandoned !== false) {
            $replacement = is_string($abandoned) ? $abandoned : null;

            return new PluginCompatibility(
                packageName: $packageName,
                currentVersion: '',
                status: PluginCompatibilityStatus::ABANDONED,
                notes: $replacement !== null
                    ? sprintf('Le paquet est abandonne. Remplacement suggere : %s', $replacement)
                    : 'Le paquet est abandonne et n\'a pas de remplacement suggere',
            );
        }

        /* Analyse des versions pour trouver une compatibilite avec Sylius cible */
        $versions = $packageData['versions'] ?? [];
        if (!is_array($versions)) {
            return new PluginCompatibility(
                packageName: $packageName,
                currentVersion: '',
                status: PluginCompatibilityStatus::UNKNOWN,
                notes: 'Aucune information de version disponible sur Packagist',
            );
        }

        $majorTarget = $this->extractMajorVersion($targetSyliusVersion);
        $compatibleVersion = null;

        /* Un paquet dont aucune version ne cite le coeur de Sylius (outil de qualite, extension
           Behat...) n'est pas contraint par la montee de version, meme publie par un editeur Sylius. */
        $hasReadableVersion = false;
        $mentionsSylius = false;

        /* Parcours des versions pour trouver une compatibilite */
        foreach ($versions as $versionName => $versionData) {
            if (!is_array($versionData) || !is_string($versionName)) {
                continue;
            }

            /* Ignorer les branches de developpement */
            if (str_starts_with($versionName, 'dev-')) {
                continue;
            }

            $require = $versionData['require'] ?? [];
            if (!is_array($require)) {
                continue;
            }

            $hasReadableVersion = true;
            foreach (array_keys($require) as $depName) {
                if (is_string($depName) && $this->isSyliusDependency($depName)) {
                    $mentionsSylius = true;
                }
            }

            /* Recherche de la dependance sylius/sylius dans les exigences */
            foreach ($require as $depName => $depConstraint) {
                if (!is_string($depName) || !is_string($depConstraint)) {
                    continue;
                }

                if (!$this->isSyliusDependency($depName)) {
                    continue;
                }

                if ($this->constraintMatchesMajor($depConstraint, $majorTarget)) {
                    $compatibleVersion = $versionName;
                    break 2;
                }
            }
        }

        if ($compatibleVersion !== null) {
            return new PluginCompatibility(
                packageName: $packageName,
                currentVersion: '',
                status: PluginCompatibilityStatus::COMPATIBLE,
                compatibleVersion: $compatibleVersion,
                notes: sprintf(
                    'La version %s est compatible avec Sylius %s selon Packagist',
                    $compatibleVersion,
                    $targetSyliusVersion,
                ),
            );
        }

        /* Recherche de compatibilite partielle (branches de developpement) */
        foreach ($versions as $versionName => $versionData) {
            if (!is_array($versionData) || !is_string($versionName)) {
                continue;
            }

            if (!str_starts_with($versionName, 'dev-')) {
                continue;
            }

            $require = $versionData['require'] ?? [];
            if (!is_array($require)) {
                continue;
            }

            $hasReadableVersion = true;
            foreach (array_keys($require) as $depName) {
                if (is_string($depName) && $this->isSyliusDependency($depName)) {
                    $mentionsSylius = true;
                }
            }

            foreach ($require as $depName => $depConstraint) {
                if (!is_string($depName) || !is_string($depConstraint)) {
                    continue;
                }

                if (!$this->isSyliusDependency($depName)) {
                    continue;
                }

                if ($this->constraintMatchesMajor($depConstraint, $majorTarget)) {
                    return new PluginCompatibility(
                        packageName: $packageName,
                        currentVersion: '',
                        status: PluginCompatibilityStatus::PARTIALLY_COMPATIBLE,
                        compatibleVersion: $versionName,
                        notes: sprintf(
                            'Seule la branche de developpement %s est compatible avec Sylius %s',
                            $versionName,
                            $targetSyliusVersion,
                        ),
                    );
                }
            }
        }

        if ($hasReadableVersion && !$mentionsSylius) {
            return new PluginCompatibility(
                packageName: $packageName,
                currentVersion: '',
                status: PluginCompatibilityStatus::COMPATIBLE,
                notes: 'Le paquet ne depend pas du coeur de Sylius : la montee de version ne le contraint pas',
            );
        }

        return new PluginCompatibility(
            packageName: $packageName,
            currentVersion: '',
            status: PluginCompatibilityStatus::INCOMPATIBLE,
            notes: sprintf(
                'Aucune version compatible avec Sylius %s trouvee sur Packagist',
                $targetSyliusVersion,
            ),
        );
    }

    /**
     * Verifie si le nom de dependance correspond a un paquet Sylius principal.
     */
    private function isSyliusDependency(string $name): bool
    {
        return $name === 'sylius/sylius'
            || $name === 'sylius/core-bundle'
            || $name === 'sylius/resource-bundle'
            || $name === 'sylius/grid-bundle';
    }

    /**
     * Verifie si une contrainte de version Composer est compatible avec une version majeure.
     *
     * La contrainte est compatible si l'ensemble des versions qu'elle autorise intersecte
     * [N.0.0, N+1.0.0[. Comparer seulement la majeure ecrite dans la contrainte est faux :
     * "^1.12 <2.0" mentionne 2 sans l'autoriser, ">=1.0 <3.0" autorise 2 sans l'ecrire.
     * Les drapeaux de stabilite (@dev, -beta) sont ignores : seule la plage numerique compte.
     */
    private function constraintMatchesMajor(string $constraint, string $majorTarget): bool
    {
        if (!ctype_digit($majorTarget)) {
            return false;
        }

        $major = (int) $majorTarget;

        /* Decoupe des alternatives (|| ou |) : une seule doit intersecter la majeure */
        $alternatives = preg_split('/\s*\|\|?\s*/', trim($constraint));
        if ($alternatives === false) {
            return false;
        }

        foreach ($alternatives as $alternative) {
            if ($alternative === '') {
                continue;
            }

            if ($this->conjunctionIntersects(
                $alternative,
                ['version' => [$major, 0, 0], 'inclusive' => true],
                ['version' => [$major + 1, 0, 0], 'inclusive' => false],
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restreint l'intervalle de la majeure cible par chaque contrainte de la conjonction,
     * puis indique s'il reste au moins une version.
     *
     * @param array{version: list<int>, inclusive: bool} $low  Borne basse de la majeure cible
     * @param array{version: list<int>, inclusive: bool} $high Borne haute de la majeure cible
     */
    private function conjunctionIntersects(string $conjunction, array $low, array $high): bool
    {
        /*
         * Plage avec tiret : une borne haute partielle couvre toute sa derniere composante
         * ("1.0 - 2.0" equivaut a ">=1.0.0 <2.1.0"), une borne complete est inclusive.
         */
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $conjunction, $matches) === 1) {
            $from = $this->parseVersion($matches[1]);
            $to = $this->parseVersion($matches[2]);
            if ($from === null || $to === null) {
                return false;
            }

            $toCount = count($to['parts']);
            $low = $this->tighterLow($low, ['version' => $this->padVersion($from['parts']), 'inclusive' => true]);
            $high = $this->tighterHigh($high, $toCount < 3
                ? ['version' => $this->bumpVersion($to['parts'], max(0, $toCount - 1)), 'inclusive' => false]
                : ['version' => $this->padVersion($to['parts']), 'inclusive' => true]);

            return $this->isNonEmpty($low, $high, []);
        }

        /* Composer tolere un espace entre l'operateur et la version (">= 2.0") */
        $normalized = preg_replace('/(<=|>=|!=|<>|==|[<>=^~])\s+/', '$1', $conjunction);
        $parts = preg_split('/\s*,\s*|\s+/', trim($normalized ?? $conjunction));
        if ($parts === false) {
            return false;
        }

        /* Versions exclues par != : chacune ne retire qu'un point de l'intervalle */
        $excluded = [];

        foreach ($parts as $part) {
            if (preg_match('/^(<=|>=|!=|<>|==|<|>|=|\^|~)?(.+)$/', $part, $matches) !== 1) {
                return false;
            }

            $operator = $matches[1];
            $version = $this->parseVersion($matches[2]);

            /* Contrainte illisible (self.version, dev-master...) : compatibilite non prouvee */
            if ($version === null) {
                return false;
            }

            $versionParts = $version['parts'];
            $count = count($versionParts);
            $padded = $this->padVersion($versionParts);

            if ($version['wildcard'] && in_array($operator, ['', '=', '=='], true)) {
                /* "*" n'impose rien ; "2.*" couvre [2.0.0, 3.0.0[ */
                if ($count > 0) {
                    $low = $this->tighterLow($low, ['version' => $padded, 'inclusive' => true]);
                    $high = $this->tighterHigh($high, ['version' => $this->bumpVersion($versionParts, $count - 1), 'inclusive' => false]);
                }

                continue;
            }

            switch ($operator) {
                case '>=':
                    $low = $this->tighterLow($low, ['version' => $padded, 'inclusive' => true]);
                    break;
                case '>':
                    $low = $this->tighterLow($low, ['version' => $padded, 'inclusive' => false]);
                    break;
                case '<=':
                    $high = $this->tighterHigh($high, ['version' => $padded, 'inclusive' => true]);
                    break;
                case '<':
                    $high = $this->tighterHigh($high, ['version' => $padded, 'inclusive' => false]);
                    break;
                case '!=':
                case '<>':
                    $excluded[] = $padded;
                    break;
                case '^':
                    /* Le caret bloque la premiere composante non nulle : ^1.2 < 2.0.0, ^0.3 < 0.4.0 */
                    $lockedIndex = max(0, $count - 1);
                    foreach ($versionParts as $index => $value) {
                        if ($value !== 0) {
                            $lockedIndex = $index;
                            break;
                        }
                    }
                    $low = $this->tighterLow($low, ['version' => $padded, 'inclusive' => true]);
                    $high = $this->tighterHigh($high, ['version' => $this->bumpVersion($versionParts, $lockedIndex), 'inclusive' => false]);
                    break;
                case '~':
                    /* Le tilde laisse varier la derniere composante ecrite : ~2.1 < 3.0.0, ~2.1.3 < 2.2.0 */
                    $low = $this->tighterLow($low, ['version' => $padded, 'inclusive' => true]);
                    $high = $this->tighterHigh($high, ['version' => $this->bumpVersion($versionParts, max(0, $count - 2)), 'inclusive' => false]);
                    break;
                default:
                    /* Version exacte, avec ou sans "=" */
                    $low = $this->tighterLow($low, ['version' => $padded, 'inclusive' => true]);
                    $high = $this->tighterHigh($high, ['version' => $padded, 'inclusive' => true]);
            }
        }

        return $this->isNonEmpty($low, $high, $excluded);
    }

    /**
     * Decompose une version Composer en composantes numeriques (au plus trois).
     * Le prefixe v et les suffixes de stabilite sont ignores ; un joker (* ou x) arrete la lecture.
     * Retourne null si la chaine n'est pas une version numerique.
     *
     * @return array{parts: list<int>, wildcard: bool}|null
     */
    private function parseVersion(string $version): ?array
    {
        if (preg_match('/^v?((?:\d+|[*xX])(?:\.(?:\d+|[*xX])){0,3})(?:[-@+].*)?$/', $version, $matches) !== 1) {
            return null;
        }

        $parts = [];
        foreach (array_slice(explode('.', $matches[1]), 0, 3) as $segment) {
            if (!ctype_digit($segment)) {
                return ['parts' => $parts, 'wildcard' => true];
            }
            $parts[] = (int) $segment;
        }

        return ['parts' => $parts, 'wildcard' => false];
    }

    /**
     * Complete une version partielle par des zeros : [2, 1] devient [2, 1, 0].
     *
     * @param list<int> $parts
     *
     * @return list<int>
     */
    private function padVersion(array $parts): array
    {
        return array_slice(array_pad($parts, 3, 0), 0, 3);
    }

    /**
     * Incremente la composante d'indice donne et remet les suivantes a zero :
     * [2, 1, 3] incremente a l'indice 1 donne [2, 2, 0].
     *
     * @param list<int> $parts
     *
     * @return list<int>
     */
    private function bumpVersion(array $parts, int $index): array
    {
        $bumped = [];
        foreach ($this->padVersion($parts) as $position => $value) {
            $bumped[] = match (true) {
                $position < $index => $value,
                $position === $index => $value + 1,
                default => 0,
            };
        }

        return $bumped;
    }

    /**
     * Retient la plus restrictive de deux bornes basses (la plus haute, l'exclusive a egalite).
     *
     * @param array{version: list<int>, inclusive: bool} $current
     * @param array{version: list<int>, inclusive: bool} $candidate
     *
     * @return array{version: list<int>, inclusive: bool}
     */
    private function tighterLow(array $current, array $candidate): array
    {
        $comparison = $candidate['version'] <=> $current['version'];

        return $comparison > 0 || ($comparison === 0 && !$candidate['inclusive']) ? $candidate : $current;
    }

    /**
     * Retient la plus restrictive de deux bornes hautes (la plus basse, l'exclusive a egalite).
     *
     * @param array{version: list<int>, inclusive: bool} $current
     * @param array{version: list<int>, inclusive: bool} $candidate
     *
     * @return array{version: list<int>, inclusive: bool}
     */
    private function tighterHigh(array $current, array $candidate): array
    {
        $comparison = $candidate['version'] <=> $current['version'];

        return $comparison < 0 || ($comparison === 0 && !$candidate['inclusive']) ? $candidate : $current;
    }

    /**
     * Indique si l'intervalle borne par low et high contient au moins une version.
     * Une exclusion != ne vide l'intervalle que s'il est reduit a ce seul point.
     *
     * @param array{version: list<int>, inclusive: bool} $low
     * @param array{version: list<int>, inclusive: bool} $high
     * @param list<list<int>>                            $excluded
     */
    private function isNonEmpty(array $low, array $high, array $excluded): bool
    {
        $comparison = $low['version'] <=> $high['version'];
        if ($comparison !== 0) {
            return $comparison < 0;
        }

        return $low['inclusive'] && $high['inclusive'] && !in_array($low['version'], $excluded, true);
    }

    /**
     * Extrait la version majeure d'une chaine de version.
     */
    private function extractMajorVersion(string $version): string
    {
        $cleaned = ltrim($version, '^~><=v');
        $parts = explode('.', $cleaned);

        return $parts[0];
    }
}
