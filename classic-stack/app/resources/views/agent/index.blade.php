@extends('layouts.app')
@section('content')
<p class="eyebrow">NEURON AI / COMPUTER AGENT</p>
<h1>Give it a task.<br>Watch it work.</h1>
<p class="intro">Ask the agent to fetch a public page, parse a file, or work on a short video. It gets a fresh Linux workspace and chooses the commands to run.</p>
<div class="grid">
    <section class="card">
        <h2>What should it do?</h2>
        @unless($configured)
            <p class="notice">Setup needed: add your model settings and API key to <code>app/.env</code>, then run <code>./scripts/agent up</code>.</p>
        @endunless
        <form method="POST" action="{{ route('agent.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="prompt">Message</label>
            <textarea id="prompt" name="prompt" required maxlength="12000" placeholder="Go to https://example.com, extract its title and links, and save them as JSON.">{{ old('prompt') }}</textarea>
            <label for="file">Attach a file <span class="muted">optional · up to 20 MB</span></label>
            <input id="file" type="file" name="file">
            <p class="muted">For video: “Inspect this clip with ffprobe, extract one thumbnail, and save its metadata as JSON.”</p>
            <button @disabled(!$configured)>Run task →</button>
        </form>
    </section>
    <section class="card">
        <h2>A computer for each task</h2>
        <p>Bash, Python, curl, jq, ffmpeg, and ffprobe. Files persist while the task runs; results are saved here before the workspace is removed.</p>
        <p class="muted">Public web access. Up to 20 commands. One task at a time. No browser, speech transcription, or visual video understanding yet.</p>
        <p class="muted">Task text and command output go to your configured model provider. The command log shows the agent's actual work.</p>
    </section>
</div>
<h2>Your recent tasks</h2>
@forelse($runs as $run)
    <p><span class="badge">{{ $run->status }}</span> <a href="{{ route('agent.show', $run) }}">{{ Str::limit($run->prompt, 100) }}</a></p>
@empty
    <p class="muted">Your first task will appear here.</p>
@endforelse
@endsection
