<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Runner;

use Flytachi\Winter\Thread\Engine\Engine;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Runnable;

final class ProcessRunner implements Runner
{
    /**
     * @param resource|null $errStream Where diagnostics are written; defaults to STDERR.
     *                                 Injectable so tests can capture output instead of
     *                                 leaking it to the console.
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly mixed $errStream = null,
    ) {
    }

    private function stderr(): mixed
    {
        return $this->errStream ?? STDERR;
    }

    public function execute(array $options): int
    {
        $payload = $this->receiveTransport($options)->receive($options);
        if ($payload === '') {
            fwrite($this->stderr(), "Error: No payload received.\n");
            return 1;
        }

        $security = $this->engine->security();
        try {
            if (function_exists('\Opis\Closure\serialize')) {
                $runnable = \Opis\Closure\unserialize($payload, $security);
            } else {
                $runnable = unserialize($payload);
            }
        } catch (\Throwable $e) {
            // Includes Opis SecurityException for unsigned/tampered payloads when a
            // secret is configured — reject cleanly instead of a fatal error.
            fwrite($this->stderr(), 'Error: failed to deserialize payload: ' . $e->getMessage() . "\n");
            return 1;
        }

        if (!$runnable instanceof Runnable) {
            fwrite($this->stderr(), "Error: The provided payload is not a valid Runnable object.\n");
            return 1;
        }

        if (isset($options['detach'])) {
            $this->daemonize();
        }

        $this->setProcessTitle($options, $runnable);

        try {
            $runnable->run($this->parseArgs());
            return 0;
        } catch (\Throwable $e) {
            fwrite($this->stderr(), 'Uncaught exception in background process: ' . $e->getMessage() . "\n");
            fwrite($this->stderr(), $e->getTraceAsString() . "\n");
            return 1;
        }
    }

    /** Read side is options-driven: shmkey → shm, otherwise stdin (pipe/tempfile identical). */
    private function receiveTransport(array $options): PipeTransport|ShmTransport
    {
        return isset($options['shmkey']) ? new ShmTransport() : new PipeTransport();
    }

    private function daemonize(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite($this->stderr(), "Error: fork failed for detached mode.\n");
            exit(1);
        }
        if ($pid > 0) {
            // Launcher process L: exit immediately so the parent reaps it cheaply.
            exit(0);
        }
        // Worker process W: new session, no controlling terminal, reparented to init.
        if (posix_setsid() === -1) {
            fwrite($this->stderr(), "Error: setsid failed for detached mode.\n");
            exit(1);
        }
    }

    private function setProcessTitle(array $options, Runnable $runnable): void
    {
        if (!function_exists('cli_set_process_title')) {
            return;
        }
        $namespace = isset($options['namespace']) ? ($options['namespace'] . ' ') : '';
        $tag = $options['tag'] ?? 'runnable';
        if (isset($options['name'])) {
            $name = $options['name'];
        } else {
            $class = get_class($runnable);
            $name = substr($class, strrpos($class, '\\') + 1);
        }
        cli_set_process_title("WinterThread {$namespace}-> {$name}@{$tag}");
    }

    /** @return array<string, string|bool> */
    private function parseArgs(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $args = [];
        foreach ($argv as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--arg-')) {
                continue;
            }
            $content = substr($arg, 6);
            if (str_contains($content, '=')) {
                [$key, $value] = explode('=', $content, 2);
                $args[$key] = $value;
            } else {
                $args[$content] = true;
            }
        }
        return $args;
    }
}
