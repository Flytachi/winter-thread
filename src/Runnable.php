<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread;

/**
 * Represents a task that can be executed in a separate process.
 */
interface RunnableInterface
{
    /**
     * The main logic of the task goes here.
     */
    public function run(): void;
}