<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Engine;

use Flytachi\Winter\Thread\Launch\Launcher;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Runner\Runner;
use Opis\Closure\Security\DefaultSecurityProvider;

interface Engine
{
    public function transport(): PayloadTransport;
    public function launcher(): Launcher;
    public function runner(): Runner;
    public function binaryPath(): string;
    public function runnerPath(): string;
    public function security(): ?DefaultSecurityProvider;
}
