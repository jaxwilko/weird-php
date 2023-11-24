<?php

use PHPUnit\Framework\TestCase;
use Weird\ProcessManager;
use Weird\Processes\Thread;
use Weird\Promise;

class ProcessManagerTest extends TestCase
{
    protected string $bootstrapFile = __DIR__ . '/bootstrap.php';

    /**
     * Validate that the ProcessManager can spawn processes
     *
     * @return void
     * @throws \Weird\Exceptions\ProcessSpawnFailed
     */
    public function testSpawnProcess(): void
    {
        $manager = ProcessManager::create()
            ->withBootstrap($this->bootstrapFile)
            ->spawn(Thread::class);

        $this->assertEquals(1, $manager->processesCount());

        $manager->killAll();

        $this->assertEquals(0, $manager->processesCount());

        $manager = ProcessManager::create()
            ->withBootstrap($this->bootstrapFile)
            ->spawn(Thread::class, 4);

        $this->assertEquals(4, $manager->processesCount());

        $manager->killAll();

        $this->assertEquals(0, $manager->processesCount());
    }

    /**
     * Validate that Promise can be executed and next functions executed on return
     *
     * @return void
     * @throws \Weird\Exceptions\ProcessFailed
     * @throws \Weird\Exceptions\ProcessSpawnFailed
     */
    public function testExecutePromise(): void
    {
        $manager = ProcessManager::create()
            ->withBootstrap($this->bootstrapFile)
            ->spawn(Thread::class);

        // Test with scoping
        $manager->dispatch(
            Promise::make(function () {
                return 'working';
            })
                ->then(function (string $output) {
                    $this->assertEquals('working', $output);
                })
        );

        $manager->wait();

        // Test with pass by reference
        $result = null;
        $manager->dispatch(
            Promise::make(function () {
                return 'working';
            })
                ->then(function (string $output) use (&$result) {
                    $result = $output;
                })
        );

        $manager->wait();

        $this->assertEquals('working', $result);
    }

    /**
     * Validate that non-promise executables can be executed
     *
     * @return void
     * @throws \Weird\Exceptions\ProcessFailed
     * @throws \Weird\Exceptions\ProcessSpawnFailed
     */
    public function testExecuteCallable(): void
    {
        $manager = ProcessManager::create()
            ->withBootstrap($this->bootstrapFile)
            ->spawn(Thread::class);

        // Test anonymous dispatch
        $manager->dispatch(function () {
            file_put_contents(__DIR__ . '/test.txt', 'hello');
        });

        $manager->wait();

        $this->assertTrue(file_exists(__DIR__ . '/test.txt'));
        $this->assertEquals('hello', file_get_contents(__DIR__ . '/test.txt'));

        unlink(__DIR__ . '/test.txt');
    }

    /**
     * Validate that registered hint handlers are executed when a hint is returned by a process
     *
     * @return void
     * @throws \Weird\Exceptions\ProcessFailed
     * @throws \Weird\Exceptions\ProcessSpawnFailed
     */
    public function testHinting(): void
    {
        $manager = ProcessManager::create()
            ->withBootstrap($this->bootstrapFile)
            ->spawn(Thread::class);

        $manager->registerHintHandler(function (mixed $message) {
            $this->assertEquals('test', $message);
        });

        // Test with scoping
        $manager->dispatch(
            Promise::make(function () {
                \Weird\Messages\Events\Hint::send('test');
                return 'working';
            })
                ->then(function (string $output) {
                    $this->assertEquals('working', $output);
                })
        );

        $manager->wait();
    }

    /**
     * Validate that registered unknown message handlers are executed when a hint is returned by a process
     *
     * @return void
     * @throws \Weird\Exceptions\ProcessFailed
     * @throws \Weird\Exceptions\ProcessSpawnFailed
     */
    public function testUnknownMessage(): void
    {
        $manager = ProcessManager::create()
            ->withBootstrap($this->bootstrapFile)
            ->spawn(Thread::class);

        $manager->registerUnknownMessageHandler(function (mixed $message) {
            $this->assertEquals('test', $message);
        });

        // Test with scoping
        $manager->dispatch(
            Promise::make(function () {
                echo 'test';
                return 'working';
            })
                ->then(function (string $output) {
                    $this->assertEquals('working', $output);
                })
        );

        $manager->wait();
    }
}
