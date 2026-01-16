<?php

namespace Flytachi\Winter\Thread;

/**
 * Utility class for sending POSIX signals to processes by PID.
 *
 * Provides static methods for common process control operations such as
 * interrupting, terminating, and killing processes, as well as waiting
 * for process termination.
 *
 * WARNING: PID Reuse Limitation
 * This class works with raw PIDs without process handle validation.
 * Due to PID reuse by the OS, a PID may be reassigned to a different process
 * after the original process terminates. This means:
 * - `isProcessRunning($pid)` may return true for a completely different process
 * - Signal methods may affect an unrelated process
 *
 * For safer process management, use the {@see Thread} class which validates
 * processes via handles. Use this class only with "fresh" PIDs obtained
 * immediately before the call.
 *
 * @package Flytachi\Winter\Thread
 */
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
     * Sends a kill signal (SIGKILL) to a process.
     * This is a forceful termination and cannot be caught or ignored.
     *
     * @param int $pid The process ID.
     * @return bool True if the signal was sent, false otherwise.
     */
    public static function kill(int $pid): bool
    {
        if (self::isProcessRunning($pid)) {
            return posix_kill($pid, SIGKILL);
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
            usleep(50_000); // 0.05 seconds
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
     * Checks if a process with the given PID exists.
     *
     * Note: This method only verifies that a process with this PID exists and is
     * accessible. Due to PID reuse, this may be a different process than the one
     * originally associated with this PID. For reliable process tracking, use
     * the {@see Thread} class instead.
     *
     * @param int $pid The process ID.
     * @return bool True if a process with this PID exists, false otherwise.
     */
    public static function isProcessRunning(int $pid): bool
    {
        // posix_kill with signal 0 is a standard way to check for process existence
        return posix_kill($pid, 0);
    }
}
