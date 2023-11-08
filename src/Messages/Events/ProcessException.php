<?php

namespace Weird\Messages\Events;

use Weird\Contracts\Message;
use Weird\Messages\GenericMessage;

class ProcessException extends GenericMessage implements Message
{
    public function toString(): string
    {
        return $this->data['message'] . ' ' . $this->data['file'] . '@' . $this->data['line'];
    }
}
