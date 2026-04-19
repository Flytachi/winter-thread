# 4. Debugging and Output Handling

`Winter Thread` provides a flexible system for handling output from background processes.
The behavior is controlled by two parameters of `start()`: `$debugMode` and `$outputTarget`.

> **Why `/dev/null` is the default**
>
> When a parent process opens a pipe (`$outputTarget = null`) but never reads from it,
> the OS buffer (~64 KB) fills up, the child process blocks on `write()`, and eventually
> receives a **Broken pipe** error. This silently kills background jobs.
>
> The default `$outputTarget = '/dev/null'` prevents this entirely: output is discarded
> by the OS without buffering, and the parent needs no lifecycle management of the process.
> Pass `null` explicitly only when you actively read output via `readOutput()` / `readError()`.

---

## Strategy 1: Fire and Forget (default)

The safest mode for production background jobs. Output is discarded. No pipe is opened,
so the parent process can start a task and immediately release the `Thread` object.

- **`start()`** — equivalent to `start([], false, '/dev/null')`
- **`$debugMode`**: `false` — PHP errors suppressed in the child.
- **`$outputTarget`**: `'/dev/null'` — output discarded by the OS.

```php
<?php
require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

$thread = new Thread(new class implements Runnable {
    public function run(array $args): void {
        // Heavy task — output is discarded by default
        file_put_contents('/tmp/result.json', json_encode(['done' => true]));
    }
});

$pid = $thread->start(); // safe fire-and-forget
echo "Started background process PID: $pid\n";
// Thread object can be released here; no Broken pipe risk
```

---

## Strategy 2: Log to File

The recommended approach for staging and production when you need a record of what
happened. All output (`echo`, `var_dump`, PHP errors) is appended to the specified file.

- **`start(true, '/path/to/file.log')`**
- **`$debugMode`**: `true` — PHP errors enabled and visible in the log.
- **`$outputTarget`**: a file path string — output appended to the file.

```php
<?php
require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

$thread = new Thread(new class implements Runnable {
    public function run(array $args): void {
        echo "Task started at: " . date('Y-m-d H:i:s') . PHP_EOL;
        echo "Processing..." . PHP_EOL;
        // A warning — visible in the log because debugMode is true
        $result = 10 / 0;
    }
});

$logFile = __DIR__ . '/worker.log';
$pid = $thread->start(debugMode: true, outputTarget: $logFile);
echo "PID: $pid — logging to {$logFile}\n";
```

**Reading the log:**

```bash
tail -f worker.log
```

**Expected content:**

```
Task started at: 2025-12-19 10:30:00
Processing...
Warning: Division by zero in ... on line XX
```

---

## Strategy 3: Interactive / Pipe Mode

For local development and debugging. The parent reads the child's output in real time.
You **must** pass `null` explicitly and **must** actively poll `readOutput()` / `readError()`
while the process is alive — otherwise the pipe buffer fills and causes a Broken pipe.

- **`start(true, null)`**
- **`$debugMode`**: `true` — PHP errors enabled.
- **`$outputTarget`**: `null` — output piped to the parent process.

```php
<?php
require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

$thread = new Thread(new class implements Runnable {
    public function run(array $args): void {
        for ($i = 1; $i <= 3; $i++) {
            echo "Step {$i}..." . PHP_EOL;
            sleep(1);
        }
        trigger_error("Custom warning for demo", E_USER_WARNING);
    }
});

$pid = $thread->start(debugMode: true, outputTarget: null);
echo "Interactive session started, PID: $pid\n\n";

// IMPORTANT: actively drain the pipe while the process runs
while ($thread->isAlive()) {
    $out = $thread->readOutput();
    if ($out !== '') {
        echo '[STDOUT] ' . rtrim($out) . "\n";
    }
    $err = $thread->readError();
    if ($err !== '') {
        echo '[STDERR] ' . rtrim($err) . "\n";
    }
    usleep(250_000);
}

// Drain remaining output after process exits
$out = $thread->readOutput();
if ($out !== '') {
    echo '[STDOUT] ' . rtrim($out) . "\n";
}
$err = $thread->readError();
if ($err !== '') {
    echo '[STDERR] ' . rtrim($err) . "\n";
}

$exitCode = $thread->join();
echo "\nProcess $pid finished with exit code: $exitCode\n";
```

**Expected output (appearing gradually):**

```
Interactive session started, PID: 12345

[STDOUT] Step 1...
[STDOUT] Step 2...
[STDOUT] Step 3...
[STDERR] Warning: Custom warning for demo in ... on line XX

Process 12345 finished with exit code: 0
```

---

## Summary

| Mode                   | `$outputTarget`   | `$debugMode` | Use case                              |
|------------------------|-------------------|--------------|---------------------------------------|
| Fire and forget        | `'/dev/null'` (default) | `false` | Production background jobs            |
| Log to file            | `'/path/file.log'`| `true`       | Staging / production with audit trail |
| Interactive pipe       | `null` (explicit) | `true`       | Local dev, real-time output reading   |
