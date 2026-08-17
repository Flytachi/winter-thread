<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

/**
 * Detects a Swoole runtime state in which `proc_open` is unsafe.
 *
 * Shared by {@see CliLauncher} (which then picks a pipe-free transport) and
 * {@see AdaptiveLauncher} (which then routes to {@see SwooleLauncher}). True when
 * we are inside a coroutine or when runtime hooks are enabled — both make
 * `proc_open` corrupt the reactor's file descriptors.
 *
 * @link https://winterframe.net/packages/thread/swoole-and-payload-delivery What counts as an active Swoole runtime
 */
trait DetectsSwooleRuntime
{
    private static function swooleRuntimeActive(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }
        if (class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() !== -1) {
            return true;
        }
        if (class_exists('\Swoole\Runtime') && method_exists('\Swoole\Runtime', 'getHookFlags')) {
            return \Swoole\Runtime::getHookFlags() !== 0;
        }
        return false;
    }
}
