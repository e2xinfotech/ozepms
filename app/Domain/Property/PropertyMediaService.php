<?php

namespace App\Domain\Property;

use App\Domain\Audit\AuditLogger;
use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Property logo and cover photo on the public disk: properties/{code}/{kind}-{ulid}.{ext}.
 * The stored name never comes from the uploaded file name.
 */
class PropertyMediaService
{
    public const DISK = 'public';

    public const COLUMNS = ['logo' => 'logo_path', 'cover' => 'cover_image_path'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Property $property, string $kind, UploadedFile $file): Property
    {
        $column = self::COLUMNS[$kind];
        $path = \App\Support\SafeUpload::image($file, self::DISK, 'properties/'.$property->code, $kind.'-', ['min_w' => 64, 'min_h' => 64]);

        $old = $property->{$column};
        $property->forceFill([$column => $path])->save();
        if ($old) {
            Storage::disk(self::DISK)->delete($old);
        }

        $this->audit->log('property.media_updated', $property, ['before' => [$column => $old], 'after' => [$column => $path]], $property->id);

        return $property;
    }

    public function remove(Property $property, string $kind): Property
    {
        $column = self::COLUMNS[$kind];
        $old = $property->{$column};
        if ($old) {
            Storage::disk(self::DISK)->delete($old);
            $property->forceFill([$column => null])->save();
            $this->audit->log('property.media_updated', $property, ['before' => [$column => $old], 'after' => [$column => null]], $property->id);
        }

        return $property;
    }
}
