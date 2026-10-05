<?php

namespace App\Http\Controllers\WebApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientErrorRequest;
use App\Infrastructure\Logging\ErrorRecorder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Receives JavaScript errors from pages so they show up next to server errors. */
class ClientErrorController extends Controller
{
    public function __invoke(ClientErrorRequest $request): Response
    {
        $data = $request->validated();
        $message = Str::limit((string) $data['message'], 1000, '');
        $source = isset($data['source']) ? $this->stripQuery((string) $data['source']) : null;
        $location = $source ? basename($source).(isset($data['line']) ? ':'.$data['line'] : '') : ($data['page'] ?? null);

        Log::channel('client')->error('Browser error', [
            'message' => $message,
            'source' => $source,
            'line' => $data['line'] ?? null,
            'column' => $data['column'] ?? null,
            'page' => $data['page'] ?? null,
            'url' => isset($data['url']) ? $this->stripQuery((string) $data['url']) : null,
            'stack' => $data['stack'] ?? null,
            'component' => $data['component'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        ErrorRecorder::record(
            fingerprint: sha1('client|'.$message.'|'.$location),
            level: 'error',
            source: 'client',
            class: null,
            message: $message,
            location: $location,
        );

        return response()->noContent();
    }

    /** Query strings may carry tokens (e.g. password reset links); never log them. */
    private function stripQuery(string $url): string
    {
        return Str::limit(strtok($url, '?#') ?: $url, 500, '');
    }
}
