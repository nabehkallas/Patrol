<?php

namespace Tests\Feature;

use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The interface languages: their translation files, and how the chosen one reaches the server. */
class LocalesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function messages(string $locale): array
    {
        /** @var array<string, string> */
        return json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-zA-Z_]+/', $text, $matches);
        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }

    public function test_every_language_translates_every_string_with_the_same_placeholders(): void
    {
        $en = $this->messages('en');
        // Arabic is the most complete file: every interface key plus the server's own strings.
        $reference = $this->messages('ar');

        $this->assertSame([], array_values(array_diff_key($en, $reference)), 'Interface keys missing from ar.json');

        foreach (array_keys(Locales::SUPPORTED) as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $messages = $this->messages($locale);

            $this->assertSame([], array_keys(array_diff_key($reference, $messages)), "Missing from {$locale}.json");
            $this->assertSame([], array_keys(array_diff_key($messages, $reference)), "Unknown keys in {$locale}.json");

            foreach ($messages as $key => $text) {
                $this->assertNotSame('', trim($text), "Empty {$locale}.json value for {$key}");
                $this->assertSame(
                    $this->placeholders($en[$key] ?? $reference[$key]),
                    $this->placeholders($text),
                    "Placeholders differ in {$locale}.json for {$key}",
                );
            }
        }
    }

    public function test_the_locale_cookie_sets_the_language_and_text_direction_of_the_page(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr', 'tr' => 'ltr', 'fr' => 'ltr', 'ku' => 'ltr'] as $locale => $direction) {
            $this->withUnencryptedCookie('locale', $locale)
                ->get('/login')
                ->assertOk()
                ->assertSee("lang=\"{$locale}\" dir=\"{$direction}\"", false);
        }
    }

    public function test_an_unknown_locale_falls_back_to_english(): void
    {
        $this->withUnencryptedCookie('locale', 'xx')
            ->get('/login')
            ->assertOk()
            ->assertSee('lang="en" dir="ltr"', false);
    }

    public function test_export_labels_and_server_messages_follow_the_language(): void
    {
        $labels = ['title' => 'Cash Box', 'columns' => ['date' => 'Date', 'liters' => 'Liters Sold (gross)']];

        app()->setLocale('en');
        $this->assertSame($labels, Locales::labels($labels));

        app()->setLocale('ar');
        $this->assertSame(
            ['title' => 'الصندوق', 'columns' => ['date' => 'التاريخ', 'liters' => 'المباع (لتر)']],
            Locales::labels($labels),
        );
        $this->assertSame('rtl', Locales::direction());

        app()->setLocale('tr');
        $this->assertSame('Kasa', Locales::labels($labels)['title']);
        $this->assertSame('ltr', Locales::direction());
        $this->assertSame('Bu e-posta adresi zaten kullanılıyor.', __('This email is already in use.'));

        app()->setLocale('fr');
        $this->assertSame('Recettes (SYP)', __('Income (:currency)', ['currency' => 'SYP']));
    }
}
