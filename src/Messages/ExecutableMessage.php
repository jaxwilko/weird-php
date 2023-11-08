<?php

namespace Weird\Messages;

use Laravel\SerializableClosure\SerializableClosure;
use Weird\Contracts\Message;

class ExecutableMessage extends GenericMessage implements Message
{
    public function __construct(SerializableClosure $data)
    {
        parent::__construct($data);
    }
}
