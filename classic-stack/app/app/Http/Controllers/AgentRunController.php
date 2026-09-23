<?php

namespace App\Http\Controllers;

use App\Jobs\RunComputerAgent;
use App\Models\AgentRun;
use App\Models\User;
use App\Services\AgentSandbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentRunController extends Controller
{
    public function index(Request $request): View
    {
        return view('agent.index', [
            'runs' => AgentRun::where('user_id', $request->user()->id)->latest()->limit(20)->get(),
            'configured' => (bool) config('agent.broker_token') && (config('agent.provider') === 'ollama' || (bool) config('agent.api_key')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:12000'],
            'file' => ['nullable', 'file', 'max:20480'],
        ]);
        if (! config('agent.broker_token') || (config('agent.provider') !== 'ollama' && ! config('agent.api_key'))) {
            throw ValidationException::withMessages(['prompt' => 'Configure the model and sandbox in app/.env first.']);
        }
        $path = null;
        try {
            $run = DB::transaction(function () use ($request, $data, &$path) {
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                if (AgentRun::where('user_id', $request->user()->id)->whereIn('status', ['queued', 'running'])->exists()) {
                    throw ValidationException::withMessages(['prompt' => 'Wait for your current task to finish before starting another.']);
                }
                $name = null;
                if ($request->hasFile('file')) {
                    $extension = $request->file('file')->guessExtension() ?: 'bin';
                    $name = 'input.'.preg_replace('/[^a-zA-Z0-9]/', '', $extension);
                    $path = $request->file('file')->store('agent-inputs', 'local');
                }
                $run = AgentRun::create([
                    'user_id' => $request->user()->id, 'prompt' => $data['prompt'],
                    'input_path' => $path, 'input_name' => $name,
                ]);
                RunComputerAgent::dispatch($run->id)->afterCommit();

                return $run;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return to_route('agent.show', $run);
    }

    public function show(Request $request, AgentRun $run): View
    {
        abort_unless($run->user_id === $request->user()->id, 403);

        return view('agent.show', ['run' => $run]);
    }

    public function cancel(Request $request, AgentRun $run, AgentSandbox $sandbox): RedirectResponse
    {
        abort_unless($run->user_id === $request->user()->id, 403);
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return to_route('agent.show', $run);
        }

        $session = $run->session_id;
        $run->update(['status' => 'canceled', 'finished_at' => now()]);
        $run->recordEvent('status', 'Canceled by user.');
        if ($session) {
            try {
                $sandbox->stop($session);
            } catch (\Throwable) {
                $run->recordEvent('status', 'Sandbox cleanup deferred to its expiry timer.');
            }
        }

        return to_route('agent.show', $run);
    }

    public function download(Request $request, AgentRun $run, int $artifact): StreamedResponse
    {
        abort_unless($run->user_id === $request->user()->id, 403);
        $file = $run->artifacts[$artifact] ?? null;
        abort_unless($run->status === 'completed' && $file, 404);

        return Storage::disk('local')->download($file['path'], $file['name'], [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
