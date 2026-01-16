<?php

namespace Flytachi\Winter\Thread;

use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * Manages and controls a single, independent task running in a separate PHP process.
 *
 * This class provides a high-level, object-oriented API for creating and managing
 * background processes in PHP. It abstracts away the complexities of `proc_open`,
 * process signaling (`posix`), and data serialization.
 *
 * It simulates a "thread-like" behavior by executing a `Runnable` task in a
 * completely isolated child process, making it ideal for long-running, parallel,
 * or blocking operations without blocking the main application.
 *
 * ---
 * ### Example 1: Using a dedicated class
 *
 * ```
 * php
 * class MyTask implements Runnable {
 *     public function run(): void {
 *         // Long-running task, e.g., video processing
 *         sleep(10);
 *     }
 * }
 *
 * $thread = new Thread(new MyTask(), 'Processing', 'VideoTask', 'job-123');
 * $pid = $thread->start();
 * echo "Task started with PID: $pid\n";
 *
 * // Wait for the task to complete
 * $exitCode = $thread->join();
 * echo "Task finished with exit code: $exitCode\n";
 * ```
 *
 * ---
 * ### Example 2: Using an anonymous class (if Opis/Closure is installed)
 *
 * ```
 * php
 * // This requires the 'opis/closure' package to be installed.
 * define('WINTER_THREAD_SECRET', 'your-secret-key');
 *
 * $thread = new Thread(new class implements Runnable {
 *     public function run(): void {
 *         file_put_contents('output.log', 'Email batch sent at ' . date('Y-m-d H:i:s'));
 *     }
 * });
 *
 * $thread->start();
 * // The main script can continue without waiting
 * ```
 * ---
 *
 * Key features:
 * - Executes any `Runnable` task in the background.
 * - Provides a rich API for process control (start, join, pause, resume, terminate, kill).
 * - Supports optional, secure serialization of closures via Opis/Closure.
 * - Enables process identification in the OS via customizable process titles.
 *
 * @package Flytachi\Winter\Thread
 * @version 1.0
 * @author Flytachi
 * @see \Flytachi\Winter\Thread\Runnable
 * @see \Flytachi\Winter\Thread\ThreadException
 */
final class Thread
{
    /**
     * The task to be executed in the separate process.
     * Must be an object that implements the Runnable interface.
     * @var Runnable
     */
    private Runnable $runnable;

    /**
     * The Process ID (PID) of the child process.
     * This value is null until the start() method is successfully called.
     * @var int|null
     */
    private ?int $pid = null;

    /**
     * A logical grouping for the process, used for identification and monitoring.
     * @var string
     */
    private string $namespace;

    /**
     * The specific name of the task being executed.
     * Used for process identification. e.g.
     * @var string
     */
    private string $name;

    /**
     * An optional, user-defined tag for distinguishing between instances of the same task.
     * @var string|null
     */
    private ?string $tag = null;

    /**
     * The internal resource handle for the running process, returned by proc_open().
     * This handle is used to get the status of and close the process.
     * It should not be manipulated directly.
     * @var resource|null
     */
    private $processHandle = null;

    /**
     * @var resource[]
     */
    private array $processPipes = [];

    /**
     * The path to the runner script that executes the task.
     * Can be overridden via the bindRunner() static method.
     * @var string
     */
    private static string $runnerScriptPath;


    /**
     * Constructs a new Thread instance.
     *
     * @param Runnable $runnable The task object to be executed in the new process.
     * @param string $namespace A logical grouping for the process (e.g., "Billing", "Notifications").
     * @param string|null $name The specific name for this task. If null, it will be auto-generated
     *                          from the Runnable's class name.
     * @param string|null $tag An optional tag to distinguish this specific process instance
     *                         (e.g., "user-123", "batch-5").
     */
    public function __construct(
        Runnable $runnable,
        string $namespace = '',
        ?string $name = null,
        ?string $tag = null
    ) {
        $this->runnable = $runnable;
        $this->namespace = $namespace;
        $this->tag = $tag;
        if ($name === null) {
            $reflection = new \ReflectionClass($runnable);
            if ($reflection->isAnonymous()) {
                $this->name = 'anonymous';
            } else {
                $this->name = $reflection->getShortName();
            }
        } else {
            $this->name = $name;
        }
    }

