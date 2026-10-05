<?php

namespace App\Domain\Accommodation;

use App\Domain\Audit\AuditLogger;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Support\PropertyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Room type photos on the public disk: properties/{code}/room-types/{public_id}/{ulid}.{ext} */
class RoomTypeImageService
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function store(RoomType $roomType, UploadedFile $file, ?string $alt = null): RoomTypeImage
    {
        $property = $this->context->property();
        $dir = 'properties/'.$property->code.'/room-types/'.$roomType->public_id;
        $name = Str::lower((string) Str::ulid()).'.'.($file->guessExtension() ?: 'jpg');
        $path = $file->storeAs($dir, $name, RoomTypeImage::DISK);

        $image = RoomTypeImage::query()->create([
            'room_type_id' => $roomType->id,
            'path' => $path,
            'alt_text' => $alt,
            'sort_order' => (int) RoomTypeImage::query()->where('room_type_id', $roomType->id)->max('sort_order') + 1,
        ]);
        $this->audit->log('room_type.image_added', $roomType, ['after' => ['path' => $path]]);

        return $image;
    }

    public function delete(RoomTypeImage $image): void
    {
        $roomType = RoomType::query()->withTrashed()->findOrFail($image->room_type_id);
        Storage::disk(RoomTypeImage::DISK)->delete($image->path);
        $image->delete();
        $this->audit->log('room_type.image_removed', $roomType, ['before' => ['path' => $image->path]]);
    }

    /** @param  list<int>  $orderedIds */
    public function reorder(RoomType $roomType, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $id) {
            RoomTypeImage::query()->where('room_type_id', $roomType->id)->whereKey($id)->update(['sort_order' => $index + 1]);
        }
    }
}
