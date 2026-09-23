<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\AgentSandbox;
use App\Services\ComputerAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunComputerAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 840;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onConnection('agent')->onQueue('agents');
    }

    /**
     * Execute the job.
     */
    public function handle(ComputerAgent $agent, AgentSandbox $sandbox): void
    {
        if (! config('agent.enabled') || ! app()->environment(config('agent.allowed_environments'))) {
            throw new \RuntimeException('The computer agent is not enabled in this environment.');
        }
        if (! AgentRun::whereKey($this->runId)->where('status', 'queued')->update(['status' => 'running'])) {
            return;
        }
        $run = AgentRun::findOrFail($this->runId);
        $session = null;
        try {
            $agent->provider();
            $session = $sandbox->start($run);
            $run->update(['session_id' => $session]);
            $run->recordEvent('status', 'Workspace ready. The agent can now run commands.');
            $result = $agent->run($run, $sandbox, $session);
            $artifacts = $sandbox->collect($run, $session);
            $run->update(['result' => $result, 'artifacts' => $artifacts, 'status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $error) {
            $this->failed($error);
            throw $error;
        } finally {
            if ($session) {
                try {
                    $sandbox->stop($session);
                } catch (Throwable) {
                    $run->recordEvent('status', 'Cleanup deferred to the sandbox expiry timer.');
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        AgentRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'finished_at' => now(),
            'error' => 'The task stopped. Check the worker logs and model configuration. Commands already completed are shown below.',
        ]);
    }
}
