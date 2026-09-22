<?php

namespace App\Services;

use App\Models\AgentRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AgentSandbox
{
    public function start(AgentRun $run): string
    {
        $data = [];
        if ($run->input_path) {
            $data['file'] = ['name' => $run->input_name, 'content' => base64_encode(Storage::disk('local')->get($run->input_path))];
        }

        return $this->request('/sessions', $data)['session'];
    }

    public function command(string $session, string $command): array
    {
        return $this->request('/sessions/'.$session.'/command', ['command' => $command]);
    }

    public function collect(AgentRun $run, string $session): array
    {
        $artifacts = [];
        $total = 0;
        foreach (array_slice($this->request('/sessions/'.$session.'/artifacts')['files'], 0, 20) as $file) {
            $content = base64_decode($file['content'], true);
            if ($content === false || ($total += strlen($content)) > 20 * 1024 * 1024) {
                throw new RuntimeException('Invalid or oversized artifact');
            }
            $name = substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name'])), 0, 120);
            $path = 'agent-runs/'.$run->id.'/outputs/'.count($artifacts);
            Storage::disk('local')->put($path, $content);
            $artifacts[] = ['name' => $name ?: 'output.txt', 'path' => $path, 'size' => strlen($content)];
        }

        return $artifacts;
    }

    public function stop(string $session): void
    {
        $this->request('/sessions/'.$session.'/stop');
    }

    protected function request(string $path, array $data = []): array
    {
        return Http::baseUrl(config('agent.broker_url'))
            ->withToken(config('agent.broker_token'))
            ->connectTimeout(5)->timeout(100)->post($path, $data)->throw()->json();
    }
}
