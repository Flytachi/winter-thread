<?php

namespace Flytachi\Kernel\Src\Thread;

final class Signal
{
    /**
     * Sends an interrupt signal (SIGINT) to a process.
     *
     * @param int $pid The process ID.
     * @return bool True if the signal was sent, false otherwise.
     */
    public static function interrupt(int $pid): bool
    {
        if (self::isProcessRunning($pid)) {
            return posix_kill($pid, SIGINT);
        }
        return false;
    }

    /**
     * Sends a termination signal (SIGTERM) to a process.
     *
     * @param int $pid The process ID.
     * @return bool True if the signal was sent, false otherwise.
     */
    public static function termination(int $pid): bool
    {
        if (self::isProcessRunning($pid)) {
            return posix_kill($pid, SIGTERM);
        }
        return false;
    }

    /**
     * Sends a hangup signal (SIGHUP) to a process.
     *
     * @param int $pid The process ID.
     * @return bool True if the signal was sent, false otherwise.
     */
    public static function close(int $pid): bool
    {
        if (self::isProcessRunning($pid)) {
            return posix_kill($pid, SIGHUP);
        }
        return false;
    }

    /**
     * Waits for a process to terminate.
     *
     * @param int $pid The process ID to wait for.
     * @param int $timeout Maximum wait time in seconds.
     * @return bool True if the process terminated, false on timeout.
     */
    public static function wait(int $pid, int $timeout = 10): bool
    {
        $startTime = time();
        while (self::isProcessRunning($pid)) {
            if (time() - $startTime > $timeout) {
                return false;
            }
            usleep(250_000); // 0.25 seconds
        }
        return true;
    }

    /**
     * Sends an interrupt signal (SIGINT) and waits for the process to terminate.
     *
     * @param int $pid The process ID.
     * @param int $timeout Maximum wait time in seconds.
     * @return bool True if the process was signaled and terminated successfully, false otherwise.
     */
    public static function interruptAndWait(int $pid, int $timeout = 10): bool
    {
        if (self::interrupt($pid)) {
            return self::wait($pid, $timeout);
        }
        return !self::isProcessRunning($pid);
    }

    /**
     * Sends a termination signal (SIGTERM) and waits for the process to terminate.
     *
     * @param int $pid The process ID.
     * @param int $timeout Maximum wait time in seconds.
     * @return bool True if the process was signaled and terminated successfully, false otherwise.
     */
    public static function terminationAndWait(int $pid, int $timeout = 10): bool
    {
        if (self::termination($pid)) {
            return self::wait($pid, $timeout);
        }
        return !self::isProcessRunning($pid);
    }

    /**
     * Sends a hangup signal (SIGHUP) and waits for the process to terminate.
     * Note: SIGHUP doesn't always terminate a process; it might just reload it.
     * This method is useful if your process is designed to exit on SIGHUP.
     *
     * @param int $pid The process ID.
     * @param int $timeout Maximum wait time in seconds.
     * @return bool True if the process was signaled and terminated successfully, false otherwise.
     */
    public static function closeAndWait(int $pid, int $timeout = 10): bool
    {
        if (self::close($pid)) {
            return self::wait($pid, $timeout);
        }
        return !self::isProcessRunning($pid);
    }

    /**
     * Checks if a process is currently running.
     *
     * @param int $pid The process ID.
     * @return bool True if the process is running, false otherwise.
     */
    public static function isProcessRunning(int $pid): bool
    {
        // posix_kill with signal 0 is a standard way to check for process existence
        return posix_kill($pid, 0);
    }
}
