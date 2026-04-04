<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Exception;

/**
 * Exception levée lorsque le fichier composer.json est introuvable dans le projet.
 */
final class ComposerJsonNotFoundException extends \RuntimeException
{
}
