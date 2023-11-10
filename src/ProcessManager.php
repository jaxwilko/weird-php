<?php

namespace Weird;

use Laravel\SerializableClosure\SerializableClosure;
use Weird\Contracts\Processable;
use Weird\Exceptions\ProcessFailed;
use Weird\Exceptions\ProcessSpawnFailed;
use Weird\Messages\Events\Dead;
use Weird\Messages\Events\FinishedEvent;
use Weird\Messages\Events\ProcessException;

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

        while (!array_product(array_map(fn (Process $process) => $process->ready(), $this->processes))) {
            usleep(500);
        }

        return $this;
    }

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

    public function tick()
    {
        foreach ($this->processes as $index => $process) {
            $message = $process->read();

            if (!$message) {
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

    public function processesCount(): int
    {
        return count($this->processes);
    }

    public function hasCommandsInBuffer(): bool
    {
        return !empty($this->commandBuffer);
    }

    public function isRunningCommands(): bool
    {
        return !empty($this->executionMap);
    }

    /**
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

    public function kill(int $index): bool
    {
        if (!isset($this->processes[$index])) {
            return true;
        }

        $status = $this->processes[$index]->kill();

        unset($this->processes[$index]);

        return $status;
    }

    public function killAll(): void
    {
        foreach ($this->processes as $index => $process) {
            $this->kill($index);
        }
    }

    public function __destruct()
    {
        $this->killAll();
    }
}
