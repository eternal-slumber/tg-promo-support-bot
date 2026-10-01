<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessIncomingMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $messageId) {}

    /**
     * Execute the job.
     */
    public function handle(): void {}
}
