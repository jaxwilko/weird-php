<?php

namespace Weird\Processes;

use React\EventLoop\Loop;
use Weird\Contracts\Processable;
use Weird\Messages\Events\Hint;
use Weird\Traits\StreamBuffer;

abstract class ParallelProcess implements Processable
{
    use StreamBuffer;

    protected float $tickRate = 1 / 32;
    protected Loop $loop;

    public function handle(): int
    {
        $this->loop = new Loop();
        $this->loop->addReadStream(STDIN, [$this, 'read']);
        $this->loop->addPeriodicTimer($this->tickRate, fn () => $this->tick());

        $this->register();

        $this->loop->run();

        return 0;
    }

    public function write(mixed $data): static
    {
        $this->writeStream(STDOUT, $data);
        return $this;
    }

    public static function output(mixed $data): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

        Hint::send([
            'from' => $backtrace,
            'message' => $data
        ]);
    }

    public static function isChild(): bool
    {
        return defined('WEIRD_SPAWNED_PROCESS');
    }

    abstract public function register();

    abstract public function read($stdin);

    abstract public function tick();
}