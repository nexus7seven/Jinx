<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jinx Login</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body{
            margin:0;
            min-height:100vh;
            font-family:Arial,sans-serif;
            background:linear-gradient(135deg,#0b1220 0%,#111827 50%,#1e293b 100%);
            color:#f9fafb;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
            box-sizing:border-box;
        }
        .wrap{
            width:100%;
            max-width:420px;
        }
        .card{
            background:rgba(15,23,42,.92);
            border:1px solid rgba(148,163,184,.18);
            border-radius:20px;
            padding:28px;
            box-shadow:0 20px 60px rgba(0,0,0,.45);
            backdrop-filter:blur(10px);
        }
        h1{
            margin:0 0 8px 0;
            font-size:30px;
        }
        p{
            margin:0 0 24px 0;
            color:#94a3b8;
        }
        label{
            display:block;
            margin:0 0 8px 0;
            font-size:14px;
            color:#cbd5e1;
        }
        input[type="email"],
        input[type="password"]{
            width:100%;
            min-height:46px;
            border-radius:12px;
            border:1px solid #334155;
            background:#0f172a;
            color:#f8fafc;
            padding:0 14px;
            box-sizing:border-box;
            margin:0 0 16px 0;
        }
        .remember{
            display:flex;
            align-items:center;
            gap:8px;
            margin:0 0 18px 0;
            color:#cbd5e1;
            font-size:14px;
        }
        button{
            width:100%;
            min-height:48px;
            border:0;
            border-radius:12px;
            background:linear-gradient(135deg,#7c3aed,#2563eb);
            color:#fff;
            font-weight:700;
            cursor:pointer;
        }
        .error{
            background:rgba(127,29,29,.35);
            border:1px solid rgba(248,113,113,.35);
            color:#fecaca;
            padding:12px 14px;
            border-radius:12px;
            margin:0 0 18px 0;
            font-size:14px;
        }
        .brand{
            margin-bottom:18px;
            font-size:13px;
            letter-spacing:.18em;
            text-transform:uppercase;
            color:#a78bfa;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="brand">Jinx</div>
            <h1>Secure Login</h1>
            <p>Please sign in to access lead data.</p>

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}">
                @csrf

                <label for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="username"
                >

                <label for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                >

                <label class="remember">
                    <input type="checkbox" name="remember" value="1">
                    Remember me
                </label>

                <button type="submit">Sign In</button>
            </form>
        </div>
    </div>
</body>
</html>