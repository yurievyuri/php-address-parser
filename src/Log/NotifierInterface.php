<?php

declare(strict_types=1);

namespace Address\Parser\Log;

/**
 * Where severe events go on their way out of this process — an alerting channel, a queue, a
 * webhook, a chat message.
 *
 * Kept as an interface with one method because every project already has its own way of raising an
 * alarm, and none of them belong in this package.
 */
interface NotifierInterface
{
    public function notify(LogRecord $record): void;
}
