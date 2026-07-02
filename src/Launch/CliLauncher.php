<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;
use Flytachi\Winter\Thread\ThreadException;

final class CliLauncher implements Launcher
{
    /** @param array<string,string> $childEnv */
    public function __construct(
        private readonly string $binaryPath,
        private readonly string $runnerPath,
        private readonly PayloadTransport $transport,
        private readonly array $childEnv = [],
    ) {}

    public function launch(LaunchSpec $spec): ProcessHandle
    {
        $staged = $this->transport->stage($spec->payload);

        $descriptors = [0 => $staged->stdinSpec];
        if ($spec->output !== null) {
            $descriptors[1] = ['file', $spec->output, 'a'];
            $descriptors[2] = ['file', $spec->output, 'a'];
        } else {
            $descriptors[1] = ['pipe', 'w'];
            $descriptors[2] = ['pipe', 'w'];
        }

        $env = $this->childEnv === [] ? null : array_merge(getenv(), $this->childEnv);
        $pipes = [];
        $process = proc_open($this->buildCommand($spec, $staged), $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            $this->transport->cleanup($staged);
            throw new ThreadException('Failed to start the process using proc_open.');
        }

        if ($staged->pipePayload !== null) {
            fwrite($pipes[0], $staged->pipePayload);
            fclose($pipes[0]);
        }
        if ($staged->unlinkAfterOpen !== null) {
            @unlink($staged->unlinkAfterOpen);
        }
        if ($spec->output === null) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
        }

        $status = proc_get_status($process);
        if (!$status || $status['running'] !== true) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            $this->transport->cleanup($staged);
            throw new ThreadException('Process failed to start or terminated immediately.');
        }

        return new ProcessHandle($process, $pipes, $status['pid'], $this->transport, $staged);
    }

    private function buildCommand(LaunchSpec $spec, StagedPayload $staged): string
    {
        $args = [
            '--namespace=' . escapeshellarg($spec->namespace),
            '--name=' . escapeshellarg($spec->name),
        ];
        if ($spec->tag !== null) {
            $args[] = '--tag=' . escapeshellarg($spec->tag);
        }
        if ($spec->debug) {
            $args[] = '--debug';
        }
        if ($spec->detached) {
            $args[] = '--detach';
        }
        foreach ($staged->cliArgs as $cliArg) {
            $args[] = $cliArg; // e.g. --shmkey=<int>, already safe
        }
        foreach ($spec->arguments as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                continue;
            }
            if ($value === true) {
                $args[] = '--arg-' . escapeshellarg((string) $key);
            } elseif ($value !== null && $value !== false) {
                $args[] = '--arg-' . escapeshellarg((string) $key) . '=' . escapeshellarg((string) $value);
            }
        }

        return escapeshellarg($this->binaryPath) . ' '
            . escapeshellarg($this->runnerPath) . ' '
            . implode(' ', $args);
    }
}
