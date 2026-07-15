<?php

declare(strict_types=1);

use Flytachi\Winter\Thread\Launch\ProcessHandle;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('UTC');

$thread = new \Flytachi\Winter\Thread\Thread(new class implements \Flytachi\Winter\Thread\Runnable {
    public function run(array $args): void
    {
        $file = 'log.txt';
        file_put_contents($file, "--- Start [" . getmypid() . "]\n", FILE_APPEND);
        for ($i = 1; $i <= $args['ticks'] ?? 1; $i++) {
            file_put_contents(
                $file,
                time() . " - Новая строка {$i}\n",
                FILE_APPEND
            );
            sleep(1);
        }
        file_put_contents($file, "--- Stop [" . getmypid() . "]\n", FILE_APPEND);
    }
});

$thread->start(
    arguments: ['ticks' => 10],
    detached: true
);
echo "PID:" . $thread->getPid() . PHP_EOL;

sleep(2);
$thread->pause();

sleep(5);
$thread->resume();

sleep(2);
$thread->interrupt();
