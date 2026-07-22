<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Support;

/**
 * Minimal reader for CS-Cart language .po files — just enough to extract the
 * `Languages::<key>` entries (msgctxt => msgstr) that the per-addon
 * lang-parity tests compare against lang_keys.php / addon.xml. Handles
 * multi-line string continuations and the standard escapes; everything else
 * (headers, other contexts, comments) is skipped.
 */
final class PoFile
{
    /**
     * @return array<string, string> language key (without the Languages:: prefix) => msgstr
     */
    public static function languages(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        $key = null;
        $collecting = false;
        $value = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'msgctxt ')) {
                if ($key !== null && $collecting) {
                    $entries[$key] = $value;
                }
                $key = null;
                $collecting = false;
                $value = '';
                $ctxt = self::unquote(substr($line, 8));
                if (str_starts_with($ctxt, 'Languages::')) {
                    $key = substr($ctxt, strlen('Languages::'));
                }
                continue;
            }

            if (str_starts_with($line, 'msgstr ')) {
                $collecting = $key !== null;
                $value = self::unquote(substr($line, 7));
                continue;
            }

            if (str_starts_with($line, 'msgid ')) {
                // msgid between ctxt and str — flushes nothing.
                continue;
            }

            if ($collecting && str_starts_with($line, '"')) {
                // msgstr continuation line
                $value .= self::unquote($line);
                continue;
            }

            if ($line === '' && $key !== null && $collecting) {
                $entries[$key] = $value;
                $key = null;
                $collecting = false;
                $value = '';
            }
        }

        if ($key !== null && $collecting) {
            $entries[$key] = $value;
        }

        return $entries;
    }

    private static function unquote(string $chunk): string
    {
        $chunk = trim($chunk);
        if (str_starts_with($chunk, '"') && str_ends_with($chunk, '"') && strlen($chunk) >= 2) {
            $chunk = substr($chunk, 1, -1);
        }

        return str_replace(['\\"', '\\n', '\\t', '\\\\'], ['"', "\n", "\t", '\\'], $chunk);
    }
}
