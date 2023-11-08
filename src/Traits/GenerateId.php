<?php

namespace Weird\Traits;

trait GenerateId
{
    /**
     * @throws \Exception
     */
    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
