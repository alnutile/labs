<?php

namespace App\Jobs;

use App\Models\DemoRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WriteDemoReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        $run = DemoRun::findOrFail($this->runId);
        if ($run->status === 'completed') {
            return;
        }
        $run->update(['status' => 'processing']);
        $path = "reports/{$run->user_id}/{$run->id}.txt";
        Storage::disk('local')->put($path, "Classic stack queue report\n\n{$run->message}\n");
        $run->update(['status' => 'completed', 'file_path' => $path, 'completed_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        DemoRun::whereKey($this->runId)->update(['status' => 'failed']);
    }

    public function tags(): array
    {
        return ['demo', 'run:'.$this->runId];
    }
}
