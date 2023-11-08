<?php

namespace Weird\Processes;

use Laravel\SerializableClosure\SerializableClosure;
use Weird\Contracts\Processable;
use Weird\Messages\Events\Dead;
use Weird\Messages\Events\FinishedEvent;
use Weird\Messages\Events\ProcessException;


class Thread extends ParallelProcess
{
    public function register()
    {
        set_error_handler(function (int $errNo, string $errStr, string $errFile, int $errLine) {
            $this->write(new ProcessException([
                'code' => $errNo,
                'message' => $errStr,
                'file' => $errFile,
                'line' => $errLine
            ]));

            $this->write(new Dead());

            exit(1);
        });
    }

    public function tick()
    {
        // do nothing
    }

    public function read($stdin)
    {
        $message = $this->readStream($stdin);

        if (!$message) {
            return;
        }

        if ($message->get() instanceof SerializableClosure) {
            $closure = $message->get()->getClosure();
            $this->write($closure($this));
            $this->write(new FinishedEvent());
            return;
        }

        $this->write('Encountered unknown: ' . print_r($message->get(), true));
    }
}