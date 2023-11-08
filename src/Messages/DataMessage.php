<?php

namespace Weird\Messages;

use Weird\Contracts\Message;

class DataMessage extends GenericMessage implements Message
{
    public function __construct(mixed $data)
    {
        parent::__construct(($value = @unserialize($data)) ? $value : $data);
    }
}
