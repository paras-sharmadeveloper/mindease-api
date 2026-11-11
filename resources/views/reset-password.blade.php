<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex">
<title>Reset your password · MindEase</title>
{{-- Self-contained on purpose: no external fonts, scripts or images, so the reset token never leaves this page. --}}
<style nonce="{{ $nonce }}">
:root {
  --red: #D64938; --red-deep: #B93A2B; --red-soft: #FBE3DF; --cream: #F7F4F2; --brown: #4F3422;
  --grey60: #736B66; --grey30: #C9C7C5; --stone: #E8DDD9; --green: #9BB068; --white: #FFFFFF;
  --ok-bg: #E3EFD0; --ok: #3E5A18; --info-bg: #FFF0CC; --info: #7A4B00;
}
* { box-sizing: border-box; }
html, body { margin: 0; min-height: 100%; }
body { background: var(--cream); color: var(--brown); font: 500 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
.hero { background: var(--red); color: var(--white); padding: 34px 20px 64px; border-radius: 0 0 34px 34px; text-align: center; }
.brand { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; }
.logo { width: 36px; height: 36px; border-radius: 12px; background: var(--white); color: var(--red); display: grid; place-items: center; font-weight: 900; }
.card { background: var(--white); max-width: 440px; margin: -40px auto 32px; border-radius: 24px; padding: 28px 24px; box-shadow: 0 1px 2px rgba(79,52,34,.05), 0 6px 20px rgba(79,52,34,.06); }
@media (max-width: 480px) { .card { margin: -40px 16px 24px; padding: 24px 18px; } }
h1 { font-size: 24px; line-height: 1.2; letter-spacing: -.4px; margin: 0 0 8px; }
p { margin: 0 0 16px; }
.muted { color: var(--grey60); }
label { display: block; font-weight: 800; font-size: 14px; margin: 0 0 8px; }
.field { margin-bottom: 16px; }
.input-wrap { position: relative; }
input { width: 100%; height: 56px; border-radius: 999px; border: 2px solid var(--stone); background: var(--white); padding: 0 56px 0 22px; font: inherit; color: var(--brown); }
input:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(155,176,104,.25); }
input[aria-invalid="true"] { border-color: var(--red); }
.eye { position: absolute; right: 8px; top: 8px; width: 40px; height: 40px; border: 0; border-radius: 50%; background: transparent; color: var(--grey60); cursor: pointer; font: 700 13px system-ui; }
.eye:focus-visible { outline: 3px solid var(--green); }
.rules { list-style: none; padding: 0; margin: 4px 0 18px; font-size: 14px; color: var(--grey60); }
.rules li { display: flex; align-items: center; gap: 8px; margin: 4px 0; }
.rules li::before { content: ""; width: 16px; height: 16px; border-radius: 50%; border: 2px solid var(--grey30); flex: none; }
.rules li.ok { color: var(--ok); }
.rules li.ok::before { background: var(--green); border-color: var(--green); }
button.primary { width: 100%; height: 56px; border: 0; border-radius: 999px; background: var(--red); color: var(--white); font: 800 16px system-ui, sans-serif; cursor: pointer; }
button.primary:hover { background: var(--red-deep); }
button.primary:disabled { background: var(--grey30); cursor: not-allowed; }
button.primary:focus-visible { outline: 3px solid var(--green); outline-offset: 2px; }
a.primary { display: grid; place-items: center; width: 100%; height: 56px; margin-top: 20px; border-radius: 999px; background: var(--red); color: var(--white); font: 800 16px system-ui, sans-serif; text-decoration: none; }
a.primary:hover { background: var(--red-deep); }
a.primary:focus-visible { outline: 3px solid var(--green); outline-offset: 2px; }
.banner { border-radius: 18px; padding: 14px 16px; margin-bottom: 16px; font-weight: 700; font-size: 14.5px; }
.banner.error { background: var(--red-soft); color: var(--red-deep); }
.banner.ok { background: var(--ok-bg); color: var(--ok); }
.banner.info { background: var(--info-bg); color: var(--info); }
.field-error { color: var(--red-deep); font-size: 13px; font-weight: 800; margin-top: 8px; padding-left: 8px; }
.center { text-align: center; }
.spinner { width: 28px; height: 28px; border: 3px solid var(--stone); border-top-color: var(--red); border-radius: 50%; margin: 12px auto; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
[hidden] { display: none !important; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
</head>
<body>
<header class="hero"><span class="brand"><span class="logo" aria-hidden="true">M</span>MindEase</span></header>

<main class="card" aria-live="polite">
  {{-- 1. Checking the link --}}
  <section id="checking" class="center">
    <div class="spinner" aria-hidden="true"></div>
    <p class="muted">Checking your reset link…</p>
  </section>

  {{-- 2. Set a new password --}}
  <section id="reset" hidden>
    <h1>Choose a new password</h1>
    <p class="muted">For <b id="reset-email"></b>. After this you'll be signed out on all your devices.</p>
    <div id="reset-error" class="banner error" role="alert" hidden></div>
    <form id="reset-form" novalidate>
      <div class="field">
        <label for="password">New password</label>
        <div class="input-wrap">
          <input id="password" type="password" autocomplete="new-password" placeholder="At least 8 characters" required>
          <button type="button" class="eye" data-for="password" aria-label="Show password" aria-pressed="false">Show</button>
        </div>
        <div id="password-error" class="field-error" role="alert" hidden></div>
      </div>
      <div class="field">
        <label for="confirm">Confirm new password</label>
        <div class="input-wrap">
          <input id="confirm" type="password" autocomplete="new-password" placeholder="Type it again" required>
          <button type="button" class="eye" data-for="confirm" aria-label="Show password" aria-pressed="false">Show</button>
        </div>
      </div>
      <ul class="rules" aria-label="Password requirements">
        <li id="rule-length">At least 8 characters<span class="sr-only"> (needed)</span></li>
        <li id="rule-match">Both passwords match<span class="sr-only"> (needed)</span></li>
      </ul>
      <button id="reset-submit" class="primary" type="submit" disabled>Save new password</button>
    </form>
  </section>

  {{-- 3. Done --}}
  <section id="done" hidden>
    <div class="banner ok" role="status">Your password has been changed.</div>
    <h1>You're all set</h1>
    @if ($panelUrl)
      <p class="muted">Sign in to the MindEase panel with your new password. You've been signed out on your other devices.</p>
      <a class="primary" href="{{ $panelUrl }}">Go to sign in</a>
    @else
      <p class="muted">Open the MindEase app and sign in with your new password. You've been signed out on your other devices.</p>
    @endif
  </section>

  {{-- 4. Link wrong or expired: send a new one --}}
  <section id="expired" hidden>
    <h1 id="expired-title">This link has expired</h1>
    <p class="muted" id="expired-text">Reset links work once and only for {{ $minutes }} minutes. Enter your email and we'll send you a new one.</p>
    <div id="request-error" class="banner error" role="alert" hidden></div>
    <div id="request-ok" class="banner ok" role="status" hidden></div>
    <form id="request-form" novalidate>
      <div class="field">
        <label for="request-email">Email</label>
        <input id="request-email" type="email" autocomplete="email" placeholder="you@example.com" required>
      </div>
      <button id="request-submit" class="primary" type="submit">Send a new link</button>
    </form>
  </section>
</main>

<script nonce="{{ $nonce }}">
(() => {
  const API = @json($apiBase);
  const params = new URLSearchParams(location.search);
  const token = params.get('token') || '';
  const email = params.get('email') || '';
  // Keep the token out of the address bar, history and any shared screenshot.
  history.replaceState(null, '', location.pathname);

  const $ = (id) => document.getElementById(id);
  const show = (id) => ['checking', 'reset', 'done', 'expired'].forEach((s) => { $(s).hidden = s !== id; });
  const setMsg = (id, text) => { $(id).textContent = text || ''; $(id).hidden = !text; };

  async function call(path, body) {
    try {
      const res = await fetch(API + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const text = res.status === 204 || res.status === 202 ? '' : await res.text();
      const data = text ? JSON.parse(text) : null;
      return { status: res.status, error: data && data.error };
    } catch {
      return { status: 0, error: { code: 'network', message: 'We couldn\'t reach MindEase. Check your connection and try again.' } };
    }
  }

  function expired(title, text) {
    if (title) $('expired-title').textContent = title;
    if (text) $('expired-text').textContent = text;
    $('request-email').value = email;
    show('expired');
  }

  // ---- 1. Check the link ----
  async function start() {
    if (!token || !email) {
      return expired('This link is incomplete', 'Open the link straight from the email, or enter your email to get a new one.');
    }
    const r = await call('/auth/password-reset/verify', { email, token });
    if (r.status === 204) { $('reset-email').textContent = email; show('reset'); $('password').focus(); return; }
    if (r.status === 429) return expired('Too many attempts', 'Please wait a while, then ask for a new link.');
    if (r.status === 0) return expired('Something went wrong', r.error.message);
    expired();
  }

  // ---- 2. New password ----
  const pw = $('password');
  const confirmPw = $('confirm');
  function checkRules() {
    const lengthOk = pw.value.length >= 8;
    const matchOk = pw.value.length > 0 && pw.value === confirmPw.value;
    [['rule-length', lengthOk], ['rule-match', matchOk]].forEach(([id, ok]) => {
      $(id).classList.toggle('ok', ok);
      $(id).querySelector('.sr-only').textContent = ok ? ' (done)' : ' (needed)';
    });
    $('reset-submit').disabled = !(lengthOk && matchOk);
  }
  pw.addEventListener('input', () => { checkRules(); setMsg('password-error', ''); pw.removeAttribute('aria-invalid'); });
  confirmPw.addEventListener('input', checkRules);

  document.querySelectorAll('.eye').forEach((b) => b.addEventListener('click', () => {
    const input = $(b.dataset.for);
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    b.textContent = showing ? 'Show' : 'Hide';
    b.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    b.setAttribute('aria-pressed', String(!showing));
  }));

  $('reset-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if ($('reset-submit').disabled) return;
    $('reset-submit').disabled = true;
    $('reset-submit').textContent = 'Saving…';
    setMsg('reset-error', '');
    const r = await call('/auth/password-reset/confirm', { email, token, password: pw.value });
    $('reset-submit').textContent = 'Save new password';
    if (r.status === 204) { pw.value = confirmPw.value = ''; show('done'); return; }
    if (r.error && r.error.code === 'invalid_reset_token') return expired();
    if (r.error && r.error.field === 'password') {
      setMsg('password-error', r.error.message);
      pw.setAttribute('aria-invalid', 'true');
    } else {
      setMsg('reset-error', r.status === 429 ? 'Too many attempts. Please wait a while and try again.' : (r.error ? r.error.message : 'Something went wrong. Please try again.'));
    }
    checkRules();
  });

  // ---- 4. Ask for a new link ----
  $('request-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const to = $('request-email').value.trim();
    setMsg('request-error', ''); setMsg('request-ok', '');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) return setMsg('request-error', 'Please enter a valid email address.');
    $('request-submit').disabled = true;
    const r = await call('/auth/password-reset', { email: to });
    $('request-submit').disabled = false;
    if (r.status === 202) setMsg('request-ok', `If there's a MindEase account for ${to}, we've sent it a new link. Check your inbox and spam folder.`);
    else setMsg('request-error', r.status === 429 ? 'Too many requests. Please wait a while and try again.' : (r.error ? r.error.message : 'Something went wrong. Please try again.'));
  });

  start();
})();
</script>
</body>
</html>
