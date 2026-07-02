<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread;

/**
 * Immutable value object carrying every parameter needed to launch one process.
 *
 * A `LaunchSpec` is what {@see \Flytachi\Winter\Thread\Launch\Launcher::launch()}
 * consumes. Bundling the parameters into one object keeps the launcher signature
 * stable as options grow, and lets a worker pool build a spec once and reuse it.
 *
 * @see \Flytachi\Winter\Thread\Launch\Launcher
 */
final readonly class LaunchSpec
{
    /**
     * @param string                     $payload   The already-serialized Runnable to deliver.
     * @param string                     $namespace Logical grouping shown in the OS process title.
     * @param string                     $name      Task name shown in the OS process title.
     * @param string|null                $tag       Optional instance tag (e.g. "user-123").
     * @param array<string, scalar|null> $arguments Per-run arguments, exposed to the task as `--arg-*`.
     * @param bool                       $debug     Enable child-side error reporting.
     * @param string|null                $output    Output target: `'/dev/null'` (discard), `null`
     *                                              (pipe to parent for readOutput/readError), or a file path.
     * @param bool                       $detached  Daemonize the child (fork + setsid) for zombie-free
     *                                              fire-and-forget under a long-lived parent.
     */
    public function __construct(
        public string $payload,
        public string $namespace = '',
        public string $name = 'anonymous',
        public ?string $tag = null,
        public array $arguments = [],
        public bool $debug = false,
        public ?string $output = '/dev/null',
        public bool $detached = false,
    ) {
    }
}
