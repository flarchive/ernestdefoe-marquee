<?php

namespace Ernestdefoe\Marquee;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Work the visitor need not wait for. It runs once the response has gone:
 * PHP-FPM and LiteSpeed close the connection first, so the answer arrives
 * straight away; other servers run it at the end of the request, as before.
 */
class Later
{
    /** @var list<callable(): void> */
    private array $tasks = [];

    public function __construct(private LoggerInterface $log)
    {
    }

    /** @param callable(): void $task */
    public function run(callable $task): void
    {
        if (! $this->tasks) {
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }

                $this->flush();
            });
        }

        $this->tasks[] = $task;
    }

    /** Run what is waiting now (the shutdown hook does this; tests call it directly). */
    public function flush(): void
    {
        while ($task = array_shift($this->tasks)) {
            try {
                $task();
            } catch (Throwable $e) {
                $this->log->warning('[marquee] Deferred work failed: '.$e->getMessage());
            }
        }
    }
}
