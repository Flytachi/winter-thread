# 3. Basic Usage

The core workflow of Winter Thread: define a task, wrap it in a `Thread`, start it.

---

## Step 1: Create a Runnable Task

Implement the `Runnable` interface. All task logic goes inside `run()`.

**Critical constraint:** the `Runnable` object is serialized and passed to the child process
via stdin. Properties must not contain non-serializable values such as database connections,
file handles, or sockets. Create those resources *inside* `run()`.

```php
<?php

use Flytachi\Winter\Thread\Runnable;

class ReportGenerator implements Runnable
{
    public function __construct(private int $reportId) {}

    public function run(array $args): void
    {
        // Initialize resources here, not in the constructor
        $db = new PDO('mysql:host=localhost;dbname=mydb', 'user', 'pass');

        $rows = $db->query("SELECT * FROM orders WHERE report_id = {$this->reportId}")
                   ->fetchAll();

        file_put_contents(
            "/tmp/report-{$this->reportId}.json",
            json_encode($rows)
        );
    }
}
```

---

## Step 2: Start the Thread

Create a `Thread` with your task and call `start()`. The method returns immediately with
the child process PID — your main script is never blocked.

By default, output from the child goes to `/dev/null`. This is the safest option for
background jobs: no pipe is opened, so no **Broken pipe** risk regardless of how long the
job runs or whether the parent stays alive.

```php
<?php

require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Thread;

$thread = new Thread(
    new ReportGenerator(42),
    'Billing',        // namespace  — appears in `ps` output
    'ReportGenerator', // name
    'report-42'       // tag
);

$pid = $thread->start(); // non-blocking; output goes to /dev/null by default
echo "Report generation started in background (PID: $pid)\n";

// Main script continues immediately
doOtherWork();
```

---

## Step 3: Wait for Completion (Optional)

If you need the result before continuing, call `join()`. It blocks until the child exits
and returns the exit code (`0` = success, non-zero = failure).

```php
$exitCode = $thread->join();

if ($exitCode === 0) {
    echo "Report ready.\n";
} else {
    echo "Report failed (exit code: $exitCode).\n";
}
```

To avoid waiting indefinitely, pass a timeout in seconds:

```php
$exitCode = $thread->join(timeout: 30);

if ($exitCode === null) {
    echo "Timed out — task is still running.\n";
    $thread->kill(); // force-stop if needed
}
```

---

## Step 4: Pass Custom Arguments (Optional)

Pass an associative array to `start()`. Arguments become available in `run()` via `$args`.

Rules:
- `'key' => 'value'` → `$args['key'] === 'value'`
- `'flag' => true` → `$args['flag'] === true` (valueless flag)
- `'skip' => false` or `'skip' => null` → not included in `$args`

```php
class ExportTask implements Runnable
{
    public function run(array $args): void
    {
        $format  = $args['format']  ?? 'csv';
        $dryRun  = isset($args['dry-run']);
        $userId  = $args['user-id'] ?? null;

        echo "Exporting as $format" . ($dryRun ? ' (dry run)' : '') . "\n";
    }
}

$thread = new Thread(new ExportTask());
$thread->start([
    'format'  => 'json',
    'user-id' => '42',
    'dry-run' => true,
]);
$thread->join();
```

---

## Step 5: Log Output to a File (Optional)

For tasks that produce output you want to keep, pass a file path as `$outputTarget`.
Output is appended — safe to reuse the same file across multiple jobs.

```php
$thread->start(
    debugMode:    true,              // enable PHP error reporting in child
    outputTarget: '/var/log/app/reports.log'
);
```

See [4. Debugging and Output Handling](04-debugging-and-output.md) for all output modes.

---

## Full Example

```php
<?php

require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

class VideoProcessor implements Runnable
{
    public function __construct(private string $file) {}

    public function run(array $args): void
    {
        $quality = $args['quality'] ?? 'high';
        // ... encoding logic
        file_put_contents("/tmp/{$this->file}.done", "quality=$quality");
    }
}

$thread = new Thread(new VideoProcessor('movie.mp4'), 'Media', 'VideoProcessor', 'job-7');
$pid    = $thread->start(['quality' => 'hd'], outputTarget: '/var/log/app/video.log');

echo "Encoding started (PID: $pid). Doing other work...\n";
sleep(2);

$exitCode = $thread->join(timeout: 60);

if ($exitCode === null) {
    echo "Encoding is taking too long, killing...\n";
    $thread->kill();
} elseif ($exitCode === 0) {
    echo "Encoding complete.\n";
} else {
    echo "Encoding failed (code: $exitCode). Check /var/log/app/video.log\n";
}
```
