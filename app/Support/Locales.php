<?php

namespace App\Support;

/**
 * The languages the app is offered in. Every string lives in lang/{code}.json (the interface
 * texts the frontend uses, plus the server's own messages, emails and export labels). English
 * is the reference and the fallback for anything missing. Keep this list in step with
 * resources/js/hooks/use-locale.tsx.
 */
final class Locales
{
    /** @var array<string, array{name: string, dir: 'ltr'|'rtl'}> */
    public const SUPPORTED = [
        'ar' => ['name' => 'العربية', 'dir' => 'rtl'],
        'en' => ['name' => 'English', 'dir' => 'ltr'],
        'tr' => ['name' => 'Türkçe', 'dir' => 'ltr'],
        'fr' => ['name' => 'Français', 'dir' => 'ltr'],
        // Northern Kurdish (Kurmanji), written in the Latin script: the variety spoken in Syria.
        'ku' => ['name' => 'Kurdî (Kurmancî)', 'dir' => 'ltr'],
    ];

    public static function isSupported(?string $code): bool
    {
        return $code !== null && isset(self::SUPPORTED[$code]);
    }

    public static function direction(?string $code = null): string
    {
        return self::SUPPORTED[$code ?? app()->getLocale()]['dir'] ?? 'ltr';
    }

    /**
     * Translates a (possibly nested) set of English labels, e.g. the column headers of a PDF or
     * spreadsheet export, into the current language through lang/{code}.json.
     *
     * @template T of array<array-key, mixed>
     *
     * @param  T  $labels
     * @return T
     */
    public static function labels(array $labels): array
    {
        /** @var T $translated */
        $translated = array_map(
            fn (mixed $value) => is_array($value) ? self::labels($value) : (is_string($value) ? __($value) : $value),
            $labels,
        );

        return $translated;
    }
}
