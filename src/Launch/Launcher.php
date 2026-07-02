<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;

interface Launcher
{
    public function launch(LaunchSpec $spec): ProcessHandle;
}
