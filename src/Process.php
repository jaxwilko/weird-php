<?php

namespace Weird;

use Weird\Traits\StreamBuffer;
use Weird\Contracts\Message;
use Weird\Exceptions\ProcessSpawnFailed;

class Process
{
    use StreamBuffer;

    public const RUNNING_FALSE = 0;
    public const RUNNING_START = 2;
    public const RUNNING_ACTIVE = 4;
    public const RUNNING_STOPPED = 8;
    public const RUNNING_UNKNOWN = 16;

    protected readonly int $id;
    protected mixed $process;
    protected array $pipes = [];
    protected array $status = [];
    protected string $runtime = __DIR__ . '/runtime.php';
    protected int $running = self::RUNNING_FALSE;
    protected bool $busy = false;

    public function __construct(
        protected string $code,
        protected string $bootstrap = '',
        protected string $cwd = '',
        protected array $env = [],
        array $descriptors = null
    ) {
        $this->cwd = $this->cwd ?: getcwd();
        $descriptors = $descriptors ?? [
            ['pipe', 'r'],
            ['pipe', 'w'],
            // @TODO: add pipe error output buffering support
            ['file', '/tmp/error-output.txt', 'a']
        ];

        // when installed via composer execute permissions are not preserved, to fix, we mark the runtime as user x
        if (!is_executable($this->runtime)) {
            chmod($this->runtime, 0744);
        }

        $this->process = proc_open(
            [$this->runtime],
            $descriptors,
            $this->pipes,
            $this->cwd,
            array_merge(['PATH' => getenv('PATH')], $this->env)
        );

        if (!$this->process) {
            throw new ProcessSpawnFailed('failed to create process for: ' . $this->code);
        }

        // pass command to runtime
        fwrite($this->pipes[0], serialize(['bootstrap' => $this->bootstrap, 'execute' => $this->code]));
        // set pipe non-blocking
        stream_set_blocking($this->pipes[1], 0);

        $this->running = static::RUNNING_START;
    }

    public function ready(int $timeout = 5): static
    {
        $start = microtime(true);
        while (true) {
            if (microtime(true) - $start > $timeout) {
                $this->running = static::RUNNING_UNKNOWN;
                return $this;
            }

            $message = $this->read();

            if (!$message) {
                usleep(200);
                continue;
            }

            if (!isset($message->data['running']) || $message->data['running'] !== true) {
                $this->running = static::RUNNING_UNKNOWN;
                throw new ProcessSpawnFailed($message->data);
            }

            $this->running = static::RUNNING_ACTIVE;
            return $this;
        }
    }

    public function status(): array
    {
        if (!$this->running && !empty($this->status)) {
            return $this->status;
        }

        return $this->status = proc_get_status($this->process);
    }

    public function running(): int
    {
        return $this->running;
    }

    public function read(): ?Message
    {
        $message = $this->readStream($this->pipes[1]);

        if ($message) {
            $this->busy = false;
        }

        return $message;
    }

    public function write(mixed $message): bool
    {
        $this->busy = true;
        return $this->writeStream($this->pipes[0], $message);
    }

    public function kill(): bool
    {
        $this->status();

        $this->running = static::RUNNING_STOPPED;

        fclose($this->pipes[0]);
        fclose($this->pipes[1]);

        return proc_terminate($this->process, SIGKILL);
    }
}
