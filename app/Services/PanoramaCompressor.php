<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shrinks an uploaded 360° photo before it is stored, so a client can
 * upload the original from a phone / 360 camera and guests still get a
 * fast, sharp image.
 *
 * - Wider than MAX_WIDTH px: scaled down to MAX_WIDTH, aspect kept.
 *   Never scaled up.
 * - Re-encoded as WebP quality 90 (JPEG quality 90 when GD has no WebP).
 * - If the result is not smaller than the original, the original is kept.
 * - Any failure (no GD, unreadable image, not enough memory) returns null
 *   and the caller stores the original untouched.
 *
 * Needs the GD extension. Decoding is memory hungry (about 4 bytes per
 * pixel), so memory_limit is raised for the call and put back afterwards.
 */
class PanoramaCompressor
{
    /** Per-file upload limit, in KB (Laravel's `max` rule unit): 25 MB. */
    public const MAX_UPLOAD_KB = 25600;

    public const MAX_WIDTH = 8192;

    public const QUALITY = 90;

    /** Above this estimated need we do not even try to decode. */
    private const MEMORY_CEILING = 2 * 1024 * 1024 * 1024;

    /**
     * @return array{contents: string, extension: string}|null null = keep the original file
     */
    public function compress(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();

        if (! is_string($path) || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $info = @getimagesize($path);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        [$width, $height] = $info;
        $originalSize = (int) filesize($path);
        $targetWidth = min($width, self::MAX_WIDTH);
        $targetHeight = (int) max(1, round($height * $targetWidth / $width));

        // Source bitmap + resized bitmap + the file buffers, with headroom.
        $needed = (int) (($width * $height + $targetWidth * $targetHeight) * 4 + $originalSize * 2 + 96 * 1024 * 1024);

        if ($needed > self::MEMORY_CEILING) {
            Log::warning('Panorama too large to compress; stored as uploaded.', [
                'file' => $file->getClientOriginalName(), 'width' => $width, 'height' => $height,
            ]);

            return null;
        }

        $previousLimit = ini_get('memory_limit');
        $this->raiseMemoryLimit((string) $previousLimit, $needed);

        $source = null;
        $target = null;

        try {
            $source = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefromwebp($path);
            if (! $source) {
                throw new \RuntimeException('Image could not be decoded.');
            }

            if ($info[2] === IMAGETYPE_JPEG) {
                $source = $this->applyOrientation($source, $path);
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $targetWidth = min($sourceWidth, self::MAX_WIDTH);
            $targetHeight = (int) max(1, round($sourceHeight * $targetWidth / $sourceWidth));

            $image = $source;
            if ($targetWidth < $sourceWidth) {
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                if (! $target || ! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) {
                    throw new \RuntimeException('Image could not be resized.');
                }
                imagedestroy($source);
                $source = null;
                $image = $target;
            }

            [$contents, $extension] = $this->encode($image);

            if ($contents === '' || strlen($contents) >= $originalSize) {
                return null;
            }

            return ['contents' => $contents, 'extension' => $extension];
        } catch (Throwable $e) {
            Log::warning('Panorama compression failed; stored as uploaded.', [
                'file' => $file->getClientOriginalName(), 'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $source = null;
            $target = null;
            $image = null;
            @ini_set('memory_limit', (string) $previousLimit);
        }
    }

    /**
     * @return array{0: string, 1: string} [binary, extension]
     */
    private function encode(\GdImage $image): array
    {
        ob_start();

        try {
            if ($this->webpSupported()) {
                imagewebp($image, null, self::QUALITY);
                $extension = 'webp';
            } else {
                imageinterlace($image, true);
                imagejpeg($image, null, self::QUALITY);
                $extension = 'jpg';
            }
        } finally {
            $contents = (string) ob_get_clean();
        }

        return [$contents, $extension];
    }

    private function webpSupported(): bool
    {
        return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
    }

    /**
     * GD drops EXIF, so bake a phone's rotation into the pixels first.
     */
    private function applyOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated ?: $image;
    }

    private function raiseMemoryLimit(string $current, int $needed): void
    {
        $limit = $this->toBytes($current);

        if ($limit === -1 || $limit >= $needed) {
            return;
        }

        ini_set('memory_limit', (string) $needed);
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
