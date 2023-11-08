<?php

use PHPUnit\Framework\TestCase;
use Weird\ProcessManager;
use Weird\Processes\Thread;
use Weird\Promise;

class ProcessManagerTest extends TestCase
{
    protected string $bootstrapFile = __DIR__ . '/bootstrap.php';

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
}
