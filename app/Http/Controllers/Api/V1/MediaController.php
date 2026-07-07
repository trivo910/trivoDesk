<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Media — upload a file once and get a hosted URL you can reuse as the
 * `media_url` on POST /messages (or anywhere a public media URL is needed).
 * Saves to the same workspace media disk the inbox uses.
 */
class MediaController extends V1Controller
{
    /**
     * POST /api/v1/media — multipart upload (field name: file).
     * Returns { url, path, type, mime, size }.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // Max 16 MB — the WhatsApp document ceiling.
            'file' => ['required', 'file', 'max:16384'],
        ]);

        $file = $request->file('file');
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $type = str_starts_with($mime, 'image/') ? 'image'
            : (str_starts_with($mime, 'video/') ? 'video'
            : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file->getClientOriginalName()) ?: 'file';
        $path = $file->storeAs('chat-media', Str::random(10) . '__' . $safe, media_disk());

        return $this->created([
            'url'  => media_url($path),
            'path' => $path,
            'type' => $type,
            'mime' => $mime,
            'size' => $file->getSize(),
        ]);
    }
}
