<?php

namespace Weird\Messages;

use Weird\Contracts\Message;
use Weird\Traits\GenerateId;

abstract class GenericMessage implements Message
{
    use GenerateId;

    public readonly mixed $data;

    public function __construct(mixed $data = null)
    {
        $this->data = $data;
    }

    public function get(): mixed
    {
        return $this->data;
    }
}
