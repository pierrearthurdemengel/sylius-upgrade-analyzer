<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\AutoFix;

/**
 * Commente une cle YAML avec tout son bloc (les lignes plus indentees qui la suivent).
 *
 * Commenter la seule ligne de la cle laisserait ses enfants rattaches au parent : le
 * fichier deviendrait invalide ou, pire, changerait de sens sans erreur.
 */
final class YamlBlockCommenter
{
    /**
     * Commente chaque bloc dont la ligne de cle correspond a $keyPattern et le fait
     * preceder d'une ligne "# TODO: $todo" a la meme indentation.
     *
     * @param string $keyPattern Expression reguliere du nom de la cle, sans delimiteurs ni ancre
     */
    public static function comment(string $content, string $keyPattern, string $todo): string
    {
        $lines = explode("\n", $content);
        $result = [];
        $count = \count($lines);

        for ($i = 0; $i < $count; $i++) {
            /* \r? : les fichiers CRLF gardent un \r en fin de ligne apres le decoupage sur \n */
            if (preg_match('/^([ \t]*)(?:' . $keyPattern . ')[ \t]*:(?:[ \t]|\r?$)/', $lines[$i], $match) !== 1) {
                $result[] = $lines[$i];
                continue;
            }

            $indent = $match[1];
            $depth = \strlen($indent);
            $result[] = $indent . '# TODO: ' . $todo;
            $result[] = $indent . '# ' . substr($lines[$i], $depth);

            /* Lignes du bloc : plus indentees que la cle ; les lignes vides internes suivent le bloc */
            while ($i + 1 < $count) {
                $next = $lines[$i + 1];
                if (trim($next) === '') {
                    if (!self::continuesBlock($lines, $i + 1, $depth)) {
                        break;
                    }
                    $result[] = $next;
                    $i++;
                    continue;
                }
                if (self::indentation($next) <= $depth) {
                    break;
                }
                $result[] = $indent . '# ' . substr($next, $depth);
                $i++;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Indique si le bloc reprend apres une serie de lignes vides.
     *
     * @param list<string> $lines
     */
    private static function continuesBlock(array $lines, int $from, int $depth): bool
    {
        $count = \count($lines);
        for ($j = $from; $j < $count; $j++) {
            if (trim($lines[$j]) !== '') {
                return self::indentation($lines[$j]) > $depth;
            }
        }

        return false;
    }

    private static function indentation(string $line): int
    {
        return \strlen($line) - \strlen(ltrim($line, " \t"));
    }
}