    /**
     * Gets the Process ID (PID) of the child process.
     *
     * @return int|null The PID, or null if the process has not been started.
     */
    public function getPid(): ?int
    {
        return $this->pid;
    }

    /**
     * Gets the namespace of the process.
     *
     * @return string The logical namespace.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Gets the name of the task.
     *
     * @return string The specific name of the task.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the optional tag of the process instance.
     *
     * @return string|null The user-defined tag, or null if not set.
     */
    public function getTag(): ?string
    {
        return $this->tag;
    }

    /**
     * Starts the execution of the Runnable task in a new background process.
     *
     * This method serializes the Runnable object, launches a new PHP process,
     * and passes the serialized task to it for execution. It offers flexible
     * options for handling the output and passing custom arguments for each run.
     *
     * @param array<string, scalar|null> $arguments Custom arguments for this specific run. These are passed
     *                                              to the child process and can be read using getopt()
     *                                              within the Runnable's run() method (prefixed with 'arg-').
     * @param bool                       $debugMode If true, enables debug mode. PHP errors from the child
     *                                              process are reported, and output is piped to the parent
     *                                              for real-time reading.
     * @param string|null                $outputTarget The destination for the process's standard output and error.
     *                                              - `null` (default): Output is piped to the parent process,
     *                                                allowing for real-time capture.
     *                                              - `'/path/to/file.log'`: Output is appended to the specified file.
     *
     * @return int The Process ID (PID) of the newly created background process.
     *
     * @throws ThreadException If the process fails to start, for example, due to system
     *                         resource limits or incorrect permissions.
     */
    public function start(array $arguments = [], bool $debugMode = false, ?string $outputTarget = null): int
    {
        if (function_exists('\Opis\Closure\serialize')) {
            $payload = \Opis\Closure\serialize(
                $this->runnable,
                self::getSerSecurity()
            );
        } else {
            $payload = serialize($this->runnable);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin - pipe to write to
        ];

        if ($outputTarget !== null) {
            $descriptorSpec[1] = ['file', $outputTarget, 'a'];
            $descriptorSpec[2] = ['file', $outputTarget, 'a'];
        } else {
            $descriptorSpec[1] = ['pipe', 'w']; // stdout - pipe to read from
            $descriptorSpec[2] = ['pipe', 'w']; // stderr
        }

        $command = $this->buildCommand($arguments, $debugMode);

        $this->processHandle = proc_open($command, $descriptorSpec, $this->processPipes);

        if (!is_resource($this->processHandle)) {
            throw new ThreadException('Failed to start the process using proc_open.');
        }

        fwrite($this->processPipes[0], $payload);
        fclose($this->processPipes[0]);

        // If we output to channels, set them to non-blocking mode.
        if ($outputTarget === null) {
            stream_set_blocking($this->processPipes[1], false);
            stream_set_blocking($this->processPipes[2], false);
        }

        $status = proc_get_status($this->processHandle);
        if (!$status || $status['running'] !== true) {
            $this->closePipes();
            proc_close($this->processHandle);
            $this->processHandle = null;
            throw new ThreadException('Process failed to start or terminated immediately.');
        }

        $this->pid = $status['pid'];
        return $this->pid;
    }

    /**
     * Checks if the child process is currently running.
     *
     * This method checks the status of the process handle returned by proc_open().
     * It does not send any signals to the process.
     *
     * @return bool True if the process is running, false otherwise (e.g., it has terminated,
     *              was never started, or the handle is invalid).
     */
    public function isAlive(): bool
    {
        if (!is_resource($this->processHandle)) {
            return false;
        }

        $status = proc_get_status($this->processHandle);
        return $status['running'];
    }

