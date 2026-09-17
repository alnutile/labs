<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Classic Stack Lab</title>
<link rel="stylesheet" href="{{ asset('stack.css') }}"></head><body>
<header><a class="brand" href="/dashboard">CLASSIC STACK <span>/ LAB 01</span></a><nav>@auth<a href="/dashboard">Dashboard</a><a href="/horizon" target="_blank" rel="noopener">Horizon ↗</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="link">Sign out</button></form>@endauth</nav></header>
<main>@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@yield('content')</main><footer>Laravel · PostgreSQL · Redis · Horizon · Docker</footer></body></html>
