<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Guests\GuestService;
use App\Domain\Guests\Queries\GuestQuery;
use App\Domain\Reservations\Queries\GlobalSearch;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsReservations;
use App\Http\Requests\Property\Reservations\SaveGuestRequest;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\Reservation;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestsController extends Controller
{
    use FindsReservations;

    public function __construct(
        private readonly GuestService $guests,
        private readonly GuestQuery $query,
        private readonly PropertyContext $context,
    ) {}

    public function show(Request $request, mixed $property, string $guest): JsonResponse
    {
        return response()->json(['guest' => $this->query->profile($this->guestOr404($guest), $this->context->property(), $request->user())]);
    }

    /** "Search Guest" in the reservation form: up to 8 matches by name, e-mail or phone. */
    public function lookup(Request $request, GlobalSearch $search): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'max:100']])['q'];
        $found = $search->search($this->context->property(), $q, reservations: false);
        $ids = array_column($found['guests'], 'id');
        $full = Guest::query()->whereIn('public_id', $ids)->get()->keyBy('public_id');

        return response()->json(['guests' => array_map(function ($g) use ($full) {
            $m = $full->get($g['id']);

            return $g + ['title' => $m?->title, 'guest_type' => $m?->guest_type, 'first_name' => $m?->first_name, 'last_name' => $m?->last_name,
                'id_type' => $m?->id_type, 'company_name' => $m?->company_name, 'vip' => (bool) $m?->is_vip];
        }, $found['guests'])]);
    }

    public function store(SaveGuestRequest $request): JsonResponse
    {
        $property = $this->context->property();
        $data = $request->validated();
        $dup = $this->guests->findDuplicate($property, $data['email'] ?? null, $data['phone'] ?? null);
        if ($dup !== null) {
            $field = ! empty($data['email']) && strtolower($data['email']) === $dup->email_lc ? 'email' : 'phone';
            throw \Illuminate\Validation\ValidationException::withMessages([$field => __('guests.errors.duplicate', ['guest' => $dup->fullName(), 'number' => $dup->number()])]);
        }
        $guest = $this->guests->create($property, $data, $request->user());

        return response()->json(['message' => __('guests.messages.created', ['name' => $guest->fullName()]), 'guest' => ['id' => $guest->public_id]], 201);
    }

    public function update(SaveGuestRequest $request, mixed $property, string $guest): JsonResponse
    {
        $model = $this->guests->update($this->guestOr404($guest), $request->validated(), $request->user());

        return response()->json(['message' => __('guests.messages.updated', ['name' => $model->fullName()]),
            'guest' => $this->query->profile($model->fresh(), $this->context->property(), $request->user())]);
    }

    public function tags(SaveGuestRequest $request, mixed $property, string $guest): JsonResponse
    {
        $model = $this->guests->setTags($this->guestOr404($guest), $request->validated('tags') ?? [], $request->user());

        return response()->json(['message' => __('guests.messages.tags_saved'), 'tags' => $model->tags ?? []]);
    }

    public function note(SaveGuestRequest $request, mixed $property, string $guest): JsonResponse
    {
        $note = $this->guests->addNote($this->guestOr404($guest), (string) $request->validated('body'), $request->user());

        return response()->json(['message' => __('guests.messages.note_added'), 'note' => [
            'id' => $note->id, 'body' => $note->body, 'user' => $request->user()?->name, 'at' => $note->created_at?->toIso8601String(),
        ]], 201);
    }

    public function upload(SaveGuestRequest $request, mixed $property, string $guest): JsonResponse
    {
        $model = $this->guestOr404($guest);
        $reservationId = $request->validated('reservation_id')
            ? Reservation::query()->where('public_id', $request->validated('reservation_id'))->value('id') : null;
        $doc = $this->guests->addDocument($model, $request->file('file'), (string) $request->validated('type'), $reservationId, $request->user());

        return response()->json(['message' => __('guests.messages.document_added'), 'document' => [
            'id' => $doc->public_id, 'type' => $doc->doc_type, 'name' => $doc->file_name, 'size' => (int) $doc->size_bytes, 'mime' => $doc->mime, 'at' => $doc->created_at?->toIso8601String(),
        ]], 201);
    }

    public function download(mixed $property, string $guest, string $document): StreamedResponse
    {
        $model = $this->guestOr404($guest);
        $doc = GuestDocument::query()->where('guest_id', $model->id)->where('public_id', $document)->firstOrFail();
        abort_unless(Storage::disk(GuestService::DOCUMENT_DISK)->exists($doc->path), 404);

        return Storage::disk(GuestService::DOCUMENT_DISK)->download($doc->path, $doc->file_name, ['Content-Type' => $doc->mime]);
    }

    public function export(Request $request): StreamedResponse
    {
        $property = $this->context->property();
        $name = 'guests-'.$property->code.'-'.now($property->timezone)->format('Ymd-His').'.csv';
        $query = Guest::query()->whereNull('anonymized_at')->where('is_companion', 0)->orderBy('guest_no');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            \App\Support\Csv::put($out, array_map(fn ($k) => __('guests.fields.'.$k), ['number', 'first_name', 'last_name', 'email', 'phone', 'nationality_iso2', 'guest_type', 'company_name', 'is_vip', 'created_at']));
            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $g) {
                    \App\Support\Csv::put($out, [$g->number(), $g->first_name, $g->last_name, $g->email, $g->phone_e164, $g->nationality_iso2,
                        __('guests.types.'.$g->guest_type), $g->company_name, $g->is_vip ? __('ui.yes') : __('ui.no'), $g->created_at?->toDateTimeString()]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
