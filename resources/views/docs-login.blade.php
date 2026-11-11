<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="same-origin">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · MindEase API docs</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; background: #F7F4F2; color: #4F3422; font: 500 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
.hero { background: #D64938; color: #fff; padding: 34px 20px 64px; border-radius: 0 0 34px 34px; text-align: center; font-weight: 800; font-size: 18px; }
.logo { display: inline-grid; place-items: center; width: 36px; height: 36px; border-radius: 12px; background: #fff; color: #D64938; font-weight: 900; margin-right: 8px; vertical-align: middle; }
.card { background: #fff; max-width: 420px; margin: -40px auto 32px; border-radius: 24px; padding: 28px 24px; box-shadow: 0 6px 20px rgba(79,52,34,.06); }
@media (max-width: 480px) { .card { margin: -40px 16px 24px; } }
h1 { font-size: 24px; line-height: 1.2; margin: 0 0 6px; }
p { margin: 0 0 20px; color: #736B66; font-size: 15px; }
label { display: block; font-weight: 700; font-size: 14px; margin: 14px 0 6px; }
input { width: 100%; padding: 12px 14px; border: 1.5px solid #E5DDD8; border-radius: 14px; font: inherit; color: inherit; background: #fff; }
input:focus { outline: none; border-color: #D64938; }
button { width: 100%; margin-top: 22px; padding: 13px; border: 0; border-radius: 999px; background: #4F3422; color: #fff; font: 800 16px/1 inherit; cursor: pointer; }
button:hover { background: #3a2619; }
.note { background: #F3EDE9; color: #4F3422; border-radius: 14px; padding: 12px 14px; font-weight: 700; font-size: 14px; margin-bottom: 8px; }
.error { background: #FBE3DF; color: #B93A2B; border-radius: 14px; padding: 12px 14px; font-weight: 700; font-size: 14px; margin-bottom: 8px; }
</style>
</head>
<body>
<header class="hero"><span class="logo">M</span>MindEase</header>
<main class="card">
  <h1>API documentation</h1>
  <p>Sign in with an authorised MindEase account to view the API documentation.</p>

  @if (session('docs_status') && ! $errors->any())
    <div class="note" role="status">{{ session('docs_status') }}</div>
  @endif
  @if ($errors->any())
    <div class="error" role="alert">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('docs.login.submit') }}" autocomplete="on">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="255">

    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password" maxlength="255">

    <button type="submit">Sign in</button>
  </form>
</main>
</body>
</html>
