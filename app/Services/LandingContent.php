<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The public landing page's content as published from the visual editor (Platform > Landing
 * page): texts per language, section order/visibility, the video and the screenshot slides.
 * The frontend merges it over the page's built-in defaults (components/landing/content.ts), so
 * this only needs to keep it well-formed and safe: every value is plain text or a flag (it is
 * rendered as text, never HTML), and every media path points at the app's own files.
 *
 * Uploaded media lives on the `landing` disk (the persistent volume in production) and is
 * served at /landing-media/{file}.
 */
class LandingContent
{
    public const KEY = 'landing_page';

    public const SECTIONS = ['hero', 'demo', 'video', 'carousel', 'features', 'cta'];

    /** Built-in assets shipped in public/landing, or files uploaded through the editor. */
    private const MEDIA_PATTERN = '#^/(landing/[a-z0-9-]+|landing-media/[A-Za-z0-9]{32})\.(jpg|jpeg|png|webp|webm|mp4)$#';

    private const MAX_BYTES = 300_000;

    /**
     * @return array<mixed>|null
     */
    public function published(): ?array
    {
        return SiteSetting::getJson(self::KEY);
    }

    /**
     * Cleans and stores a new version, then removes uploaded files nothing refers to any more.
     *
     * @param  array<mixed>  $content
     * @return array<string, mixed>
     */
    public function publish(array $content): array
    {
        if (strlen((string) json_encode($content)) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['content' => __('The page content is too large.')]);
        }

        $clean = [
            'sections' => $this->sections($content['sections'] ?? []),
            'text' => [
                'en' => $this->clean($content['text']['en'] ?? []),
                'ar' => $this->clean($content['text']['ar'] ?? []),
            ],
            'video' => [
                'src' => $this->media($content['video']['src'] ?? null),
                'poster' => $this->media($content['video']['poster'] ?? null),
            ],
            'slides' => $this->slides($content['slides'] ?? []),
            'showRoadmapSlide' => (bool) ($content['showRoadmapSlide'] ?? true),
        ];

        SiteSetting::putJson(self::KEY, $clean);
        $this->pruneUnusedUploads($clean);

        return $clean;
    }

    /**
     * @return list<array{id: string, visible: bool}>
     */
    private function sections(mixed $sections): array
    {
        $out = [];
        $seen = [];

        foreach (is_array($sections) ? $sections : [] as $section) {
            $id = is_array($section) ? ($section['id'] ?? null) : null;

            if (is_string($id) && in_array($id, self::SECTIONS, true) && ! isset($seen[$id])) {
                $seen[$id] = true;
                $out[] = ['id' => $id, 'visible' => (bool) ($section['visible'] ?? true)];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, string>>
     */
    private function slides(mixed $slides): array
    {
        $out = [];

        foreach (array_slice(is_array($slides) ? $slides : [], 0, 30) as $slide) {
            if (! is_array($slide)) {
                continue;
            }

            $en = $this->media($slide['image_en'] ?? null);
            $ar = $this->media($slide['image_ar'] ?? null) ?? $en;

            if ($en === null) {
                continue;
            }

            $out[] = [
                'id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($slide['id'] ?? '')) ?: bin2hex(random_bytes(4)),
                'image_en' => $en,
                'image_ar' => (string) $ar,
                'title_en' => $this->text($slide['title_en'] ?? ''),
                'title_ar' => $this->text($slide['title_ar'] ?? ''),
                'text_en' => $this->text($slide['text_en'] ?? ''),
                'text_ar' => $this->text($slide['text_ar'] ?? ''),
            ];
        }

        return $out;
    }

    private function media(mixed $path): ?string
    {
        return is_string($path) && preg_match(self::MEDIA_PATTERN, $path) ? $path : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 2000) : '';
    }

    /** Texts: nested lists/objects of strings and flags only. */
    private function clean(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->text($value);
        }

        if (is_bool($value)) {
            return $value;
        }

        if (! is_array($value) || $depth > 5) {
            return null;
        }

        $out = [];

        foreach (array_slice($value, 0, 100, true) as $key => $item) {
            if (is_string($key) && ! preg_match('/^[A-Za-z0-9_]{1,40}$/', $key)) {
                continue;
            }

            $cleaned = $this->clean($item, $depth + 1);

            if ($cleaned !== null) {
                $out[$key] = $cleaned;
            }
        }

        return $out;
    }

    /**
     * Deletes uploads the published content no longer uses. Files from the last day are kept:
     * another admin may have just uploaded them in an editor that hasn't been published yet.
     *
     * @param  array<string, mixed>  $content
     */
    private function pruneUnusedUploads(array $content): void
    {
        $disk = Storage::disk('landing');
        $used = (string) json_encode($content);

        foreach ($disk->files() as $file) {
            if (! str_contains($used, '/landing-media/'.$file) && $disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
