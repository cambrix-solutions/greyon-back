<?php

namespace App\Services;

use App\Models\RoomType;
use App\Models\RoomTypePanorama;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * File + row handling for a room type's 360° photos. Files live on the
 * public disk under `uploads/rooms/{id}/`, the same place the single
 * panorama has always been stored.
 */
class RoomPanoramaStore
{
    /**
     * Store one uploaded file as the last panorama of the room type.
     */
    public function add(RoomType $roomType, UploadedFile $file, bool $published = true): RoomTypePanorama
    {
        $next = $roomType->panoramas()->max('sort_order');

        return $roomType->panoramas()->create([
            'path' => $this->storeFile($file, $roomType),
            'is_published' => $published,
            'sort_order' => $next === null ? 0 : ((int) $next) + 1,
        ]);
    }

    /**
     * Delete one panorama row and its file.
     */
    public function remove(RoomTypePanorama $panorama): void
    {
        $this->deleteFile($panorama->path);
        $panorama->delete();
    }

    /**
     * Delete every panorama of the room type (rows and files), plus the
     * legacy column's file if it was never copied into the table.
     */
    public function clear(RoomType $roomType): void
    {
        foreach ($roomType->panoramas()->get() as $panorama) {
            $this->remove($panorama);
        }

        $this->deleteFile($roomType->panorama_path);
        $roomType->forceFill(['panorama_path' => null])->save();
    }

    public function deleteFile(?string $path): void
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return;
        }

        $path = ltrim($path, '/');
        if (! str_starts_with($path, 'uploads/rooms/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function storeFile(UploadedFile $file, RoomType $roomType): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (! in_array($ext, ['jpg', 'webp'], true)) {
            $ext = 'jpg';
        }

        $path = $file->storeAs('uploads/rooms/'.$roomType->id, Str::uuid()->toString().'.'.$ext, 'public');

        if (! $path) {
            throw ValidationException::withMessages([
                'panoramas' => 'Upload failed. Please try again.',
            ]);
        }

        return $path;
    }
}
