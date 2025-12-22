# 4. Debugging and Output Handling

`Winter-Thread` provides a powerful and flexible system for handling the output of your background processes, making debugging intuitive and straightforward. The behavior is controlled by the two arguments passed to the `start()` method: `$debugMode` and `$outputTarget`.

There are three primary debugging strategies you can use.

---

### Strategy 1: Silent Mode (Fire and Forget)

This is the default mode, ideal for production environments where you only care about the task's completion, not its output.

-   **`start(false, null)`**
-   **`$debugMode`**: `false` (PHP errors are suppressed).
-   **`$outputTarget`**: `null` (Output is piped but immediately discarded by the parent).

This mode is optimized for performance and silence. The child process runs completely detached, and its output does not consume resources.

**Example:**

```php
<?php
// main_script.php
require 'vendor/autoload.php';

$thread = new \Flytachi\Winter\Thread\Thread(
    new class implements \Flytachi\Winter\Thread\Runnable {
        public function run(array $args): void {
            // This output will be completely ignored.
            echo "Processing a heavy task...";
            error_log("This will also be ignored.");
        }
    }
);

$pid = $thread->start(false, null); // Or just $thread->start();
echo "Started silent background process with PID: $pid\n";
// The main script can now exit.
```

**Expected Output:**

```
Started silent background process with PID: 12345
```
*(Nothing else will be displayed. The child process output is discarded.)*

---

### Strategy 2: Logging to a File

This is the most common method for debugging in staging or production. All output, including `echo`, `var_dump`, and any PHP errors, is appended to a specified log file.

-   **`start(true, '/path/to/your.log')`**
-   **`$debugMode`**: `true` (PHP errors are enabled and reported).
-   **`$outputTarget`**: A file path string.

**Example:**

Let's create a task that produces both standard output and a PHP warning.

```php
<?php
// main_script.php
require 'vendor/autoload.php';

$thread = new \Flytachi\Winter\Thread\Thread(
    new class implements \Flytachi\Winter\Thread\Runnable {
        public function run(array $args): void {
            echo "Task started at: " . date('Y-m-d H:i:s') . PHP_EOL;
            echo "Performing calculations..." . PHP_EOL;
            // This will trigger a warning
            $result = 10 / 0;
        }
    }
);

$logFile = __DIR__ . '/debug.log';
$pid = $thread->start(true, $logFile);

echo "Started process PID: $pid. Output is being logged to {$logFile}\n";
```

**How to Check the Logs:**

Open your terminal and use `cat` or `tail` to view the log file.

```bash
# Wait a moment for the process to run, then:
cat debug.log
```

**Expected Content of `debug.log`:**

```
Task started at: 2025-12-19 10:30:00
Performing calculations...

Warning: Division by zero in /path/to/your/main_script.php on line XX
```

---

### Strategy 3: Interactive Debugging (Live Output)

This is the most powerful mode for local development. It allows the parent process to read the child's output in real-time as it's being generated.

-   **`start(true, null)`**
-   **`$debugMode`**: `true` (PHP errors are enabled).
-   **`$outputTarget`**: `null` (Output is piped to the parent process).

To make this work, the parent script must actively poll the `Thread` object for new output while the process is alive.

**Example:**

```php
<?php
// main_script.php
require 'vendor/autoload.php';

$thread = new \Flytachi\Winter\Thread\Thread(
    new class implements \Flytachi\Winter\Thread\Runnable {
        public function run(array $args): void {
            for ($i = 1; $i <= 3; $i++) {
                echo "Processing item {$i}..." . PHP_EOL;
                sleep(1);
            }
            // Trigger an error
            trigger_error("A custom error occurred", E_USER_WARNING);
        }
    }
);

$pid = $thread->start(true, null);
echo "Started interactive debug session for PID: $pid\n\n";

// Poll for output while the thread is alive
while ($thread->isAlive()) {
    $output = $thread->readOutput();
    if (!empty($output)) {
        echo "[STDOUT] " . rtrim($output) . "\n";
    }

    $error = $thread->readError();
    if (!empty($error)) {
        echo "[STDERR] " . rtrim($error) . "\n";
    }
    usleep(250000); // Poll every 0.25 seconds
}

// Read any final output after the process has finished
$finalError = $thread->readError();
if (!empty($finalError)) {
    echo "[STDERR] " . rtrim($finalError) . "\n";
}

$exitCode = $thread->join();
echo "\nProcess $pid finished with exit code: $exitCode\n";
```

**Expected Output (will appear gradually):**

```
Started interactive debug session for PID: 12345

(approx. 1 second later)
[STDOUT]: # "Processing item 1..."

(approx. 1 second later)
[STDOUT]: # "Processing item 2..."

(approx. 1 second later)
[STDOUT]: # "Processing item 3..."
[STDERR]: # "Warning: A custom error occurred in /path/to/your/main_script.php on line XX"

Process 12345 finished with exit code: 0
```
