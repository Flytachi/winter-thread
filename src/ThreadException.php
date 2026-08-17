<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread;

/**
 * The only exception this package throws.
 *
 * Raised on a failed launch, on starting an already-running Thread, on invalid
 * configuration, and on payload staging failures. Extends \RuntimeException, so
 * catching that catches this too.
 *
 * @link https://winterframe.net/packages/thread/api-reference#threadexception-class When it is thrown, and what to do
 */
class ThreadException extends \RuntimeException
{
}
