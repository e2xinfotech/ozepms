<?php

namespace App\Support;

use Illuminate\Contracts\View\View;

/**
 * Renders one page of the multi-page app: the Blade document plus the React
 * entry for that page. Each page downloads only its own code.
 *
 *   return Page::render('admin/properties/index', ['properties' => $rows], __('nav.properties'));
 */
final class Page
{
    /** @param  array<string, mixed>  $props */
    public static function render(string $page, array $props = [], ?string $title = null, string $layout = 'app'): View
    {
        $entry = 'resources/js/pages/'.$page.'.tsx';

        return view('page', [
            'entry' => $entry,
            'title' => $title,
            'layout' => $layout,
            'payload' => [
                'page' => $page,
                'layout' => $layout,
                'props' => $props,
                'shell' => $layout === 'app' ? app(ShellData::class)->build() : ShellData::guest(),
                // The public booking engine gets only its own texts (no PMS wording on a public page).
                'i18n' => Translations::forClient($layout === 'booking' ? ['booking', 'ui', 'errors'] : null),
                'locale' => app()->getLocale(),
                'flash' => array_filter([
                    'success' => session('success'),
                    'notice' => session('notice'),
                    'error' => session('errors')?->first(),
                ]),
            ],
        ]);
    }
}
