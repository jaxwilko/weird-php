# WeirdPhp

This project aims to add async support to php via dispatching tasks across multiple processes.

## Install

```bash
composer require jaxwilko/weird-php
```

## Usage

By default, Weird works with the `Promise` class, you can also pass callable arguments however you will not receive 
output from them.

```php
use Weird\Promise;

Promise::make(function (Thread $thread) {
    // do some really cool stuff
    $result = 'Hello world';
    return $result;
})
    ->then(function (string $result) {
        echo $result;
        // Prints: Hello World
    })
    ->catch(function (\Throwable $e) {
        // This will catch any exceptions happening parent process, not the child process.
    })
```

Using the `ProcessManager` we can dispatch multiple processes across as many processes as we want.

```php
use Weird\ProcessManager;
use Weird\Processes\Thread;
use Weird\Promise;

$manager = ProcessManager::create()
    ->spawn(Thread::class, processes: 1);

$manager->dispatch([
    // Promise 1
    Promise::make(function () {
        return 'hello';
    })->then(fn ($str) => $str . ' world')
        ->then(fn ($str) => echo $str . PHP_EOL),

    // Promise 2
    Promise::make(function () {
        return 'world';
    })->then(fn ($str) => $str . ' hello')
        ->then(fn ($str) => echo $str . PHP_EOL)
]);
```

> When a new process is created, it loses access to the current global scope, meaning that things such as your
> autoloader will not be loaded, by default Weird will attempt to load your composer autoload file if found.
> Alternatively you can provide a file to register your autoloader via the `withBootstrap()` method.

```php
$manager = ProcessManager::create()
    ->withBootstrap(__DIR__ . '/vendor/autoload.php')
    ->spawn(Thread::class);
```

Because these processes are being ran async, if you want them to be handled you need to tell the process manager 
when to check up on them. To wait until all active processes have completed, call `wait()`. Wait also takes a timeout 
argument which is in seconds as a float.

```php
$manager = ProcessManager::create()
    ->spawn(Thread::class, processes: 1);

$manager->dispatch(function () {
    // do something
});

$manager->wait(timeout: 0.5);
```

Alternatively, you can manually call the `tick()` function, which will check for promises being returned by child 
processes and process them.

```php
$manager = ProcessManager::create()
    ->spawn(Thread::class, processes: 1);

$manager->dispatch(
    Promise::make(function () {
        // do something
    })->then(function (string $output) {
        // do something else
    })
);

while ($doingSomethingElse) {
    // ...
    $manager->tick();
    // ...
}
```

### Hints

`Hint` is a special message that can be sent from a process to give you feedback about what is going on.

To send a `Hint`, you can call the method `\Weird\Messages\Events\Hint::send($message)`.

A `Hint` handler can be registered by calling `registerHintHandler()` on the `ProcessManager` object.

```php
$manager->registerHintHandler(function (mixed $message, Process $process) {
    echo $message;
});
```

### UnknownMessage

Due to how Weird listens to output streams, any data sent via outputting directly to the buffer will be captured as
an `UnknownMessage`. These can be listened for via calling `registerUnknownMessageHandler()` on the `ProcessManager`
object.

```php
$manager->registerUnknownMessageHandler(function (mixed $message, Process $process) {
    echo $message;
});
```

## Processes

Included in Weird is the `Thread` process, this allows you to execute multiple async php operations. Most of this
documentation outlines the usage of `Thread`, however you can implement your own `ParallelProcess` class and
spawn it with the `ProcessManager`.

For example:
```php
class MyCustomProcess extends \Weird\Processes\ParallelProcess
{
    // Define how often tick should be executed in seconds
    protected float $tickRate = 1 / 32;

    public function register()
    {
        // execute startup tasks
    }

    public function read($stdin)
    {
        while ($message = $this->readStream($stdin)) {
            // do something with messages sent from the parent process
        }
    }

    public function tick()
    {
        // do something
    }
}

// ...

$manager = ProcessManager::create()
    ->spawn(MyCustomProcess::class, processes: 5);
```