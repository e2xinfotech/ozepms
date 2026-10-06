<?php

namespace App\Domain\Guests;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reservations\ReferenceNumbers;
use App\Infrastructure\Database\Tx;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\Note;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guest profiles of a property: create / update with de-duplication by e-mail and phone,
 * tags, notes, document metadata (files on the private disk) and ID numbers (encrypted, with
 * an HMAC blind index for exact lookup).
 */
class GuestService
{
    /** Profile fields accepted from forms (validated by the request classes). */
    public const FIELDS = [
        'title', 'guest_type', 'first_name', 'last_name', 'email', 'phone', 'country_iso2', 'nationality_iso2', 'date_of_birth',
        'address_line1', 'address_line2', 'city', 'postcode', 'company_name', 'company_tax_no', 'id_type', 'id_number',
        'id_issuing_iso2', 'id_expiry', 'is_vip', 'marketing_consent', 'notes', 'preferences', 'tags',
    ];

    public const MAX_TAGS = 12;

    public const DOCUMENT_DISK = 'local';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReferenceNumbers $numbers,
    ) {}

    /** Existing guest with the same e-mail (first) or phone, or null. */
    public function findDuplicate(Property $property, ?string $email, ?string $phone, ?int $exceptId = null): ?Guest
    {
        $email = $email !== null && trim($email) !== '' ? Str::lower(trim($email)) : null;
        $phone = $this->normalizePhone($phone, $property);
        if ($email === null && $phone === null) {
            return null;
        }
        $base = fn () => Guest::query()->where('property_id', $property->id)->whereNull('anonymized_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId));

        return ($email !== null ? $base()->where('email_lc', $email)->orderBy('id')->first() : null)
            ?? ($phone !== null ? $base()->where('phone_e164', $phone)->orderBy('id')->first() : null);
    }

    /**
     * Guest for a booking: the given guest, else a duplicate by e-mail / phone, else a new one.
     * Empty fields of an existing guest are filled from $data; filled fields are not overwritten
     * unless $update is true (the form edited the selected guest).
     */
    public function resolve(Property $property, array $data, ?Guest $guest = null, bool $update = false, ?User $by = null): Guest
    {
        $guest ??= $this->findDuplicate($property, $data['email'] ?? null, $data['phone'] ?? null);
        if ($guest === null) {
            return $this->create($property, $data, $by);
        }
        $changes = [];
        foreach ($this->attributes($property, $data) as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $current = $guest->getAttribute($key);
            if ($update || $current === null || $current === '' || $current === []) {
                $changes[$key] = $value;
            }
        }
        if ($changes !== []) {
            $guest->fill($changes);
            if ($guest->isDirty()) {
                $diff = $this->audit->diff($guest);
                $guest->save();
                $this->audit->log('guest.updated', $guest, $this->redact($diff), $property->id, $by?->id);
            }
        }

        return $guest;
    }

    public function create(Property $property, array $data, ?User $by = null): Guest
    {
        return Tx::run(function () use ($property, $data, $by) {
            $guest = new Guest($this->attributes($property, $data));
            $guest->property_id = $property->id;
            $guest->guest_no = $this->numbers->guestNo($property->id);
            $guest->save();
            $this->audit->log('guest.created', $guest, ['after' => ['name' => $guest->fullName()]], $property->id, $by?->id);

            return $guest;
        });
    }

    public function update(Guest $guest, array $data, ?User $by = null): Guest
    {
        $property = Property::query()->findOrFail($guest->property_id);
        if (array_key_exists('email', $data) || array_key_exists('phone', $data)) {
            $dup = $this->findDuplicate($property, $data['email'] ?? $guest->email, $data['phone'] ?? $guest->phone_e164, $guest->id);
            if ($dup !== null) {
                $field = ($data['email'] ?? null) && Str::lower((string) $data['email']) === $dup->email_lc ? 'email' : 'phone';
                throw ValidationException::withMessages([$field => __('guests.errors.duplicate', ['guest' => $dup->fullName(), 'number' => $dup->number()])]);
            }
        }

        return Tx::run(function () use ($guest, $data, $property, $by) {
            $guest->fill($this->attributes($property, $data, partial: true));
            if ($guest->isDirty()) {
                $diff = $this->audit->diff($guest);
                $guest->save();
                $this->audit->log('guest.updated', $guest, $this->redact($diff), $property->id, $by?->id);
            }

            return $guest;
        });
    }

    /** @param  list<string>  $tags */
    public function setTags(Guest $guest, array $tags, ?User $by = null): Guest
    {
        $clean = collect($tags)->map(fn ($t) => Str::limit(trim((string) $t), 30, ''))->filter()->unique(fn ($t) => Str::lower($t))->values()->all();
        if (count($clean) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => __('guests.errors.too_many_tags', ['max' => self::MAX_TAGS])]);
        }
        $guest->tags = $clean;
        $guest->save();
        $this->audit->log('guest.tags', $guest, ['after' => $clean], $guest->property_id, $by?->id);

        return $guest;
    }

    public function addNote(Guest $guest, string $body, ?User $by = null): Note
    {
        return Note::query()->create([
            'property_id' => $guest->property_id, 'subject_type' => 'guest', 'subject_id' => $guest->id,
            'body' => Str::limit(trim($body), 2000, ''), 'user_id' => $by?->id, 'created_at' => now(),
        ]);
    }

    public function addDocument(Guest $guest, UploadedFile $file, string $type, ?int $reservationId = null, ?User $by = null): GuestDocument
    {
        $path = $file->store('guest-documents/'.$guest->property_id, self::DOCUMENT_DISK);

        try {
            $doc = GuestDocument::query()->create([
                'property_id' => $guest->property_id, 'guest_id' => $guest->id, 'reservation_id' => $reservationId,
                'doc_type' => $type, 'file_name' => Str::limit($file->getClientOriginalName(), 180, ''), 'path' => $path,
                'mime' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'uploaded_by' => $by?->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk(self::DOCUMENT_DISK)->delete($path);
            throw $e;
        }
        $this->audit->log('guest.document_added', $guest, ['after' => ['type' => $type, 'file' => $doc->file_name]], $guest->property_id, $by?->id);

        return $doc;
    }

    /** E.164-like: keeps a leading + and digits; local numbers get the country's dialling code. */
    public function normalizePhone(?string $phone, ?Property $property = null, ?string $countryIso2 = null): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($phone, '+')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }
        $iso = $countryIso2 ?? $property?->country_iso2;
        $code = $iso ? DB::table('countries')->where('iso2', $iso)->value('phone_code') : null;
        $code = $code ? preg_replace('/\D+/', '', (string) $code) : null;

        return '+'.($code ? $code.ltrim($digits, '0') : $digits);
    }

    public function idHash(string $number): string
    {
        $normalized = Str::upper(preg_replace('/[\s-]+/', '', $number) ?? '');

        return hash_hmac('sha256', $normalized, (string) config('app.key'), true);
    }

    /** @return array<string, mixed> model attributes from form data */
    private function attributes(Property $property, array $data, bool $partial = false): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            if ($partial && ! array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }
            switch ($field) {
                case 'phone':
                    $out['phone_e164'] = $this->normalizePhone($value, $property, $data['country_iso2'] ?? $data['nationality_iso2'] ?? null);
                    break;
                case 'id_number':
                    $out['id_number_enc'] = $value;
                    $out['id_number_hash'] = $value !== null ? $this->idHash($value) : null;
                    break;
                case 'email':
                    $out['email'] = $value !== null ? Str::lower($value) : null;
                    break;
                case 'country_iso2': case 'nationality_iso2': case 'id_issuing_iso2':
                    $out[$field] = $value !== null ? Str::upper($value) : null;
                    break;
                case 'is_vip': case 'marketing_consent':
                    if ($value !== null || ! $partial) {
                        $out[$field] = (bool) $value;
                    }
                    break;
                case 'guest_type':
                    $out[$field] = $value ?? 'individual';
                    break;
                case 'tags':
                    if ($value !== null) {
                        $out[$field] = array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) $value)));
                    }
                    break;
                default:
                    $out[$field] = $value;
            }
        }

        return $out;
    }

    private function redact(array $diff): array
    {
        foreach (['before', 'after'] as $side) {
            foreach (['id_number_enc', 'id_number_hash'] as $key) {
                if (array_key_exists($key, $diff[$side] ?? [])) {
                    $diff[$side][$key] = '•••';
                }
            }
        }

        return $diff;
    }
}
