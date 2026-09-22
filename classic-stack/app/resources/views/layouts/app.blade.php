<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Classic Stack Lab</title>
<link rel="stylesheet" href="{{ asset('stack.css') }}">@yield('head')</head><body>
<header><a class="brand" href="/dashboard">CLASSIC STACK <span>/ LAB 01</span></a><nav><a href="https://github.com/alnutile/labs" target="_blank" rel="noopener noreferrer">GitHub ↗</a>@auth<a href="/dashboard">Dashboard</a><a href="/horizon" target="_blank" rel="noopener">Horizon ↗</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="link">Sign out</button></form>@endauth</nav></header>
@can('use-computer-agent')<aside class="agent-nav"><a href="{{ route('agent.index') }}">Computer agent ↗</a> <span class="muted">Local experiment</span></aside>@endcan
<main>@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@yield('content')</main><footer>Laravel · PostgreSQL · Redis · Horizon · Docker</footer></body></html>
