<?php

namespace Weird;

use Laravel\SerializableClosure\SerializableClosure;
use Weird\Contracts\Processable;
use Weird\Exceptions\ProcessFailed;
use Weird\Exceptions\ProcessSpawnFailed;
use Weird\Messages\Events\Dead;
use Weird\Messages\Events\FinishedEvent;
use Weird\Messages\Events\Hint;
use Weird\Messages\Events\ProcessException;
use Weird\Messages\UnknownMessage;

class ProcessManager
{
    /**
     * @var string the bootstrap file path
     */
    protected string $bootstrap = '';

    /**
     * @var array<Process>
     */
    protected array $processes = [];

    /**
     * @var array<array>
     */
    protected array $executionMap = [];

    /**
     * @var array<int<string>>
     */
    protected array $processMap = [];

    /**
     * @var array<Promise>
     */
    protected array $commandBuffer = [];

    /**
     * @var array<callable>
     */
    protected array $hintHandlers = [];

    /**
     * @var array<callable>
     */
    protected array $unknownMessageHandlers = [];

    /**
     * Create a new ProcessManager instance
     *
     * @return static
     */
    public static function create(): static
    {
        return new static();
    }

    /**
     * Set the bootstrap path
     *
     * @param string $bootstrap
     * @return $this
     */
    public function withBootstrap(string $bootstrap): static
    {
        $this->bootstrap = $bootstrap;
        return $this;
    }

    /**
     * Create X amount of processes, calling \Weird\Contracts\Processable compatible $class inside each
     *
     * @param string $class
     * @param int $processes
     * @return $this
     * @throws ProcessSpawnFailed
     */
    public function spawn(string $class, int $processes = 1): static
    {
        if (!$this->bootstrap) {
            $this->bootstrap = $this->attemptedBootstrapAutoDetect();
        }

        for ($i = 0; $i < $processes; $i++) {
            $this->processes[] = new Process($class, $this->bootstrap);
        }

        while (!array_product(array_map(fn (Process $process) => $process->ready()->running(), $this->processes))) {
            usleep(500);
        }

        return $this;
    }

    /**
     * Dispatch a Processable object (Promise/callbale) across process workers
     *
     * @param Processable|callable|array $command
     * @return $this
     */
    public function dispatch(Processable|callable|array $command): static
    {
        if (is_array($command)) {
            foreach ($command as $c) {
                $this->dispatch($c);
            }

            return $this;
        }

        if (!$command instanceof Promise) {
            $command = new Promise($command);
        }

        $availableProcesses = array_values(array_diff(array_keys($this->processes), array_keys($this->processMap)));

        if (!$availableProcesses) {
            $this->commandBuffer[] = $command;
            return $this;
        }

        $process = $availableProcesses[0];

        $this->executionMap[$command->id] = [
            'command' => $command,
            'output' => [],
        ];

        $this->processMap[$process] = $command->id;
        $this->processes[$process]->write($command->getExecutable());

        return $this;
    }

    /**
     * Check all processes for messages and trigger events / callbacks when needed
     *
     * @return void
     * @throws ProcessFailed
     */
    public function tick()
    {
        foreach ($this->processes as $index => $process) {
            $message = $process->read();

            if (!$message) {
                continue;
            }

            if ($message instanceof Hint) {
                foreach ($this->hintHandlers as $callable) {
                    $callable($message->get(), $process);
                }
                continue;
            }

            if ($message instanceof UnknownMessage) {
                foreach ($this->unknownMessageHandlers as $callable) {
                    $callable($message->get(), $process);
                }
                continue;
            }

            if ($message instanceof ProcessException) {
                throw new ProcessFailed($message->toString());
            }

            if ($message instanceof Dead) {
                // @TODO: Implement some kind of recovery
                throw new ProcessFailed('process has died');
            }

            if ($message instanceof FinishedEvent) {
                $this->executionMap[$this->processMap[$index]]['command']
                    ->handle(
                        count($this->executionMap[$this->processMap[$index]]['output']) === 1
                            ? $this->executionMap[$this->processMap[$index]]['output'][0]->get()
                            : $this->executionMap[$this->processMap[$index]]['output']
                    );

                unset($this->executionMap[$this->processMap[$index]]);
                unset($this->processMap[$index]);

                if ($this->commandBuffer) {
                    $this->dispatch(array_shift($this->commandBuffer));
                }

                continue;
            }

            $this->executionMap[$this->processMap[$index]]['output'][] = $message;
        }
    }

    /**
     * Register a handler to be called when a Hint message is sent by a process.
     * Handler must accept (mixed $message, Process $process)
     *
     * @param callable $callback
     * @return $this
     */
    public function registerHintHandler(callable $callback): static
    {
        $this->hintHandlers[] = $callback;
        return $this;
    }

    /**
     * Register a handler to be called when a UnknownMessage is sent by a process.
     * Handler must accept (mixed $message, Process $process)
     *
     * @param callable $callback
     * @return $this
     */
    public function registerUnknownMessageHandler(callable $callback): static
    {
        $this->unknownMessageHandlers[] = $callback;
        return $this;
    }

    /**
     * Return the amount of processes being managed
     *
     * @return int
     */
    public function processesCount(): int
    {
        return count($this->processes);
    }

    /**
     * Return the amount of commands in the command buffer queue
     *
     * @return int
     */
    public function commandBufferCount(): int
    {
        return count($this->commandBuffer);
    }

    /**
     * Return bool if commands are being queued
     *
     * @return bool
     */
    public function hasCommandsInBuffer(): bool
    {
        return !empty($this->commandBuffer);
    }

    /**
     * Return bool if command are being ran
     *
     * @return bool
     */
    public function isRunningCommands(): bool
    {
        return !empty($this->executionMap);
    }

    /**
     * Wait for all issued commands to finish execution
     *
     * @param float $timeout seconds to wait for
     * @return $this
     * @throws ProcessFailed
     */
    public function wait(float $timeout = 10): static
    {
        $time = microtime(true);

        while (!empty($this->executionMap)) {
            if (microtime(true) - $timeout > $time) {
                break;
            }

            $this->tick();
        }

        return $this;
    }

    /**
     * Attempt to automatically load composer based on files loaded
     *
     * @return string
     */
    protected function attemptedBootstrapAutoDetect(): string
    {
        $files = get_required_files();
        foreach ($files as $index => $file) {
            if (
                str_ends_with($file, '/vendor/autoload.php')
                && isset($files[$index + 1])
                && str_ends_with($files[$index + 1], '/vendor/composer/autoload_real.php')
            ) {
                return $file;
            }
        }

        return '';
    }

    /**
     * Kill a process by its internal index id
     *
     * @param int $index
     * @return bool
     */
    public function kill(int $index): bool
    {
        if (!isset($this->processes[$index])) {
            return true;
        }

        $status = $this->processes[$index]->kill();

        unset($this->processes[$index]);

        return $status;
    }

    /**
     * Kill all processes
     *
     * @return void
     */
    public function killAll(): void
    {
        foreach ($this->processes as $index => $process) {
            $this->kill($index);
        }
    }

    /**
     * Trigger all child processes to be terminated
     */
    public function __destruct()
    {
        $this->killAll();
    }
}
