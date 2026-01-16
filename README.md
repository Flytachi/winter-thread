# Winter Thread: A Modern Process Control Library for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-thread.svg)](https://packagist.org/packages/flytachi/winter-thread)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-thread.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-thread)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

**Winter Thread** provides a clean, object-oriented API for running and controlling background processes in PHP, simulating a traditional threading model for parallel and long-running tasks.

It abstracts away the complexities of `proc_open` and `posix` signals into a powerful and easy-to-use interface.

## Key Features

- 🚀 **Fluent, Object-Oriented API**: Manage background processes as objects.
- ⏯️ **Full Process Control**: `start()`, `join()`, `pause()`, `resume()`, `terminate()`, and `kill()`.
- 🏷️ **Advanced Process Naming**: Identify your processes easily with namespaces, names, and tags.
- 🔒 **Secure by Default**: Optional signed serialization for closures via `opis/closure`.
- 🧩 **Extensible**: Easily override the runner script for deep framework integration.
- ☕ **Java-like API**: Familiar method names like `isAlive()` and `join()` for an easy learning curve.

## Requirements

- PHP >= 8.1
- `ext-pcntl`
- `ext-posix`
- (Optional) `opis/closure` for serializing closures.

## Installation

```bash
composer require flytachi/winter-thread
```

## Quick Start

Here's how easy it is to run a long-running task in the background without blocking your main script.

```php
<?php

require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

// 1. Define your task by implementing the Runnable interface.
//    The logic inside run() will be executed in a separate process.
class VideoProcessingTask implements Runnable {
    private string $videoFile;

    public function __construct(string $videoFile) {
        $this->videoFile = $videoFile;
    }

    public function run(array $args): void {
        echo "Child Process (PID: " . getmypid() . "): Starting processing for {$this->videoFile}...\n";
        sleep(10); // Simulate a long-running encoding job
        echo "Child Process (PID: " . getmypid() . "): Finished processing {$this->videoFile}.\n";
    }
}

echo "Main Script (PID: " . getmypid() . "): Starting application.\n";

// 2. Create a new Thread instance with your task.
//    You can also provide metadata for process identification.
$thread = new Thread(
    new VideoProcessingTask('movie.mp4'),
    'ETL',          // Namespace
    'ProcessVideo', // Name
    'job-42'        // Tag
);

// 3. Start the thread. This immediately returns the child process PID.
$pid = $thread->start();

echo "Main Script: Video processing started in background (PID: $pid).\n";

// The main script can continue doing other work here...
echo "Main Script: Doing other work while video is processing...\n";
sleep(2);

// 4. (Optional) Wait for the thread to finish and get its exit code.
//    This will block the main script until the child process completes.
echo "Main Script: Waiting for the task to complete...\n";
$exitCode = $thread->join();
echo "Main Script: Task completed with exit code: $exitCode.\n";
```

## Documentation

For detailed information on advanced features, process control, 
configuration, and examples, please see our full documentation 
in the [/docs](docs) directory. (You can create this directory later)

## Contributing
Contributions are welcome! Please feel free to submit a pull request 
or create an issue for bugs, questions, or feature requests.


## License
This library is open-source software licensed under the [MIT license](LICENSE).