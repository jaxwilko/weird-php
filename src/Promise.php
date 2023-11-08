<?php

namespace Weird;

use Laravel\SerializableClosure\SerializableClosure;
use Weird\Contracts\Processable;
use Weird\Traits\GenerateId;

class Promise implements Processable
{
    use GenerateId;

    public readonly string $id;
    protected SerializableClosure $closure;
    protected array $then = [];
    protected mixed $catcher;

    public function __construct(callable $closure)
    {
        $this->id = $this->generateId();
        $this->closure = new SerializableClosure($closure);
    }

    public static function make(callable $closure): static
    {
        return new static($closure);
    }

    public function then(callable $callable): static
    {
        $this->then[] = $callable;
        return $this;
    }

    public function catch(callable $callable): static
    {
        $this->catcher = $callable;
        return $this;
    }

    public function getExecutable(): SerializableClosure
    {
        return $this->closure;
    }

    public function handle(mixed $result): mixed
    {
        try {
            foreach ($this->then as $then) {
                $x = $then($result);
                if ($x) {
                    $result = $x;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            if (isset($this->catcher)) {
                return $this->catcher($e);
            }

            throw $e;
        }
    }
}