    /**
     * Waits for the child process to complete its execution.
     *
     * This method blocks the execution of the current script until the child process
     * has finished. It is the equivalent of Java's `Thread.join()`.
     *
     * @param int $timeout The maximum number of seconds to wait for the process to terminate.
     *                     If 0, it will wait indefinitely. Default: 0.
     *
     * @return int|null The exit code of the child process (e.g., 0 for success).
     *                  Returns null if the timeout is reached before the process terminates.
     *                  Returns -1 if the process was already terminated before the call.
     */
    public function join(int $timeout = 0): ?int
    {
        if (!is_resource($this->processHandle)) {
            return -1;
        }

        $startTime = time();

        while (true) {
            $status = proc_get_status($this->processHandle);

            if (!$status['running']) {
                $this->closePipes();
                proc_close($this->processHandle);
                $this->processHandle = null;
                return $status['exitcode'];
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                return null;
            }

            usleep(50_000); // 0.05 seconds
        }
    }

    /**
     * Reads the standard output (STDOUT) from the process.
     * This method only works if the thread was started with $outputTarget = null.
     *
     * @return string The content from STDOUT since the last read.
     */
    public function readOutput(): string
    {
        if (isset($this->processPipes[1])) {
            return (string) stream_get_contents($this->processPipes[1]);
        }
        return '';
    }

    /**
     * Reads the standard error (STDERR) from the process.
     * This method only works if the thread was started with $outputTarget = null.
     *
     * @return string The content from STDERR since the last read.
     */
    public function readError(): string
    {
        if (isset($this->processPipes[2])) {
            return (string) stream_get_contents($this->processPipes[2]);
        }
        return '';
    }

    /**
     * Pauses the execution of the child process by sending a SIGSTOP signal.
     *
     * This is a "hard" pause that cannot be ignored by the child process.
     * The process can be resumed later using the resume() method.
     * Note: This functionality requires the `posix` PHP extension.
     *
     * @return bool True if the signal was sent successfully, false otherwise
     *              (e.g., the process is not running, or the `posix` extension is not available).
     */
    public function pause(): bool
    {
        if ($this->isAlive() && $this->pid) {
            return posix_kill($this->pid, SIGSTOP);
        }
        return false;
    }

    /**
     * Resumes the execution of a paused (via SIGSTOP) process by sending a SIGCONT signal.
     *
     * Note: This functionality requires the `posix` PHP extension.
     *
     * @return bool True if the signal was sent successfully, false otherwise
     *              (e.g., the process is not running, or the `posix` extension is not available).
     */
    public function resume(): bool
    {
        if ($this->isAlive() && $this->pid) {
            return posix_kill($this->pid, SIGCONT);
        }
        return false;
    }

    /**
     * Sends an interrupt signal (SIGINT) to the child process.
     *
     * This is typically equivalent to pressing Ctrl+C in the terminal.
     * Like SIGTERM, this is a "soft" signal that the process can catch and handle.
     * Note: This functionality requires the `posix` PHP extension.
     *
     * @return bool True if the signal was sent successfully, false otherwise.
     */
    public function interrupt(): bool
    {
        if ($this->isAlive() && $this->pid) {
            return posix_kill($this->pid, SIGINT);
        }
        return false;
    }

    /**
     * Requests the child process to terminate gracefully by sending a SIGTERM signal.
     *
     * This is a "soft" termination request. The child process can catch this signal
     * to perform cleanup operations (e.g., close files, save state) before exiting.
     * If the process ignores this signal, it will continue to run.
     * Note: This functionality requires the `posix` PHP extension.
     *
     * @return bool True if the signal was sent successfully, false otherwise.
     */
    public function terminate(): bool
    {
        if ($this->isAlive() && $this->pid) {
            return posix_kill($this->pid, SIGTERM);
        }
        return false;
    }

    /**
     * Forcefully terminates the child process by sending a SIGKILL signal.
     *
     * WARNING: This is a "hard" kill. The signal cannot be caught, blocked, or ignored
     * by the child process. The process will be terminated immediately by the OS,
     * without any chance for cleanup (e.g., saving data or closing connections).
     * Use this as a last resort when terminate() or interrupt() fail.
     * Note: This functionality requires the `posix` PHP extension.
     *
     * @return bool True if the signal was sent successfully, false otherwise.
     */
    public function kill(): bool
    {
        if ($this->isAlive() && $this->pid) {
            return posix_kill($this->pid, SIGKILL);
        }
        return false;
    }

