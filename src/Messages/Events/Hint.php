<?php

namespace Weird\Messages\Events;

use Weird\Contracts\Message;
use Weird\Messages\GenericMessage;

class Hint extends GenericMessage implements Message
{
    public static function send(mixed $message): void
    {
        if (!defined('WEIRD_SPAWNED_PROCESS')) {
            return;
        }

        processWrite(new static($message));
    }
}
