<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Jinx')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @stack('head')
</head>
<body style="margin:0; font-family: Arial, sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

<div style="max-width:980px; margin:0 auto; padding:20px; box-sizing:border-box;">
    @include('partials.app-nav')
    @yield('content')
</div>

@stack('scripts')
</body>
</html>
