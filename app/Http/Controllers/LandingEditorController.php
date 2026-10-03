<?php

namespace App\Http\Controllers;

use App\Services\LandingContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Platform > Landing page: the visual (WYSIWYG) editor for the public landing page. The editor
 * renders the real page and edits it in the browser; nothing reaches visitors until Publish.
 */
class LandingEditorController extends Controller
{
    private const UPLOAD_EXTENSIONS = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function edit(LandingContent $landing): Response
    {
        return Inertia::render('platform/landing-editor', [
            'content' => $landing->published(),
        ]);
    }

    public function publish(Request $request, LandingContent $landing): RedirectResponse
    {
        $data = $request->validate(['content' => ['required', 'array']]);

        $landing->publish($data['content']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Landing page published.')]);

        return to_route('platform.landing.edit');
    }

    /** Uploads one video or image for the editor and returns its public URL. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            // 60 MB: the web server accepts uploads up to 64 MB.
            'file' => ['required', 'file', 'max:61440', 'mimetypes:'.implode(',', array_keys(self::UPLOAD_EXTENSIONS))],
        ]);

        $file = $request->file('file');
        $extension = self::UPLOAD_EXTENSIONS[$file->getMimeType()] ?? null;

        abort_if($extension === null, 422);

        $name = Str::random(32).'.'.$extension;
        Storage::disk('landing')->putFileAs('', $file, $name);

        return response()->json(['url' => '/landing-media/'.$name]);
    }

    /** Serves an uploaded file (public; supports range requests so videos can seek). */
    public function media(string $file): BinaryFileResponse
    {
        $disk = Storage::disk('landing');

        abort_unless($disk->exists($file), 404);

        return response()->file($disk->path($file), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
