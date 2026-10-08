<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way files from users reach the disk.
 *  - The real content decides, never the file name or the type the browser claims.
 *  - Images: JPEG, PNG or WebP only, size and pixel limits, then re-drawn from the decoded pixels so
 *    metadata and anything hidden inside the file (scripts, polyglots) is gone.
 *  - PDFs (ID documents): must really be a PDF, and active content (scripts, launch actions) is refused.
 *  - The stored name is generated; the uploaded name is never used on disk.
 */
final class SafeUpload
{
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Largest picture accepted, in pixels (about 50 megapixels): bigger ones could exhaust memory when decoded. */
    private const MAX_PIXELS = 50_000_000;

    /**
     * Stores a picture on $disk under $dir and returns its path.
     *
     * @param  array{min_w?: int, min_h?: int, max_w?: int, max_h?: int}  $limits
     */
    public static function image(UploadedFile $file, string $disk, string $dir, string $prefix = '', array $limits = [], string $field = 'image'): string
    {
        $path = $file->getRealPath();
        $info = $path && is_file($path) ? @getimagesize($path) : false;
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if ($info === false || ! is_string($mime) || ! isset(self::IMAGE_TYPES[$mime]) || ($info['mime'] ?? '') !== $mime) {
            self::refuse($field, 'upload.not_image');
        }
        [$w, $h] = $info;
        if ($w < ($limits['min_w'] ?? 1) || $h < ($limits['min_h'] ?? 1) || $w > ($limits['max_w'] ?? 8000) || $h > ($limits['max_h'] ?? 8000) || $w * $h > self::MAX_PIXELS) {
            self::refuse($field, 'upload.image_size');
        }
        // Decoding needs about 5 bytes per pixel (plus a copy): refuse pictures that would not fit in the memory left, instead of crashing.
        $limit = self::memoryLimit();
        if ($limit > 0 && $w * $h * 10 > ($limit - memory_get_usage()) ) {
            self::refuse($field, 'upload.image_size');
        }
        $bytes = self::redraw($path, $mime);
        $name = $prefix.Str::lower((string) Str::ulid()).'.'.self::IMAGE_TYPES[$mime];
        $target = trim($dir, '/').'/'.$name;
        Storage::disk($disk)->put($target, $bytes);

        return $target;
    }

    /**
     * Stores an ID document (PDF or picture) on the private $disk and returns [path, mime, size].
     *
     * @return array{0: string, 1: string, 2: int}
     */
    public static function document(UploadedFile $file, string $disk, string $dir, string $field = 'file'): array
    {
        $path = $file->getRealPath();
        $mime = $path && is_file($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if ($mime === 'application/pdf') {
            $head = (string) file_get_contents($path, false, null, 0, 1024);
            if (! str_starts_with(ltrim($head, "\xEF\xBB\xBF \t\r\n"), '%PDF-')) {
                self::refuse($field, 'upload.not_document');
            }
            // Active content has no place in an identity document.
            $body = (string) file_get_contents($path);
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b#', $body)) {
                self::refuse($field, 'upload.pdf_active');
            }
            $name = Str::lower((string) Str::ulid()).'.pdf';
            Storage::disk($disk)->put(trim($dir, '/').'/'.$name, $body);

            return [trim($dir, '/').'/'.$name, 'application/pdf', strlen($body)];
        }
        $stored = self::image($file, $disk, $dir, '', ['max_w' => 10000, 'max_h' => 10000], $field);

        return [$stored, (string) Storage::disk($disk)->mimeType($stored), (int) Storage::disk($disk)->size($stored)];
    }

    private static function memoryLimit(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;

        return match (strtoupper(substr($v, -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }

    private static function redraw(string $path, string $mime): string
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };
        if ($img === false) {
            self::refuse('image', 'upload.not_image');
        }
        // Honour the camera's rotation before the metadata is dropped.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            $img = match ($orientation) {
                3 => imagerotate($img, 180, 0) ?: $img,
                6 => imagerotate($img, -90, 0) ?: $img,
                8 => imagerotate($img, 90, 0) ?: $img,
                default => $img,
            };
        }
        if ($mime !== 'image/jpeg') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($img, null, 88),
            'image/png' => imagepng($img, null, 6),
            default => imagewebp($img, null, 86),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        if ($bytes === '') {
            self::refuse('image', 'upload.not_image');
        }

        return $bytes;
    }

    private static function refuse(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __($key)]);
    }
}
