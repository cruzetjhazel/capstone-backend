<?php

namespace App\Services\Photographer;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns any uploaded image into a clean 4:1 cover banner.
 *
 * Never stretches and never rejects on dimensions: the image is scaled to
 * cover the banner and the overflow is center-cropped. Images smaller than
 * the target are NOT upscaled (they keep the 4:1 ratio at their own size).
 */
class CoverPhotoProcessor
{
    public const WIDTH = 2400;          // max output width
    public const RATIO = 4;             // width : height = 4 : 1
    public const JPEG_QUALITY = 85;
    public const MAX_KILOBYTES = 3072;  // 3 MB, keep in sync with PhotographerProfileRequest

    /** Resizes/crops the upload and stores it on the public disk. Returns the stored path. */
    public function store(UploadedFile $file, string $directory): string
    {
        $binary = $this->render($file);

        $path = trim($directory, '/') . '/' . Str::random(40) . '.jpg';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /** Returns the processed banner as JPEG bytes. */
    public function render(UploadedFile $file): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('The GD extension is required to process cover photos.');
        }

        // A compressed file under 3 MB can still decode to a huge bitmap.
        $previousLimit = ini_set('memory_limit', '512M');

        try {
            $contents = file_get_contents($file->getRealPath());
            $source = @imagecreatefromstring($contents);

            if ($source === false) {
                throw ValidationException::withMessages([
                    'cover_photo' => 'That file could not be read as an image. Please use a JPG, PNG or WebP.',
                ]);
            }

            $source = $this->applyExifOrientation($source, $file);

            $srcW = imagesx($source);
            $srcH = imagesy($source);

            // Largest 4:1 rectangle that fits inside the source, centered.
            if ($srcW / $srcH > self::RATIO) {
                $cropH = $srcH;
                $cropW = $srcH * self::RATIO;
            } else {
                $cropW = $srcW;
                $cropH = (int) floor($srcW / self::RATIO);
            }
            $cropW = max(1, (int) $cropW);
            $cropH = max(1, (int) $cropH);
            $cropX = (int) floor(($srcW - $cropW) / 2);
            $cropY = (int) floor(($srcH - $cropH) / 2);

            // Scale down to the target width; never scale up.
            $outW = min(self::WIDTH, $cropW);
            $outH = max(1, (int) round($outW / self::RATIO));

            $canvas = imagecreatetruecolor($outW, $outH);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // flatten PNG/WebP transparency
            imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $outW, $outH, $cropW, $cropH);

            ob_start();
            imagejpeg($canvas, null, self::JPEG_QUALITY);
            $binary = ob_get_clean();

            return $binary;
        } finally {
            if ($previousLimit !== false) {
                ini_set('memory_limit', $previousLimit);
            }
        }
    }

    /** Phone photos are often stored rotated with an EXIF flag; honor it before cropping. */
    protected function applyExifOrientation(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $orientation = $exif['Orientation'] ?? 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated ?: $image;
    }
}