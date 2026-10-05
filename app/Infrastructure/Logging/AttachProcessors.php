<?php

namespace App\Infrastructure\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

/**
 * Logging "tap": JSON lines + request context on the channels that use it.
 */
final class AttachProcessors
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(new ContextProcessor);
            $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true));
        }
    }
}
