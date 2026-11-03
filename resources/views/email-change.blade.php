<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex">
<title>{{ $status === 'done' ? 'Email updated' : 'Link not valid' }} · MindEase</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; background: #F7F4F2; color: #4F3422; font: 500 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
.hero { background: #D64938; color: #fff; padding: 34px 20px 64px; border-radius: 0 0 34px 34px; text-align: center; font-weight: 800; font-size: 18px; }
.logo { display: inline-grid; place-items: center; width: 36px; height: 36px; border-radius: 12px; background: #fff; color: #D64938; font-weight: 900; margin-right: 8px; vertical-align: middle; }
.card { background: #fff; max-width: 440px; margin: -40px auto 32px; border-radius: 24px; padding: 28px 24px; box-shadow: 0 6px 20px rgba(79,52,34,.06); }
@media (max-width: 480px) { .card { margin: -40px 16px 24px; } }
h1 { font-size: 24px; line-height: 1.2; margin: 0 0 8px; }
p { margin: 0; color: #736B66; }
.banner { border-radius: 18px; padding: 14px 16px; margin-bottom: 16px; font-weight: 700; font-size: 14.5px; }
.ok { background: #E3EFD0; color: #3E5A18; }
.error { background: #FBE3DF; color: #B93A2B; }
</style>
</head>
<body>
<header class="hero"><span class="logo">M</span>MindEase</header>
<main class="card">
@if ($status === 'done')
  <div class="banner ok" role="status">Your email has been changed.</div>
  <h1>You're all set</h1>
  <p>From now on, sign in to the MindEase app with <b>{{ $email }}</b>.</p>
@elseif ($status === 'taken')
  <div class="banner error" role="alert">This email is already used by another account.</div>
  <h1>Email not changed</h1>
  <p>Your email stays the same. In the app, choose a different email under Profile.</p>
@else
  <div class="banner error" role="alert">This link has expired or was already used.</div>
  <h1>Email not changed</h1>
  <p>Links work for 60 minutes, and only the newest one works. In the app, enter the new email again under Profile to get a fresh link.</p>
@endif
</main>
</body>
</html>
