<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread;

/**
 * Represents a task that can be executed in a separate process by a Thread.
 *
 * Any class that implements this interface can be passed to the `Thread` constructor.
 * The `run()` method contains the core logic that will be executed in the isolated
 * child process.
 *
 * The class implementing this interface must be serializable. This means it should
 * not contain resources (like database connections or file handles) or other
 * non-serializable objects in its properties. Any required resources should be
 * initialized within the `run()` method itself.
 *
 * @see \Flytachi\Winter\Thread\Thread
 */
interface Runnable
{
    /**
     * The main entry point for the background task.
     *
     * All the logic to be executed in the separate process must be placed within
     * this method. If an uncaught exception is thrown from this method, it will
     * be caught by the runner, logged to stderr, and the process will exit with
     * a non-zero status code.
     */
    public function run(): void;
}
