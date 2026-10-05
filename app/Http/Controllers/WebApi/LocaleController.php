<?php

namespace App\Http\Controllers\WebApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocaleRequest;
use Illuminate\Http\Response;

/** Changes the interface language for this session and, when signed in, for the account. */
class LocaleController extends Controller
{
    public function __invoke(LocaleRequest $request): Response
    {
        $locale = (string) $request->validated('locale');
        $request->session()->put('locale', $locale);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        return response()->noContent();
    }
}
