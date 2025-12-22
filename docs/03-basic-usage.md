# 3. Basic Usage

The core workflow of Winter Thread involves creating a task, wrapping it in a `Thread` object, and starting it.

## Step 1: Create a Runnable Task

First, create a class that implements the `Runnable` interface. The entire logic of your background task must be contained within the `run()` method.

**Important**: The `Runnable` object will be serialized and passed to another process. Therefore, it cannot contain non-serializable properties like database connections, file handles, or other resources. All such resources must be initialized inside the `run()` method.

```php
<?php

use Flytachi\Winter\Thread\Runnable;

class ReportGenerator implements Runnable
{
    private int $reportId;

    public function __construct(int $reportId)
    {
        $this->reportId = $reportId;
    }

    public function run(array $args): void
    {
        // Initialize resources inside the run() method
        $db = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');

        echo "Generating report #{$this->reportId}...\n";
        $reportData = $db->query("SELECT ... WHERE id = {$this->reportId}")->fetchAll();

        // Simulate a long-running task
        sleep(15);

        file_put_contents(
            "report-{$this->reportId}.json",
            json_encode($reportData)
        );

        echo "Report #{$this->reportId} generated.\n";
    }
}
```

## Step 2: Create and Start the Thread

Now, in your main application script, create an instance of your task and pass it to the Thread constructor.
The start() method launches the process in the background and immediately returns its Process ID (PID), 
allowing your main script to continue its execution without waiting.

```php
<?php

require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Thread;

$task = new ReportGenerator(42);
$thread = new Thread($task);

$pid = $thread->start();

echo "Main Script: Report generation for #42 has been started in the background (PID: $pid).\n";
echo "Main Script: Now doing other important work...\n";

// The main script is not blocked and can perform other operations.
```

## Step 3: Wait for Completion (Optional)

If you need to wait for the background task to finish, use the join() method. This will block 
your main script's execution until the child process terminates.

The join() method returns the exit code of the child process (typically 0 for success).

```php
// ... continuing from the previous example

// After doing other work, we now need to wait for the report.
echo "Main Script: Waiting for the report to be finalized...\n";

$exitCode = $thread->join();

if ($exitCode === 0) {
    echo "Main Script: Background task completed successfully.\n";
} else {
    echo "Main Script: Background task failed with exit code: $exitCode.\n";
}
```
You can also provide a timeout to join() to prevent waiting indefinitely:

```php
// Wait for a maximum of 30 seconds
$exitCode = $thread->join(30);

if ($exitCode === null) {
    echo "Main Script: Timeout reached! The task is still running.\n";
    // You might want to terminate it forcefully
    $thread->kill();
}
```