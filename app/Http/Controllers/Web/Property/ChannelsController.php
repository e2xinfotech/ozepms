<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Channels\ChannelPresenter;
use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ChannelsController extends Controller
{
    public function index(PropertyContext $context, ChannelPresenter $presenter): View
    {
        return Page::render('property/channels/index', $presenter->index($context->property()), __('channels.title'));
    }

    public function show(Request $request, ChannelPresenter $presenter, mixed $property, string $connection): View
    {
        $c = ChannelConnection::query()->where('public_id', $connection)->firstOrFail();

        return Page::render('property/channels/show', $presenter->show($c) + [
            'tab' => in_array($request->query('tab'), ['overview', 'mapping', 'logs', 'bookings', 'test'], true) ? $request->query('tab') : 'overview',
        ], $c->name ?: __('channels.providers.'.$c->provider));
    }
}
