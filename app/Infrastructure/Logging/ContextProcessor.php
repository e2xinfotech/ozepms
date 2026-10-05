<?php

namespace App\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds request id, user, property and route to every log line and strips secrets.
 */
final class ContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        if (app()->bound('request') && ! app()->runningInConsole()) {
            $request = request();
            $extra['request_id'] = $request->attributes->get('request_id');
            $extra['ip'] = $request->ip();
            $extra['route'] = $request->route()?->getName() ?? $request->path();
            $extra['method'] = $request->method();
        }

        try {
            $extra['user_id'] = auth()->id();
        } catch (\Throwable) {
            // auth not available yet (early boot)
        }

        $context = app(\App\Support\PropertyContext::class);
        $extra['property_id'] = $context->idOrNull();

        return $record->with(context: Redactor::clean($record->context), extra: $extra);
    }
}