    /**
     * Constructs the complete command-line string to execute the runner script.
     *
     * This internal method assembles the full command, including:
     * 1. The PHP executable path.
     * 2. The path to the runner script.
     * 3. System arguments for process identification (--namespace, --name, --tag).
     * 4. The debug flag (--debug), if enabled.
     * 5. Any custom user-provided arguments for the specific run (prefixed with --arg-).
     *
     * All arguments are properly escaped to prevent shell injection vulnerabilities.
     *
     * @param array<string, scalar|null> $arguments An associative array of custom arguments for this specific run.
     *                                              Keys become argument names, and values become their values.
     *                                              A value of `true` creates a valueless flag.
     * @param bool                       $debugMode If true, the --debug flag is added, enabling
     *                                              detailed error reporting in the child process.
     *
     * @return string The fully constructed and escaped command string, ready for execution.
     */
    private function buildCommand(array $arguments, bool $debugMode): string
    {
        $phpExecutable = PHP_BINARY ?: 'php';
        $runnerScript = self::getRunnerScriptPath();

        // Base args
        $baseArgs = [
            '--namespace=' . escapeshellarg($this->namespace),
            '--name=' . escapeshellarg($this->name),
        ];

        if ($this->tag !== null) {
            $baseArgs[] = '--tag=' . escapeshellarg($this->tag);
        }
        if ($debugMode) {
            $baseArgs[] = '--debug';
        }

        // Custom args
        $customArgs = [];
        foreach ($arguments as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                continue;
            }
            if ($value === true) {
                $customArgs[] = '--arg-' . escapeshellarg($key);
            } elseif ($value !== null && $value !== false) {
                $customArgs[] = '--arg-' . escapeshellarg($key) . '=' . escapeshellarg((string)$value);
            }
        }

        $allArgs = array_merge($baseArgs, $customArgs);
        return "{$phpExecutable} {$runnerScript} " . implode(' ', array_filter($allArgs));
    }

    /**
     * Closes any open process pipes.
     */
    private function closePipes(): void
    {
        foreach ($this->processPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->processPipes = [];
    }

    /**
     * Creates a security provider for Opis/Closure serialization.
     *
     * This method allows for signed serialization, which prevents the execution of
     * untrusted code. To enable this feature, define the `WINTER_THREAD_SECRET`
     * constant with a long, secret, and unique string before starting any threads.
     *
     * Example:
     * ```
     * php
     * define('WINTER_THREAD_SECRET', 'your-super-secret-key-here'); // string
     * $thread = new Thread(new MyTask());
     * $thread->start();
     * ```
     *
     * @return DefaultSecurityProvider|null A configured security provider if the constant is defined,
     *                                      otherwise null.
     */
    public static function getSerSecurity(): ?DefaultSecurityProvider
    {
        if (defined('WINTER_THREAD_SECRET')) {
            return new DefaultSecurityProvider(
                secret: (string) WINTER_THREAD_SECRET
            );
        }
        return null;
    }

    /**
     * Gets the configured path to the executable runner script.
     *
     * Returns the path set via `bindRunner()`, or the default path if `bindRunner()`
     * has not been called. The default path points to the `runner` script located
     * within this library's directory.
     *
     * @return string The absolute or relative path to the runner script.
     */
    public static function getRunnerScriptPath(): string
    {
        if (!isset(self::$runnerScriptPath)) {
            self::$runnerScriptPath = dirname(__DIR__) . '/wExecutor';
        }
        return self::$runnerScriptPath;
    }

    /**
     * Overrides the default path to the runner script.
     *
     * This method should be called once at the beginning of your application's bootstrap
     * process if you need to use a custom runner script or if the default path is incorrect
     * for your project structure.
     *
     * @param string $runnerScriptPath The absolute path to the custom runner script.
     */
    public static function bindRunner(string $runnerScriptPath): void
    {
        self::$runnerScriptPath = $runnerScriptPath;
    }
}
