@extends('layouts.app')
@section('head')
@if(in_array($run->status, ['queued', 'running']))<meta http-equiv="refresh" content="3">@endif
@endsection
@section('content')
<div class="row"><p class="eyebrow">COMPUTER AGENT / TASK {{ $run->id }}</p><a href="{{ route('agent.index') }}">All tasks</a></div>
<h1>{{ ucfirst($run->status) }}.</h1>
<section class="card"><h2>Your message</h2><p class="agent-text">{{ $run->prompt }}</p>
@if($run->input_name)<p class="muted">Attached: {{ $run->input_name }}</p>@endif</section>
@if(in_array($run->status, ['queued', 'running']))
<div class="row"><p role="status">This page refreshes every three seconds while the agent works. The task has a five-minute limit.</p><form method="POST" action="{{ route('agent.cancel', $run) }}">@csrf<button class="danger">Cancel task</button></form></div>
@endif
@if($run->error)<p class="notice error">{{ $run->error }}</p>@endif
@if($run->result)<section class="card"><h2>Agent response</h2><p class="agent-text">{{ $run->result }}</p></section>@endif
@if($run->artifacts)
    <h2>Download results</h2>
    @foreach($run->artifacts as $index => $file)
        <p><a class="button secondary" href="{{ route('agent.download', [$run, $index]) }}">{{ $file['name'] }} ↓</a> <span class="muted">{{ number_format($file['size'] / 1024, 1) }} KB</span></p>
    @endforeach
@endif
<h2>Command log</h2>
@forelse($run->events ?? [] as $event)
    <section class="agent-event"><p class="eyebrow">{{ $event['type'] }} · {{ $event['at'] }}</p><pre>{{ $event['text'] }}</pre></section>
@empty
    <p class="muted">Waiting for the worker to pick up this task.</p>
@endforelse
@endsection
