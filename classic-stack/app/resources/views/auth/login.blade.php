@extends('layouts.app')
@section('content')<section class="auth"><p class="eyebrow">YOUR OWN INFRASTRUCTURE</p><h1>Welcome back.</h1><p>Sign in to your classic stack.</p>
<form method="POST" action="{{ route('login') }}">@csrf<label>Email<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Sign in →</button></form>
@if(Route::has('register'))<p>First visit? <a href="{{ route('register') }}">Create an account</a></p>@endif</section>@endsection
