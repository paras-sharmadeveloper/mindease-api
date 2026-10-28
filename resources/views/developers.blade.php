<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MindEase API Docs</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script>window.MINDEASE = { baseUrl: @json($baseUrl) };</script>
@verbatim
<style>
:root {
  --red: #D64938; --red-deep: #B93A2B; --red-soft: #FBE3DF;
  --brown: #4F3422; --ink: #3F3B39; --grey: #736B66; --grey30: #C9C7C5;
  --stone: #E8DDD9; --cream: #F7F4F2; --green: #9BB068; --link: #3F7F63;
  --orange: #ED7E1C; --purple: #A694F5;
  --bg: #FFFFFF; --side: var(--cream); --line: var(--stone); --heading: var(--brown); --text: var(--ink); --muted: var(--grey);
  --chip: #F1ECE9; --code-bg: #2B221D; --code-head: #362B25; --code-text: #EDE6E1; --code-muted: #A3968E;
  --tok-key: #F2B8A0; --tok-str: #B9D08A; --tok-num: #C8B8FF; --tok-kw: #FFCE5C;
  --get: #3F7F63; --post: #2F6FBF; --put: #B7791F; --delete: #C0392B;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg: #1C1714; --side: #231D19; --line: #3A302A; --heading: #F3E9E3; --text: #DED5CF; --muted: #A69A92;
    --chip: #2C2420; --red-soft: #3A2320; --link: #8CC7A9; --code-bg: #120F0D; --code-head: #1A1512;
  }
}
:root[data-theme="dark"] {
  --bg: #1C1714; --side: #231D19; --line: #3A302A; --heading: #F3E9E3; --text: #DED5CF; --muted: #A69A92;
  --chip: #2C2420; --red-soft: #3A2320; --link: #8CC7A9; --code-bg: #120F0D; --code-head: #1A1512;
}
* { box-sizing: border-box; }
[hidden] { display: none !important; }
html { scroll-padding-top: 76px; }
body { margin: 0; background: var(--bg); color: var(--text); font: 500 15px/1.6 Urbanist, system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; }
a { color: var(--link); text-decoration: none; } a:hover { text-decoration: underline; }
code, pre, .mono { font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; }
:not(pre) > code { background: var(--chip); padding: 1px 6px; border-radius: 6px; font-size: .86em; color: var(--heading); word-break: break-word; }
h1, h2, h3, h4 { color: var(--heading); font-weight: 800; letter-spacing: -.4px; line-height: 1.25; }
h1 { font-size: 34px; margin: 0 0 10px; letter-spacing: -1px; }
h2 { font-size: 26px; margin: 0 0 12px; }
h3 { font-size: 18px; margin: 28px 0 8px; }
p { margin: 0 0 14px; }
ul, ol { padding-left: 20px; margin: 0 0 14px; } li { margin: 4px 0; }

/* Top bar */
.top { position: sticky; top: 0; z-index: 30; height: 60px; display: flex; align-items: center; gap: 14px; padding: 0 20px; background: var(--bg); border-bottom: 1px solid var(--line); }
.brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 17px; color: var(--heading); white-space: nowrap; }
.brand .dot { width: 30px; height: 30px; border-radius: 10px; background: var(--red); display: grid; place-items: center; color: #fff; font-size: 15px; }
.brand small { color: var(--muted); font-weight: 600; font-size: 14px; }
.search { flex: 1; max-width: 360px; margin-left: 12px; }
.search input { width: 100%; height: 36px; border-radius: 999px; border: 1px solid var(--line); background: var(--side); color: var(--text); padding: 0 14px; font: inherit; font-size: 14px; }
.search input:focus { outline: 2px solid var(--green); outline-offset: 1px; }
.top .spacer { flex: 1; }
.top a.toplink { color: var(--muted); font-weight: 700; font-size: 14px; white-space: nowrap; }
.btn { height: 36px; border-radius: 999px; border: 0; padding: 0 16px; font: 700 14px Urbanist, sans-serif; cursor: pointer; white-space: nowrap; }
.btn-red { background: var(--red); color: #fff; } .btn-red:hover { background: var(--red-deep); }
.btn-ghost { background: var(--chip); color: var(--heading); }
.auth-chip { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--muted); }
.auth-chip i { width: 8px; height: 8px; border-radius: 50%; background: var(--grey30); display: inline-block; }
.auth-chip.on i { background: var(--green); }
.menu-btn { display: none; }

/* Layout */
.layout { display: grid; grid-template-columns: 260px minmax(0, 1fr); }
nav.side { position: sticky; top: 60px; height: calc(100vh - 60px); overflow-y: auto; background: var(--side); border-right: 1px solid var(--line); padding: 20px 14px 40px; }
nav.side h5 { margin: 18px 10px 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); font-weight: 800; }
nav.side a { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 10px; color: var(--text); font-size: 14px; font-weight: 600; }
nav.side a:hover { background: var(--chip); text-decoration: none; }
nav.side a.active { background: var(--red-soft); color: var(--red-deep); }
:root[data-theme="dark"] nav.side a.active { color: #F6B3A8; }
nav.side a .m { font: 700 10px "JetBrains Mono", monospace; width: 44px; flex: none; }
main { min-width: 0; }
section.doc { padding: 48px 48px 40px; border-bottom: 1px solid var(--line); max-width: 1240px; }
.eyebrow { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 800; color: var(--red); margin-bottom: 8px; }
.lead { font-size: 17px; color: var(--muted); max-width: 720px; }
.prose { max-width: 720px; }
.split { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 40px; align-items: start; }
.split > .code-col { position: sticky; top: 80px; }

/* Cards / callouts / tables */
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin: 22px 0; }
.card { border: 1px solid var(--line); border-radius: 18px; padding: 18px; background: var(--bg); color: var(--text); display: block; }
.card:hover { border-color: var(--red); text-decoration: none; }
.card b { display: block; color: var(--heading); font-size: 16px; margin-bottom: 4px; }
.card span { color: var(--muted); font-size: 14px; }
.note { border-radius: 14px; padding: 12px 16px; margin: 0 0 16px; background: var(--chip); border-left: 4px solid var(--green); font-size: 14.5px; }
.note.warn { border-left-color: var(--orange); }
.note.danger { border-left-color: var(--red); background: var(--red-soft); }
.note b:first-child { color: var(--heading); }
table { width: 100%; border-collapse: collapse; margin: 0 0 18px; font-size: 14px; }
th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); font-weight: 800; padding: 8px 10px; border-bottom: 1px solid var(--line); }
td { padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
td code { white-space: nowrap; }
.tbl-wrap { overflow-x: auto; }
.req { color: var(--red); font-size: 11px; font-weight: 800; text-transform: uppercase; margin-left: 6px; }
.opt { color: var(--muted); font-size: 11px; font-weight: 700; margin-left: 6px; }
.type { color: var(--muted); font: 12px "JetBrains Mono", monospace; display: block; }
.steps { counter-reset: s; list-style: none; padding: 0; }
.steps > li { counter-increment: s; position: relative; padding-left: 40px; margin-bottom: 14px; }
.steps > li::before { content: counter(s); position: absolute; left: 0; top: 0; width: 26px; height: 26px; border-radius: 50%; background: var(--red); color: #fff; font-weight: 800; font-size: 13px; display: grid; place-items: center; }

/* Method badges */
.badge { display: inline-block; font: 700 11px "JetBrains Mono", monospace; padding: 3px 8px; border-radius: 6px; color: #fff; letter-spacing: .3px; }
.GET { background: var(--get); } .POST { background: var(--post); } .PUT { background: var(--put); } .DELETE { background: var(--delete); }
nav.side a .m.GET, nav.side a .m.POST, nav.side a .m.PUT, nav.side a .m.DELETE { background: none; }
nav.side .m.GET { color: var(--get); } nav.side .m.POST { color: var(--post); } nav.side .m.PUT { color: var(--put); } nav.side .m.DELETE { color: var(--delete); }
.endpoint-line { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 4px 0 16px; padding: 10px 14px; border-radius: 14px; background: var(--chip); }
.endpoint-line code { background: none; padding: 0; font-size: 14px; color: var(--heading); }
.tags { display: flex; gap: 6px; flex-wrap: wrap; margin-left: auto; }
.tag { font-size: 11.5px; font-weight: 800; padding: 2px 9px; border-radius: 999px; background: var(--bg); color: var(--muted); border: 1px solid var(--line); }
.tag.lock { color: var(--red-deep); }
.ep-actions { display: flex; gap: 8px; margin: 18px 0 0; flex-wrap: wrap; }
select.ex { height: 34px; border-radius: 999px; border: 1px solid var(--line); background: var(--bg); color: var(--text); padding: 0 12px; font: 700 13px Urbanist, sans-serif; }

/* Code panels */
.codebox { background: var(--code-bg); border-radius: 16px; overflow: hidden; margin: 0 0 16px; color: var(--code-text); }
.codebox .head { display: flex; align-items: center; gap: 4px; padding: 6px 8px 0 14px; background: var(--code-head); min-height: 40px; }
.codebox .title { font-size: 12.5px; font-weight: 700; color: var(--code-muted); margin-right: auto; padding: 6px 0; }
.codebox .tab { background: none; border: 0; color: var(--code-muted); font: 700 12.5px Urbanist, sans-serif; padding: 8px 10px; cursor: pointer; border-bottom: 2px solid transparent; }
.codebox .tab.on { color: #fff; border-bottom-color: var(--red); }
.codebox .copy { background: none; border: 1px solid #5a4a40; color: var(--code-muted); border-radius: 8px; font: 700 11.5px Urbanist, sans-serif; padding: 4px 9px; cursor: pointer; margin: 0 4px 6px 8px; }
.codebox .copy:hover { color: #fff; }
.codebox pre { margin: 0; padding: 14px 16px; overflow: auto; font-size: 12.8px; line-height: 1.6; max-height: 480px; }
.codebox .status { font: 700 12px "JetBrains Mono", monospace; padding: 2px 8px; border-radius: 6px; background: #3b4a2a; color: #cfe6a6; }
.codebox .status.err { background: #4a2a26; color: #f6b3a8; }
.k { color: var(--tok-key); } .s { color: var(--tok-str); } .n { color: var(--tok-num); } .kw { color: var(--tok-kw); } .c { color: var(--code-muted); font-style: italic; }

/* Try-it console */
.console { position: fixed; top: 60px; right: 0; bottom: 0; width: min(560px, 100vw); background: var(--bg); border-left: 1px solid var(--line); z-index: 40; transform: translateX(100%); transition: transform .22s ease; display: flex; flex-direction: column; }
.console.open { transform: none; box-shadow: -20px 0 40px rgba(0,0,0,.08); }
.console header { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--line); }
.console header b { color: var(--heading); font-size: 16px; margin-right: auto; }
.console .body { padding: 16px 18px; overflow-y: auto; flex: 1; }
.console label { display: block; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); margin: 12px 0 6px; }
.console input[type=text], .console input[type=email], .console input[type=password], .console textarea { width: 100%; border: 1px solid var(--line); background: var(--side); color: var(--text); border-radius: 12px; padding: 9px 12px; font: 13px "JetBrains Mono", monospace; }
.console textarea { min-height: 170px; resize: vertical; }
.console .urlrow { display: flex; gap: 8px; align-items: center; }
.console .urlrow input { flex: 1; }
.console .hint { font-size: 12.5px; color: var(--muted); margin-top: 6px; }
.console .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 14px; }
.console .check { display: flex; gap: 8px; align-items: center; font-size: 13.5px; font-weight: 600; text-transform: none; letter-spacing: 0; color: var(--text); margin: 6px 0; }
.console .meta { font: 12px "JetBrains Mono", monospace; color: var(--code-muted); }
.overlay { position: fixed; inset: 60px 0 0 0; background: rgba(30,20,15,.25); z-index: 35; display: none; }
.overlay.show { display: block; }
.signin { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; align-items: end; }

footer { padding: 30px 48px 60px; color: var(--muted); font-size: 13.5px; }

@media (max-width: 1150px) {
  .split { grid-template-columns: minmax(0, 1fr); gap: 8px; }
  .split > .code-col { position: static; }
}
@media (max-width: 900px) {
  .layout { grid-template-columns: minmax(0, 1fr); }
  nav.side { position: fixed; left: 0; top: 60px; width: 280px; z-index: 36; transform: translateX(-100%); transition: transform .2s; }
  nav.side.open { transform: none; }
  .menu-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; padding: 0; }
  .search, .top a.toplink, .brand small { display: none; }
  section.doc { padding: 32px 16px; }
  footer { padding: 24px 16px 50px; }
  h1 { font-size: 28px; }
  .signin { grid-template-columns: 1fr; }
  .auth-chip span { display: none; }
}
</style>
</head>
<body>

<header class="top">
  <button class="btn btn-ghost menu-btn" id="menuBtn" aria-label="Menu">☰</button>
  <div class="brand"><span class="dot">M</span> MindEase <small>Developers · API v1</small></div>
  <div class="search"><input id="search" type="search" placeholder="Search endpoints and guides…  ( / )" aria-label="Search"></div>
  <div class="spacer"></div>
  <a class="toplink" href="/docs/api" target="_blank" rel="noopener">OpenAPI reference ↗</a>
  <a class="toplink" href="/docs/api.json" target="_blank" rel="noopener">Postman / JSON ↗</a>
  <span class="auth-chip" id="authChip"><i></i><span>Not signed in</span></span>
  <button class="btn btn-red" id="openConsole">Try it</button>
  <button class="btn btn-ghost" id="themeBtn" aria-label="Toggle theme" title="Toggle theme">◐</button>
</header>
<div class="overlay" id="overlay"></div>

<div class="layout">
<nav class="side" id="side">
  <h5>Get started</h5>
  <a href="#introduction">Introduction</a>
  <a href="#quickstart">Quickstart</a>
  <a href="#authentication">Authentication</a>
  <a href="#errors">Errors</a>
  <a href="#rate-limits">Rate limits</a>
  <a href="#idempotency">Idempotency &amp; offline queue</a>
  <a href="#caching">Caching content</a>
  <h5>Guides</h5>
  <a href="#guide-onboarding">Onboarding questionnaire</a>
  <a href="#guide-progress">Progress, video &amp; quiz</a>
  <a href="#guide-booking">Booking &amp; payment</a>
  <a href="#guide-video">In-app video call</a>
  <a href="#guide-account">Account deletion</a>
  <div id="navRef"></div>
</nav>

<main id="main">

<!-- ============ INTRODUCTION ============ -->
<section class="doc" id="introduction" data-search="introduction overview base url conventions">
  <div class="eyebrow">MindEase API v1</div>
  <h1>Build the MindEase app on our API</h1>
  <p class="lead">This REST API powers the MindEase Flutter app on Android and iOS: sign-in, the onboarding questionnaire, programs and phase content, progress and points, therapist booking with Stripe, and Zoom video sessions.</p>

  <div class="cards">
    <a class="card" href="#quickstart"><b>Quickstart →</b><span>Make your first request in five minutes.</span></a>
    <a class="card" href="#authentication"><b>Authentication →</b><span>Access tokens, refresh rotation and secure storage.</span></a>
    <a class="card" href="#guide-progress"><b>Progress events →</b><span>Lessons, video seconds, activities and quizzes.</span></a>
    <a class="card" href="#guide-booking"><b>Booking &amp; payments →</b><span>Slots, Stripe PaymentSheet and Zoom calls.</span></a>
  </div>

  <div class="split">
    <div class="prose">
      <h3>Base URL</h3>
      <p>All endpoints are relative to the base URL below. Use HTTPS outside local development.</p>
      <div class="tbl-wrap"><table>
        <tr><th>Environment</th><th>Base URL</th></tr>
        <tr><td>This server</td><td><code class="js-base"></code></td></tr>
        <tr><td>Staging</td><td><code>https://staging-api.&lt;domain&gt;/v1</code></td></tr>
        <tr><td>Production</td><td><code>https://api.&lt;domain&gt;/v1</code></td></tr>
      </table></div>
      <h3>Conventions</h3>
      <ul>
        <li>Request and response bodies are JSON. Send <code>Accept: application/json</code> on every request.</li>
        <li>Field names are <code>camelCase</code>. Timestamps are ISO 8601 in UTC, for example <code>2026-10-26T11:45:24.000Z</code>.</li>
        <li>User, therapist and booking ids are UUIDs. Content ids such as <code>mhfa</code>, <code>p1</code> and <code>p1l1</code> are stable strings.</li>
        <li>Money is in cents (<code>priceCents: 15000</code> = A$150.00, GST inclusive). Currency is lowercase ISO (<code>aud</code>).</li>
        <li>Every endpoint except <code>/auth/*</code> needs <code>Authorization: Bearer &lt;accessToken&gt;</code>.</li>
        <li>Read decimals as <code>num</code> in Dart: <code>(json['position'] as num).toDouble()</code>. A value like <code>63.0</code> may arrive as <code>63</code>.</li>
      </ul>
      <div class="note"><b>Demo account.</b> On local and staging, sign in with <code>demo@mindease.test</code> / <code>password123</code>. The <b>Try it</b> button at the top opens a console that sends real requests to this server.</div>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">Health check</span></div><pre class="js-hl" data-lang="sh">curl <span class="js-origin"></span>/up</pre></div>
      <div class="codebox"><div class="head"><span class="title">Every response under /v1 · error shape</span></div><pre class="js-json">{"error":{"code":"phase_locked","message":"Complete the previous phase to unlock this one.","field":"phaseId"}}</pre></div>
    </div>
  </div>
</section>

<!-- ============ QUICKSTART ============ -->
<section class="doc" id="quickstart" data-search="quickstart dio flutter setup first request">
  <div class="eyebrow">Get started</div>
  <h2>Quickstart</h2>
  <div class="split">
    <div class="prose">
      <ol class="steps">
        <li><b>Add the packages.</b> We use <code>dio</code> for HTTP, <code>flutter_secure_storage</code> for tokens and <code>uuid</code> for idempotency keys. The samples on this page use these packages.</li>
        <li><b>Create one API client</b> with the base URL and JSON headers. Every sample on this page calls it <code>api</code>.</li>
        <li><b>Sign in</b> with <code>POST /auth/login</code> and store both tokens in secure storage.</li>
        <li><b>Load the program</b> with <code>GET /programs/mhfa</code> and the user's progress with <code>GET /me/programs/mhfa/progress</code>.</li>
        <li><b>Send an event</b> when the user finishes something, such as <code>lesson_read</code>. The response holds the new progress and, when a phase completes, the points awarded.</li>
      </ol>
      <div class="note warn"><b>Never compute points or completion in the app.</b> The app sends events and shows whatever the server returns. The server decides when a phase is complete and how many points it is worth.</div>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">pubspec.yaml</span><button class="copy">Copy</button></div><pre>dependencies:
  dio: ^5.7.0
  flutter_secure_storage: ^9.2.2
  uuid: ^4.5.1</pre></div>
      <div class="codebox"><div class="head"><span class="title">lib/core/api/api_client.dart</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">import 'package:dio/dio.dart';

const baseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'https://api.example.com/v1',
);

final api = Dio(BaseOptions(
  baseUrl: baseUrl,
  connectTimeout: const Duration(seconds: 10),
  receiveTimeout: const Duration(seconds: 20),
  headers: {'Accept': 'application/json'},
  contentType: Headers.jsonContentType,
))
  ..interceptors.add(AuthInterceptor()); // see Authentication

// 1. sign in
final res = await api.post('/auth/login', data: {
  'email': 'demo@mindease.test',
  'password': 'password123',
});
await tokens.save(res.data['accessToken'], res.data['refreshToken']);

// 2. load content + progress
final program = (await api.get('/programs/mhfa')).data;
final progress = (await api.get('/me/programs/mhfa/progress')).data;

// 3. send an event
final r = await api.post('/me/programs/mhfa/events',
  data: {'type': 'lesson_read', 'phaseId': 'p1', 'lessonId': 'p1l1'},
  options: Options(headers: {'Idempotency-Key': const Uuid().v4()}),
);
final completion = r.data['completion']; // null or { phaseId, pointsAwarded, ... }</pre></div>
    </div>
  </div>
</section>

<!-- ============ AUTH ============ -->
<section class="doc" id="authentication" data-search="authentication token refresh rotation secure storage interceptor logout reset deep link">
  <div class="eyebrow">Get started</div>
  <h2>Authentication</h2>
  <div class="split">
    <div class="prose">
      <p>Sign-in returns two tokens. Send the <b>access token</b> as <code>Authorization: Bearer …</code>. When it expires, exchange the <b>refresh token</b> for a new pair.</p>
      <div class="tbl-wrap"><table>
        <tr><th>Token</th><th>Lifetime</th><th>Notes</th></tr>
        <tr><td>accessToken</td><td>15 minutes</td><td>Keep it in memory or secure storage.</td></tr>
        <tr><td>refreshToken</td><td>60 days</td><td><b>Rotated on every refresh.</b> Store it only in <code>flutter_secure_storage</code> (Keychain or Keystore).</td></tr>
      </table></div>
      <h3>Refresh rules</h3>
      <ul>
        <li>When a request returns <code>401 unauthenticated</code>, call <code>POST /auth/refresh</code> once and then replay the request.</li>
        <li><b>Always save the new refresh token.</b> Sending an old refresh token again counts as theft: the server revokes every token from that sign-in and returns <code>401 invalid_refresh_token</code>.</li>
        <li>Run <b>one refresh at a time</b>. If several requests fail at once, they all wait for the same refresh call. Never start two refreshes in parallel.</li>
        <li>If the refresh fails with <code>401</code>, clear the stored tokens and show Sign In.</li>
      </ul>
      <h3>Splash and logout</h3>
      <p>On the splash screen, call <code>GET /me</code> when a refresh token exists. The interceptor refreshes the access token when needed. To log out, call <code>POST /auth/logout</code> with the refresh token, then clear storage. Clear storage even if the call fails.</p>
      <h3>Password reset</h3>
      <p><code>POST /auth/password-reset</code> always returns <code>202</code>. The email links to <code>mindease://reset-password?token=…&amp;email=…</code>. Handle that deep link, ask for a new password and call <code>POST /auth/password-reset/confirm</code>. The confirm call signs out every device.</p>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">auth_interceptor.dart · single-flight refresh</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">class AuthInterceptor extends QueuedInterceptor {
  // QueuedInterceptor runs errors one at a time, so only one
  // refresh is ever in flight.
  final _refreshDio = Dio(BaseOptions(baseUrl: baseUrl));

  @override
  void onRequest(RequestOptions o, RequestInterceptorHandler h) async {
    final token = await tokens.access();
    if (token != null) o.headers['Authorization'] = 'Bearer $token';
    h.next(o);
  }

  @override
  void onError(DioException e, ErrorInterceptorHandler h) async {
    final is401 = e.response?.statusCode == 401;
    final isAuthCall = e.requestOptions.path.startsWith('/auth/');
    if (!is401 || isAuthCall) return h.next(e);

    // Another request may have refreshed while this one waited.
    final sent = e.requestOptions.headers['Authorization'];
    final current = await tokens.access();
    if (current != null && sent != 'Bearer $current') {
      return h.resolve(await _retry(e.requestOptions, current));
    }

    try {
      final r = await _refreshDio.post('/auth/refresh',
          data: {'refreshToken': await tokens.refresh()});
      await tokens.save(r.data['accessToken'], r.data['refreshToken']);
      h.resolve(await _retry(e.requestOptions, r.data['accessToken']));
    } on DioException {
      await tokens.clear();
      authState.signedOut(); // route to Sign In
      h.next(e);
    }
  }

  Future&lt;Response&gt; _retry(RequestOptions o, String token) =>
      api.fetch(o..headers['Authorization'] = 'Bearer $token');
}</pre></div>
    </div>
  </div>
</section>

<!-- ============ ERRORS ============ -->
<section class="doc" id="errors" data-search="errors error codes validation field 422 401 403 404 409 429">
  <div class="eyebrow">Get started</div>
  <h2>Errors</h2>
  <div class="split">
    <div class="prose">
      <p>Every error has the same shape. <code>message</code> is plain English and safe to show to the user. <code>field</code> names the form input to highlight, or is <code>null</code>. Validation errors also include <code>fields</code>, which lists every message for every input.</p>
      <p>Switch on <code>error.code</code>, not on the message text.</p>
      <div class="tbl-wrap"><table id="errorTable"><tr><th>Status</th><th>Code</th><th>What to do</th></tr></table></div>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">422 · validation_failed</span></div><pre class="js-json">{"error":{"code":"validation_failed","message":"The email field must be a valid email address.","field":"email","fields":{"email":["The email field must be a valid email address."],"password":["The password field must be at least 8 characters."]}}}</pre></div>
      <div class="codebox"><div class="head"><span class="title">api_error.dart</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">class ApiError implements Exception {
  ApiError(this.status, this.code, this.message, this.field);
  final int status;
  final String code, message;
  final String? field;

  factory ApiError.from(DioException e) {
    final err = (e.response?.data as Map?)?['error'] as Map?;
    if (err == null) {
      // no response: offline or timeout
      return ApiError(0, 'network', 'Check your connection and try again.', null);
    }
    return ApiError(e.response!.statusCode!, err['code'], err['message'], err['field']);
  }
}

// Sign Up form
on DioException catch (e) {
  final err = ApiError.from(e);
  if (err.field != null) setFieldError(err.field!, err.message);
  else showSnack(err.message);
}</pre></div>
    </div>
  </div>
</section>

<!-- ============ RATE LIMITS ============ -->
<section class="doc" id="rate-limits" data-search="rate limits throttle 429 retry-after">
  <div class="eyebrow">Get started</div>
  <h2>Rate limits</h2>
  <div class="prose">
    <p>A request over a limit gets <code>429 rate_limited</code> with a <code>Retry-After</code> header in seconds. Authenticated responses include <code>X-RateLimit-Limit</code> and <code>X-RateLimit-Remaining</code>.</p>
    <div class="tbl-wrap"><table>
      <tr><th>Endpoint</th><th>Limit</th></tr>
      <tr><td><code>POST /auth/login</code></td><td>5 per minute per email, and 30 per minute per IP</td></tr>
      <tr><td><code>POST /auth/password-reset</code>, <code>/confirm</code></td><td>5 per hour per email, and 10 per minute per IP</td></tr>
      <tr><td>Other <code>/auth/*</code></td><td>30 per minute per IP</td></tr>
      <tr><td>Everything else</td><td>180 per minute per user</td></tr>
    </table></div>
    <div class="note">Throttle video progress events to about one every 10 seconds while playing, plus one on pause, seek and exit. That keeps each user well under the limit.</div>
  </div>
</section>

<!-- ============ IDEMPOTENCY ============ -->
<section class="doc" id="idempotency" data-search="idempotency key offline queue retry uuid replay">
  <div class="eyebrow">Get started</div>
  <h2>Idempotency &amp; offline queue</h2>
  <div class="split">
    <div class="prose">
      <p><code>POST /me/programs/{id}/events</code> and <code>POST /me/bookings</code> <b>require</b> an <code>Idempotency-Key</code> header. Use a new UUID v4 for each new action, and <b>send the same key again when you retry that action</b>. The server then runs each action only once, even after a timeout or on a flaky network.</p>
      <div class="tbl-wrap"><table>
        <tr><th>Situation</th><th>Response</th></tr>
        <tr><td>Same key, same body (replay)</td><td>The original response again, with header <code>Idempotent-Replayed: true</code></td></tr>
        <tr><td>Same key, different body</td><td><code>422 idempotency_key_reused</code>. This is a bug in the app; use a new key.</td></tr>
        <tr><td>Same key while the first request is still running</td><td><code>409 idempotency_in_progress</code>. Retry shortly.</td></tr>
      </table></div>
      <h3>Offline queue</h3>
      <ol>
        <li>Create the event and its key, and save both to a local queue (for example Hive or sqflite) <b>before</b> you send it.</li>
        <li>Update the UI optimistically.</li>
        <li>Send the queue in order. Remove an item after any <code>2xx</code> response or any <code>4xx</code> except <code>401</code>, <code>408</code>, <code>409</code> and <code>429</code>. A 4xx won't succeed on retry, so drop it.</li>
        <li>On network errors and <code>5xx</code>, keep the item and retry with backoff when connectivity returns.</li>
        <li>After the queue drains, use the last <code>progress</code> response as the source of truth.</li>
      </ol>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">event_queue.dart</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">class QueuedEvent {
  QueuedEvent(this.programId, this.body) : key = const Uuid().v4();
  final String programId, key;           // key is fixed for life
  final Map&lt;String, dynamic&gt; body;
}

Future&lt;void&gt; flush() async {
  while (queue.isNotEmpty) {
    final ev = queue.first;
    try {
      final r = await api.post('/me/programs/${ev.programId}/events',
          data: ev.body,
          options: Options(headers: {'Idempotency-Key': ev.key}));
      progressStore.apply(r.data['progress']);
      if (r.data['completion'] != null) showPhaseComplete(r.data['completion']);
      await queue.removeFirst();
    } on DioException catch (e) {
      final s = e.response?.statusCode;
      final retryable = s == null || s &gt;= 500 || [401, 408, 409, 429].contains(s);
      if (retryable) return scheduleRetry();   // backoff; same key
      await queue.removeFirst();               // 4xx: drop it
    }
  }
}</pre></div>
    </div>
  </div>
</section>

<!-- ============ CACHING ============ -->
<section class="doc" id="caching" data-search="caching etag if-none-match 304 content version offline">
  <div class="eyebrow">Get started</div>
  <h2>Caching content</h2>
  <div class="split">
    <div class="prose">
      <p>The content endpoints (<code>/config</code>, <code>/questionnaires/onboarding</code>, <code>/programs</code>, <code>/programs/{id}</code> and <code>/support-resources</code>) return an <code>ETag</code> and an <code>X-Content-Version</code> header. Store each body with its ETag. On the next launch, send <code>If-None-Match</code>. A <code>304 Not Modified</code> has no body, so use your cached copy.</p>
      <p>The app should ship with a bundled copy of the content, so it works offline on first launch.</p>
      <div class="note"><b>Quiz answers are in the program JSON</b> so the app can give instant feedback. The server still re-scores every submission.</div>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">content_cache.dart</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">Future&lt;Map&lt;String, dynamic&gt;&gt; getCached(String path) async {
  final cached = await cache.read(path); // { etag, body }
  final r = await api.get(path,
      options: Options(
        headers: {if (cached != null) 'If-None-Match': cached.etag},
        validateStatus: (s) =&gt; s == 200 || s == 304,
      ));
  if (r.statusCode == 304) return cached!.body;
  await cache.write(path, r.headers.value('etag')!, r.data);
  return r.data;
}</pre></div>
    </div>
  </div>
</section>

<!-- ============ GUIDE: ONBOARDING ============ -->
<section class="doc" id="guide-onboarding" data-search="guide onboarding questionnaire assessment resume retake support card">
  <div class="eyebrow">Guide</div>
  <h2>Onboarding questionnaire</h2>
  <div class="split">
    <div class="prose">
      <ol class="steps">
        <li><b>Load the questions</b> with <code>GET /questionnaires/onboarding</code>. Question types are <code>single</code>, <code>multi</code> and <code>mood</code>. Options marked <code>exclusive</code> clear the other selections.</li>
        <li><b>Resume.</b> Call <code>GET /me/assessment</code>. If <code>completedAt</code> is null and <code>step</code> &gt; 0, open the question at that step with the saved answers.</li>
        <li><b>Save on every tap.</b> <code>PUT /me/assessment</code> replaces the whole document, so always send every answer plus the current <code>step</code>. Debounce saves by about 300 ms.</li>
        <li><b>Support card.</b> Show the Get Help card in the app as soon as the user picks <i>Very low</i> mood, <i>Overwhelming</i> stress or <i>No support</i>. The server is not involved.</li>
        <li><b>Finish.</b> Send <code>completedAt</code> with the current UTC time. After the user sees Your Next Steps, send <code>nextStepsSeen: true</code>.</li>
        <li><b>Retake</b> from Profile: load <code>GET /me/assessment</code>, pre-select the saved answers, then send <code>completedAt: null</code> and <code>step: 0</code>.</li>
      </ol>
      <div class="note danger"><b>Sensitive data.</b> Answers are encrypted at rest and are only returned to their owner. Never log them or send them to analytics or crash reports.</div>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">PUT /me/assessment · body</span></div><pre class="js-json">{"answers":{"goals":["stress","sleep"],"mood":"low","stress":"medium"},"step":3,"completedAt":null,"nextStepsSeen":false,"questionnaireVersion":"1.0.0"}</pre></div>
    </div>
  </div>
</section>

<!-- ============ GUIDE: PROGRESS ============ -->
<section class="doc" id="guide-progress" data-search="guide progress events video watched seconds seeking 90 quiz activity journal lesson locked phase points completion">
  <div class="eyebrow">Guide</div>
  <h2>Progress, video &amp; quiz</h2>
  <div class="split">
    <div class="prose">
      <p>The app reports what the user did. The server applies the rules, unlocks phases and awards points. A phase is complete when all its lessons are read, all its videos are watched, its activity is complete and its quiz is passed (see <code>phaseRequirements</code> in <code>/config</code>).</p>
      <h3>Locked phases</h3>
      <p>Phase 1 is always open. Phase N+1 unlocks when phase N is complete. Use <code>progress.phases[id].unlocked</code> to decide what is tappable. Check it again for deep links. The server rejects every event on a locked phase with <code>403 phase_locked</code>.</p>
      <h3>Lessons</h3>
      <p>When the user taps <i>I've read this lesson</i>, send <code>lesson_read</code>. Sending it again does no harm.</p>
      <h3>Video: count seconds actually played</h3>
      <ul>
        <li>Keep a string with one character per second of <code>duration</code>, all <code>"0"</code> at first. Each second that <b>plays</b>, set that position to <code>"1"</code>. Seeking does not set any characters.</li>
        <li>Send <code>video_progress</code> with <code>position</code> (in seconds) and the <code>watched</code> string. The server merges strings with OR, so it never loses seconds already watched.</li>
        <li>The video is complete when watched seconds reach 90% of <code>duration</code> (<code>videoCompletePercent</code>). On return, seek to <code>videos[id].position</code>.</li>
      </ul>
      <h3>Activity (journal)</h3>
      <p>The answer keys are the prompt ids. <i>Save draft</i> sends <code>complete: false</code>. <i>Complete</i> sends <code>complete: true</code> and needs at least one non-empty answer, otherwise the server returns <code>422 activity_empty</code>. Once complete, it stays complete, and later saves only update the answers.</p>
      <h3>Quiz</h3>
      <p>Send one 0-based option index per question, in question order. The server scores the quiz and passes it at <code>round(correct/total×100) ≥ passThresholdPercent</code>. Retakes are unlimited and every attempt is stored.</p>
      <h3>Phase and program complete</h3>
      <p>When an event completes a phase, <code>completion</code> is not null. Show the Phase complete sheet with <code>pointsAwarded</code>. If <code>programComplete</code> is true, show the Program complete sheet with the disclaimer instead.</p>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">video_tracker.dart</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">class VideoTracker {
  VideoTracker(this.duration, String? saved)
      : bits = (saved ?? '').padRight(duration, '0').split('');
  final int duration;
  final List&lt;String&gt; bits;
  int? _lastSecond;

  /// Call from the player's position listener while playing.
  void onTick(Duration pos, {required bool isPlaying}) {
    final s = pos.inSeconds;
    // Only a step of 0 or 1 second counts; a bigger jump is a seek.
    if (isPlaying &amp;&amp; _lastSecond != null &amp;&amp; s - _lastSecond! &lt;= 1 &amp;&amp; s &lt; duration) {
      bits[s] = '1';
    }
    _lastSecond = s;
  }

  Map&lt;String, dynamic&gt; event(String phaseId, String videoId, Duration pos) =&gt; {
    'type': 'video_progress', 'phaseId': phaseId, 'videoId': videoId,
    'position': pos.inMilliseconds / 1000, 'watched': bits.join(),
  };
}</pre></div>
      <div class="codebox"><div class="head"><span class="title">completion (from any event response)</span></div><pre class="js-json">{"phaseId":"p1","pointsAwarded":100,"nextPhaseId":"p2","programComplete":false}</pre></div>
    </div>
  </div>
</section>

<!-- ============ GUIDE: BOOKING ============ -->
<section class="doc" id="guide-booking" data-search="guide booking therapist slots stripe payment sheet poll confirmed cancel refund">
  <div class="eyebrow">Guide</div>
  <h2>Booking &amp; payment</h2>
  <div class="split">
    <div class="prose">
      <ol class="steps">
        <li><b>List therapists</b> with <code>GET /therapists</code> and show one with <code>GET /therapists/{id}</code>.</li>
        <li><b>Load slots</b> with <code>GET /therapists/{id}/slots?from=…&amp;to=…</code>, at most 31 days at a time. Times are UTC; show them in the device's time zone and group them by local date.</li>
        <li><b>Hold the slot.</b> <code>POST /me/bookings</code> with an <code>Idempotency-Key</code> returns a <code>pending</code> booking and a Stripe <code>clientSecret</code>. The server sets the price.</li>
        <li><b>Pay</b> with Stripe's PaymentSheet (<code>flutter_stripe</code>) using <code>payment.clientSecret</code> and <code>payment.publishableKey</code>.</li>
        <li><b>Wait for confirmation.</b> When the sheet closes successfully, show <i>Confirming…</i>. Poll <code>GET /me/bookings/{id}</code> every 2 s, for up to 30 s, until <code>status</code> is <code>confirmed</code>. Only Stripe's webhook to our server can confirm a booking.</li>
      </ol>
      <h3>Booking status</h3>
      <p><code>pending</code> → <code>confirmed</code> → <code>completed</code> or <code>no_show</code>. A booking can also become <code>cancelled</code>, with <code>cancelReason</code> set to <code>user</code>, <code>expired</code>, <code>replaced</code>, <code>payment_failed</code> or <code>therapist</code>. An unpaid hold lapses at <code>holdExpiresAt</code>, 15 minutes after it was created.</p>
      <h3>My Bookings &amp; cancel</h3>
      <p>Use <code>?scope=upcoming</code> and <code>?scope=past</code> for the two tabs. Before cancelling, show <code>refundIfCancelledCents</code> and offer Cancel only if <code>canCancel</code> is true. <code>POST /me/bookings/{id}/cancel</code> is safe to retry.</p>
      <div class="note warn"><b>Placeholders.</b> The price, session length (50 min) and refund rule (full refund ≥ 24 h before the start) may change when the client confirms them. The response shapes will not change, so always read these values from the API.</div>
      <div class="tbl-wrap"><table>
        <tr><th>Error</th><th>What to do</th></tr>
        <tr><td><code>409 slot_unavailable</code></td><td>Someone else took the slot. Reload the slots.</td></tr>
        <tr><td><code>502 payment_unavailable</code></td><td>Stripe is unavailable. Retry with the same key.</td></tr>
        <tr><td><code>409 booking_not_cancellable</code></td><td>The session has started or is already cancelled. Reload the booking.</td></tr>
      </table></div>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">book_and_pay.dart</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">final key = const Uuid().v4(); // keep it for retries of this booking
final r = await api.post('/me/bookings',
    data: {'therapistId': therapist.id, 'startsAt': slot.startsAt},
    options: Options(headers: {'Idempotency-Key': key}));

final booking = r.data['booking'];
final pay = r.data['payment'];

Stripe.publishableKey = pay['publishableKey'];
await Stripe.instance.initPaymentSheet(
  paymentSheetParameters: SetupPaymentSheetParameters(
    paymentIntentClientSecret: pay['clientSecret'],
    merchantDisplayName: 'MindEase',
  ),
);
await Stripe.instance.presentPaymentSheet(); // throws if cancelled

// Confirming… (only the webhook can confirm)
for (var i = 0; i &lt; 15; i++) {
  final b = (await api.get('/me/bookings/${booking['id']}')).data;
  if (b['status'] == 'confirmed') return showBooked(b);
  if (b['status'] == 'cancelled') return showFailed(b);
  await Future.delayed(const Duration(seconds: 2));
}
showStillConfirming(); // check My Bookings later</pre></div>
    </div>
  </div>
</section>

<!-- ============ GUIDE: VIDEO ============ -->
<section class="doc" id="guide-video" data-search="guide zoom video sdk call join token permissions camera mic reconnect">
  <div class="eyebrow">Guide</div>
  <h2>In-app video call (Zoom Video SDK)</h2>
  <div class="split">
    <div class="prose">
      <ol class="steps">
        <li>Show <b>Join</b> when <code>now ≥ joinOpensAt</code> (10 minutes before the start) and <code>now &lt; endsAt</code>, and only for <code>confirmed</code> bookings.</li>
        <li>Ask for camera and microphone permission with <code>permission_handler</code>. If the user refuses, explain why and link to Settings.</li>
        <li>Call <code>POST /me/bookings/{id}/video-token</code> and join with <code>flutter_zoom_videosdk</code> using <code>sessionName</code>, <code>token</code> and <code>userIdentity</code>.</li>
        <li><b>Request a new token every time</b> the user joins or reconnects, and don't store it. The SDK key and secret stay on the server.</li>
        <li>For reconnects, listen for <code>onSessionLeave</code> and the connection events. Show <i>Reconnecting…</i> and join again with a new token while <code>now &lt; endsAt</code>.</li>
      </ol>
      <div class="tbl-wrap"><table>
        <tr><th>Error</th><th>What to do</th></tr>
        <tr><td><code>409 booking_not_confirmed</code></td><td>The booking isn't paid or confirmed yet.</td></tr>
        <tr><td><code>409 join_window_closed</code></td><td>It's too early or the session has ended. Show the time it opens.</td></tr>
        <tr><td><code>503 video_unavailable</code></td><td>Video isn't set up on this server. Try again later.</td></tr>
      </table></div>
    </div>
    <div class="code-col">
      <div class="codebox"><div class="head"><span class="title">join_call.dart</span><button class="copy">Copy</button></div><pre class="js-hl" data-lang="dart">final t = (await api.post('/me/bookings/$bookingId/video-token')).data;

await zoom.joinSession(JoinSessionConfig(
  sessionName: t['sessionName'],
  token: t['token'],
  userName: t['userIdentity'],
  audioOptions: {'connect': true, 'mute': false},
  videoOptions: {'localVideoOn': true},
  sessionIdleTimeoutMins: 10,
));

// controls
await zoom.audioHelper.muteAudio(myself.userId);
await zoom.videoHelper.stopVideo();
await zoom.leaveSession(false);</pre></div>
    </div>
  </div>
</section>

<!-- ============ GUIDE: ACCOUNT ============ -->
<section class="doc" id="guide-account" data-search="guide delete account apple privacy profile">
  <div class="eyebrow">Guide</div>
  <h2>Account deletion</h2>
  <div class="prose">
    <p>Apple guideline 5.1.1(v) requires in-app account deletion. <code>DELETE /me</code> permanently deletes the user and all their data: answers, progress, points, bookings and tokens. Ask the user to confirm first, then clear local storage and go to Welcome. Deleting does not refund upcoming paid sessions, so warn the user if they have any.</p>
  </div>
</section>

<!-- ============ REFERENCE (rendered from data below) ============ -->
<div id="reference"></div>

<footer>
  MindEase API v1 · Machine-readable spec: <a href="/docs/api.json">/docs/api.json</a> (OpenAPI 3.1, imports into Postman) · Interactive schema: <a href="/docs/api">/docs/api</a>
</footer>
</main>
</div>

<!-- ============ TRY-IT CONSOLE ============ -->
<aside class="console" id="console" aria-label="API console">
  <header><b>API console</b><button class="btn btn-ghost" id="closeConsole">Close</button></header>
  <div class="body">
    <div class="signin" id="signinBox">
      <div><label for="cEmail">Email</label><input id="cEmail" type="email" value="demo@mindease.test"></div>
      <div><label for="cPass">Password</label><input id="cPass" type="password" value="password123"></div>
      <button class="btn btn-red" id="cSignin">Sign in</button>
    </div>
    <div class="row" id="signedBox" hidden>
      <span class="auth-chip on"><i></i><span id="signedAs"></span></span>
      <button class="btn btn-ghost" id="cRefresh">Refresh token</button>
      <button class="btn btn-ghost" id="cSignout">Sign out</button>
    </div>
    <div class="hint">Requests go to <code class="js-base"></code> from your browser. Tokens are kept in this tab's memory only.</div>

    <label for="cUrl">Request</label>
    <div class="urlrow"><span class="badge" id="cMethodBadge">GET</span><input id="cUrl" type="text" spellcheck="false"></div>
    <label class="check"><input type="checkbox" id="cAuth" checked> Send <code>Authorization: Bearer</code></label>
    <label class="check"><input type="checkbox" id="cIdem"> Send a new <code>Idempotency-Key</code></label>
    <div id="cBodyWrap"><label for="cBody">Body (JSON)</label><textarea id="cBody" spellcheck="false"></textarea></div>
    <div class="row"><button class="btn btn-red" id="cSend">Send request</button><span class="meta" id="cMeta"></span></div>

    <div class="codebox" style="margin-top:16px"><div class="head"><span class="title">Response</span><span class="status" id="cStatus" hidden></span><button class="copy" data-copy="cOut">Copy</button></div><pre id="cOut" class="c">Pick an endpoint and press “Try it”, or press Send.</pre></div>
  </div>
</aside>

<script>
(() => {
const BASE = window.MINDEASE.baseUrl.replace(/\/$/, '');
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const UUID = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));

// ---------------------------------------------------------------- sample ids
const TID = '01a18094-c5b7-707b-9b63-d77ad437184e';
const BID = '01a180a2-7c1e-71d0-8f0e-3b2a9d4c6e11';
const USER = { id: '01a18094-c540-7204-9d17-ec999dd6a9f7', name: 'Demo User', email: 'demo@mindease.test', createdAt: '2026-10-28T03:36:35.000Z' };
const THERAPIST = { id: TID, name: 'Dr Sam Lee', bio: 'Registered psychologist (sample profile).', specialties: ['anxiety', 'stress'], photoUrl: null, timezone: 'Australia/Sydney', priceCents: 15000, currency: 'aud', sessionMinutes: 50 };
const BOOKING = { id: BID, status: 'confirmed', cancelReason: null, startsAt: '2026-11-02T22:00:00.000Z', endsAt: '2026-11-02T22:50:00.000Z', holdExpiresAt: null, therapist: { id: TID, name: 'Dr Sam Lee', photoUrl: null }, priceCents: 15000, currency: 'aud', notes: null, payment: { status: 'succeeded', amountCents: 15000, refundedCents: 0 }, canCancel: true, refundIfCancelledCents: 15000, joinOpensAt: '2026-11-02T21:50:00.000Z', createdAt: '2026-10-28T04:10:00.000Z' };
const PHASE = (id, unlocked, extra = {}) => Object.assign({ unlocked, completedAt: null, pointsAwarded: null, counts: { lessons: { done: 0, total: 3 }, videos: { done: 0, total: 3 }, activity: false, quiz: false }, lessons: {}, videos: {}, activity: null, quiz: { attempts: [], passed: false, passedAt: null, bestScore: null } }, extra);
const PROGRESS = { programId: 'mhfa', contentVersion: '1.0.0', phases: {
  p1: PHASE('p1', true, { counts: { lessons: { done: 1, total: 3 }, videos: { done: 0, total: 3 }, activity: false, quiz: false }, lessons: { p1l1: { readAt: '2026-10-28T03:36:47.000Z' } }, videos: { p1v1: { position: 41.5, watched: '1'.repeat(38) + '0'.repeat(32), watchedSeconds: 38, duration: 70, percent: 54, completedAt: null } } }),
  p2: PHASE('p2', false), p3: PHASE('p3', false), p4: PHASE('p4', false) }, phaseCompleted: {}, points: 0, programCompletedAt: null };

// ---------------------------------------------------------------- error codes
const ERRORS = [
  [422, 'validation_failed', 'Show <code>message</code> under <code>field</code>.'],
  [401, 'unauthenticated', 'Refresh the access token once, then retry.'],
  [401, 'invalid_credentials', 'Show the message on Sign In. It never says which part was wrong.'],
  [401, 'invalid_refresh_token', 'Clear the tokens and show Sign In.'],
  [409, 'email_taken', 'Highlight the email field on Sign Up.'],
  [422, 'invalid_reset_token', 'The link expired or was used. Offer to send a new one.'],
  [403, 'phase_locked', 'Don\'t queue the event again. Refresh progress.'],
  [404, 'not_found · program_not_found · content_not_found · therapist_not_found', 'Show an empty or error state.'],
  [422, 'unknown_phase · unknown_lesson · unknown_video · unknown_prompt', 'The ids don\'t match the content. Reload the content.'],
  [422, 'activity_empty · quiz_incomplete · invalid_answer', 'Fix the input. Retrying won\'t help.'],
  [422, 'idempotency_key_reused', 'Bug: the same key was sent with a different body.'],
  [409, 'idempotency_in_progress', 'Retry in a moment with the same key.'],
  [409, 'slot_unavailable · booking_not_cancellable · booking_not_confirmed · join_window_closed', 'See the Booking and Video guides.'],
  [429, 'rate_limited', 'Wait for <code>Retry-After</code> seconds.'],
  [502, 'payment_unavailable', 'Retry with the same Idempotency-Key.'],
  [503, 'video_unavailable', 'Try again later.'],
  [500, 'server_error', 'Retry with backoff. Show a generic message.'],
];

// ---------------------------------------------------------------- endpoints
// auth: bearer required · idem: Idempotency-Key required · examples: switchable request bodies
const GROUPS = [
 { id: 'auth', title: 'Auth', intro: 'Sign up, sign in, refresh tokens, reset passwords and log out. These endpoints don\'t take a bearer token.', endpoints: [
  { id: 'register', method: 'POST', path: '/auth/register', title: 'Create an account', auth: false,
    desc: 'Creates the user and signs them in. Passwords need at least 8 characters.',
    params: [['name', 'body', 'string', true, 'Shown on Home. Max 255.'], ['email', 'body', 'string', true, 'Must be unique.'], ['password', 'body', 'string', true, 'At least 8 characters.']],
    body: { name: 'Alex Chen', email: 'alex@example.com', password: 'a-strong-password' },
    response: [201, { user: USER, accessToken: '1|jRATrZvCxZcFDTc7rps6KtcQcFKoMdx9SbQ5mFE2463b891c', refreshToken: 'HezQitTfyyuvSAbiZqrLgX6m3LCkZQXZQR4A0gx3167dLjJq4wTmsUugwzaCYrA0' }],
    errors: [[409, 'email_taken', 'field: email'], [422, 'validation_failed', ''], [429, 'rate_limited', '']] },
  { id: 'login', method: 'POST', path: '/auth/login', title: 'Sign in', auth: false,
    desc: 'Returns the user and a new token pair. A wrong email and a wrong password give the same error.',
    params: [['email', 'body', 'string', true, ''], ['password', 'body', 'string', true, '']],
    body: { email: 'demo@mindease.test', password: 'password123' },
    response: [200, { user: USER, accessToken: '1|jRATrZvCxZcFDTc7rps6KtcQcFKoMdx9SbQ5mFE2463b891c', refreshToken: 'HezQitTfyyuvSAbiZqrLgX6m3LCkZQXZQR4A0gx3167dLjJq4wTmsUugwzaCYrA0' }],
    errors: [[401, 'invalid_credentials', ''], [429, 'rate_limited', '5 per minute per email']] },
  { id: 'refresh', method: 'POST', path: '/auth/refresh', title: 'Refresh tokens', auth: false,
    desc: 'Exchanges a refresh token for a new pair. <b>The old refresh token stops working.</b> Reusing it revokes the whole session. Run only one refresh at a time.',
    params: [['refreshToken', 'body', 'string', true, 'The most recent refresh token.']],
    body: { refreshToken: '<refreshToken>' },
    response: [200, { accessToken: '2|bnYDP1xYaJa3VlHDDSBCDGyrbqn5gm5q6BocNQ2g54ccc01b', refreshToken: 'ZxejtkD5F9W6GWy8XuS8WC7GKkgGfhFRfJNv4S2aLeVGx8lreih82lIFvmXEoCWw' }],
    errors: [[401, 'invalid_refresh_token', 'Expired, revoked or reused. Sign in again.']] },
  { id: 'password-reset', method: 'POST', path: '/auth/password-reset', title: 'Request a password reset', auth: false,
    desc: 'Emails a reset link (<code>mindease://reset-password?token=…&email=…</code>). Always returns 202, so the response never reveals whether an account exists.',
    params: [['email', 'body', 'string', true, '']], body: { email: 'demo@mindease.test' }, response: [202, null],
    errors: [[429, 'rate_limited', '5 per hour per email']] },
  { id: 'password-reset-confirm', method: 'POST', path: '/auth/password-reset/confirm', title: 'Set a new password', auth: false,
    desc: 'Uses the <code>token</code> and <code>email</code> from the deep link. On success, every device is signed out. Then sign in with the new password.',
    params: [['email', 'body', 'string', true, 'From the link.'], ['token', 'body', 'string', true, 'From the link.'], ['password', 'body', 'string', true, 'At least 8 characters.']],
    body: { email: 'demo@mindease.test', token: '<token from link>', password: 'new-password-123' }, response: [204, null],
    errors: [[422, 'invalid_reset_token', 'Expired or already used.']] },
  { id: 'logout', method: 'POST', path: '/auth/logout', title: 'Log out', auth: false,
    desc: 'Revokes this sign-in\'s tokens. Clear local storage even if the call fails.',
    params: [['refreshToken', 'body', 'string', true, '']], body: { refreshToken: '<refreshToken>' }, response: [204, null], errors: [] },
 ]},
 { id: 'account', title: 'Account', intro: 'The signed-in user.', endpoints: [
  { id: 'me', method: 'GET', path: '/me', title: 'Get the current user', auth: true, desc: 'Use it on the splash screen to check the session, and on Profile.', response: [200, USER], errors: [[401, 'unauthenticated', '']] },
  { id: 'delete-me', method: 'DELETE', path: '/me', title: 'Delete the account', auth: true, danger: true,
    desc: 'Permanently deletes the user and <b>all</b> their data. This can\'t be undone. Required by Apple 5.1.1(v).', response: [204, null], errors: [] },
 ]},
 { id: 'content', title: 'Content', intro: 'Read-only and cacheable with <code>ETag</code> and <code>If-None-Match</code>. Bodies are the app\'s content JSON files, unchanged.', endpoints: [
  { id: 'config', method: 'GET', path: '/config', title: 'App config', auth: true, desc: 'Thresholds the app shows in its UI. The server applies the same values.',
    response: [200, { passThresholdPercent: 80, pointsPerPhase: 100, videoCompletePercent: 90, phaseRequirements: { lessons: true, videos: true, activity: true, quiz: true }, previewVideosWithoutMedia: true }], errors: [] },
  { id: 'questionnaire', method: 'GET', path: '/questionnaires/onboarding', title: 'Onboarding questionnaire', auth: true, desc: 'The intro and the 9 questions. Types: <code>single</code>, <code>multi</code>, <code>mood</code>. Options can be <code>exclusive</code>.',
    response: [200, { version: '1.0.0', questions: [{ id: 'goals', type: 'multi', q: 'What would you like help with?', options: [{ v: 'stress', label: 'Managing stress' }, { v: 'sleep', label: 'Better sleep' }, { v: 'none', label: 'Not sure yet', exclusive: true }] }, { id: 'mood', type: 'mood', q: 'How have you been feeling lately?', options: [{ v: 'very_low', label: 'Very low' }, { v: 'low', label: 'Low' }, { v: 'okay', label: 'Okay' }] }] }], errors: [] },
  { id: 'programs', method: 'GET', path: '/programs', title: 'List programs', auth: true, desc: 'Programs for the Programs tab, sorted by <code>displayOrder</code>.',
    response: [200, [{ id: 'mhfa', title: 'MHFA Self-Reflection Program', short: 'Four phases to understand and support your mental health', status: 'active', displayOrder: 1, coverImage: null }]], errors: [] },
  { id: 'program', method: 'GET', path: '/programs/{programId}', title: 'Get a program', auth: true, desc: 'The full program: 4 phases, each with lessons, videos (with <code>duration</code> in seconds), activity prompts and chips, and a quiz with answers and explanations.',
    params: [['programId', 'path', 'string', true, 'e.g. <code>mhfa</code>']], sample: { programId: 'mhfa' },
    response: [200, { id: 'mhfa', title: 'MHFA Self-Reflection Program', contentVersion: '1.0.0', disclaimer: 'This is not an accredited course.', phases: [{ id: 'p1', number: 1, title: 'Noticing how you feel', color: '#9BB068', lessons: [{ id: 'p1l1', title: 'Lesson 1', minutes: 4, intro: '…', sections: [{ h: 'Heading', p: ['…'], list: ['…'] }], takeaway: '…' }], videos: [{ id: 'p1v1', title: 'Video 1', duration: 70, mediaUrl: 'https://…', thumbnailUrl: null }], activity: { prompts: [{ id: 'q1', q: '…', chips: ['…'] }] }, quiz: { questions: [{ q: '…', options: ['A', 'B', 'C'], answer: 1, explanation: '…' }] } }] }],
    headers: 'ETag: "cfe55f08cc…"\nX-Content-Version: 1.0.0', errors: [[404, 'program_not_found', '']] },
  { id: 'support', method: 'GET', path: '/support-resources', title: 'Support resources (Get Help)', auth: true, desc: 'Australian support numbers. Use <code>tel:</code> links for tap-to-call. Strip the spaces from <code>number</code> before dialling.',
    params: [['region', 'query', 'string', false, 'Defaults to <code>AU</code>.']], query: { region: 'AU' },
    response: [200, { region: 'AU', verifiedOn: '01/10/2026', resources: [{ id: 'emergency', name: 'Emergency', description: 'Police, fire, ambulance', number: '000' }, { id: 'lifeline', name: 'Lifeline', description: '24/7 crisis support', number: '13 11 14' }, { id: 'beyondblue', name: 'Beyond Blue', description: 'Support for anxiety and depression', number: '1300 22 4636' }] }], errors: [] },
 ]},
 { id: 'assessment', title: 'Assessment', intro: 'The user\'s questionnaire answers. Sensitive: encrypted at rest and only returned to their owner.', endpoints: [
  { id: 'get-assessment', method: 'GET', path: '/me/assessment', title: 'Get saved answers', auth: true, desc: 'Use it to resume a questionnaire or pre-select answers for a retake. Returns an empty document before the first save.',
    response: [200, { answers: { goals: ['stress', 'sleep'], mood: 'low' }, step: 2, completedAt: null, nextStepsSeen: false, questionnaireVersion: '1.0.0' }], errors: [] },
  { id: 'put-assessment', method: 'PUT', path: '/me/assessment', title: 'Save answers', auth: true, desc: '<b>Full replace</b>: send every answer each time. Call it on every tap (debounced).',
    params: [['answers', 'body', 'object', true, 'Question id to value (string or list of strings).'], ['step', 'body', 'integer', false, 'Current question index (0–1000).'], ['completedAt', 'body', 'ISO date | null', false, 'Set when the user finishes.'], ['nextStepsSeen', 'body', 'boolean', false, ''], ['questionnaireVersion', 'body', 'string', false, 'From the questionnaire <code>version</code>.']],
    body: { answers: { goals: ['stress', 'sleep'], mood: 'low', stress: 'medium' }, step: 3, completedAt: null, nextStepsSeen: false, questionnaireVersion: '1.0.0' },
    response: [200, { answers: { goals: ['stress', 'sleep'], mood: 'low', stress: 'medium' }, step: 3, completedAt: null, nextStepsSeen: false, questionnaireVersion: '1.0.0' }], errors: [[422, 'validation_failed', '']] },
 ]},
 { id: 'progress', title: 'Progress & points', intro: 'The app sends events. The server decides completion and points.', endpoints: [
  { id: 'get-progress', method: 'GET', path: '/me/programs/{programId}/progress', title: 'Get program progress', auth: true,
    desc: 'Locked and unlocked state, counts for the Phase detail tabs, read lessons, video positions and watched maps, activity answers, quiz attempts and points.',
    params: [['programId', 'path', 'string', true, '']], sample: { programId: 'mhfa' }, response: [200, PROGRESS], errors: [[404, 'program_not_found', '']] },
  { id: 'events', method: 'POST', path: '/me/programs/{programId}/events', title: 'Send a progress event', auth: true, idem: true,
    desc: 'One event per call. Returns the new <code>progress</code>, and a <code>completion</code> when this event completed a phase. Pick an event type to see its body.',
    params: [['Idempotency-Key', 'header', 'uuid', true, 'New per event; the same key on retry.'], ['programId', 'path', 'string', true, ''], ['type', 'body', 'enum', true, '<code>lesson_read</code> · <code>video_progress</code> · <code>activity_save</code> · <code>quiz_submit</code>'], ['phaseId', 'body', 'string', true, 'The phase must be unlocked.'], ['lessonId', 'body', 'string', false, 'lesson_read'], ['videoId, position, watched', 'body', 'string, number, string', false, 'video_progress. <code>watched</code>: one <code>0</code>/<code>1</code> per second.'], ['complete, answers', 'body', 'boolean, object', false, 'activity_save. Keys are prompt ids: <code>{ text, chips[] }</code>'], ['answers', 'body', 'int[]', false, 'quiz_submit. A 0-based option index per question.']],
    sample: { programId: 'mhfa' },
    examples: {
      lesson_read: { type: 'lesson_read', phaseId: 'p1', lessonId: 'p1l1' },
      video_progress: { type: 'video_progress', phaseId: 'p1', videoId: 'p1v1', position: 41.5, watched: '1'.repeat(38) + '0'.repeat(32) },
      activity_save: { type: 'activity_save', phaseId: 'p1', complete: true, answers: { q1: { text: 'I noticed I get tense before meetings.', chips: ['work'] } } },
      quiz_submit: { type: 'quiz_submit', phaseId: 'p1', answers: [1, 0, 1, 1, 1] },
    },
    response: [200, { progress: PROGRESS, completion: null }],
    errors: [[403, 'phase_locked', ''], [422, 'unknown_phase · unknown_lesson · unknown_video · unknown_prompt', ''], [422, 'activity_empty', 'complete: true with no answer'], [422, 'quiz_incomplete · invalid_answer', ''], [422, 'idempotency_key_reused', ''], [409, 'idempotency_in_progress', '']] },
  { id: 'points', method: 'GET', path: '/me/points', title: 'Get points', auth: true, desc: 'The total for Home, plus each award. Read-only.',
    response: [200, { total: 100, awards: [{ programId: 'mhfa', phaseId: 'p1', points: 100, awardedAt: '2026-10-28T05:12:00.000Z' }] }], errors: [] },
 ]},
 { id: 'therapists', title: 'Therapists', intro: 'Therapist list, profile and bookable slots.', endpoints: [
  { id: 'therapists', method: 'GET', path: '/therapists', title: 'List therapists', auth: true, desc: 'Active therapists, sorted by name.', response: [200, [THERAPIST]], errors: [] },
  { id: 'therapist', method: 'GET', path: '/therapists/{therapistId}', title: 'Get a therapist', auth: true, desc: 'One therapist for the profile screen. Same shape as a list item.',
    params: [['therapistId', 'path', 'uuid', true, '']], sample: { therapistId: TID }, response: [200, THERAPIST], errors: [[404, 'therapist_not_found', '']] },
  { id: 'slots', method: 'GET', path: '/therapists/{therapistId}/slots', title: 'Available slots', auth: true,
    desc: 'Bookable start times in UTC. Booked and held slots, the 12-hour notice period and daylight saving time are already handled.',
    params: [['therapistId', 'path', 'uuid', true, ''], ['from', 'query', 'ISO date', false, 'Default: now.'], ['to', 'query', 'ISO date', false, 'Default: from + 14 days. At most 31 days after from.']],
    sample: { therapistId: TID }, query: { from: '2026-11-02T00:00:00Z', to: '2026-11-09T00:00:00Z' },
    response: [200, { therapistId: TID, sessionMinutes: 50, slots: [{ startsAt: '2026-11-02T22:00:00.000Z', endsAt: '2026-11-02T22:50:00.000Z' }, { startsAt: '2026-11-02T23:00:00.000Z', endsAt: '2026-11-02T23:50:00.000Z' }] }],
    errors: [[404, 'therapist_not_found', ''], [422, 'range_too_long', 'field: to']] },
 ]},
 { id: 'bookings', title: 'Bookings', intro: 'Paid with Stripe. A booking is confirmed only when Stripe\'s webhook reaches the server.', endpoints: [
  { id: 'create-booking', method: 'POST', path: '/me/bookings', title: 'Book a session', auth: true, idem: true,
    desc: 'Holds the slot as <code>pending</code> for 15 minutes and creates a Stripe PaymentIntent at the server\'s price. Pass <code>payment</code> to PaymentSheet. Booking the same slot again replaces your earlier unpaid hold.',
    params: [['Idempotency-Key', 'header', 'uuid', true, ''], ['therapistId', 'body', 'uuid', true, ''], ['startsAt', 'body', 'ISO date', true, 'Exactly a <code>startsAt</code> from /slots.'], ['notes', 'body', 'string', false, 'Max 2000. Private to the user and the therapist.']],
    body: { therapistId: TID, startsAt: '2026-11-02T22:00:00.000Z', notes: 'First session' },
    response: [201, { booking: Object.assign({}, BOOKING, { status: 'pending', holdExpiresAt: '2026-10-28T04:25:00.000Z', payment: { status: 'requires_payment_method', amountCents: 15000, refundedCents: 0 } }), payment: { clientSecret: 'pi_3Q…_secret_…', publishableKey: 'pk_test_…' } }],
    errors: [[404, 'therapist_not_found', ''], [409, 'slot_unavailable', 'field: startsAt'], [502, 'payment_unavailable', '']] },
  { id: 'list-bookings', method: 'GET', path: '/me/bookings', title: 'My bookings', auth: true, desc: '<code>upcoming</code>: pending and confirmed bookings that haven\'t ended, soonest first. <code>past</code>: everything else, newest first.',
    params: [['scope', 'query', 'enum', false, '<code>upcoming</code> · <code>past</code>. Leave it out to get all.']], query: { scope: 'upcoming' }, response: [200, [BOOKING]], errors: [] },
  { id: 'get-booking', method: 'GET', path: '/me/bookings/{bookingId}', title: 'Get a booking', auth: true, desc: 'Poll this after PaymentSheet until the status is <code>confirmed</code>.',
    params: [['bookingId', 'path', 'uuid', true, '']], sample: { bookingId: BID }, response: [200, BOOKING], errors: [[404, 'not_found', '']] },
  { id: 'cancel-booking', method: 'POST', path: '/me/bookings/{bookingId}/cancel', title: 'Cancel a booking', auth: true,
    desc: 'Cancels the booking and refunds <code>refundIfCancelledCents</code>. Safe to retry. A pending booking\'s PaymentIntent is cancelled.',
    params: [['bookingId', 'path', 'uuid', true, '']], sample: { bookingId: BID },
    response: [200, Object.assign({}, BOOKING, { status: 'cancelled', cancelReason: 'user', canCancel: false, refundIfCancelledCents: 0, payment: { status: 'refunded', amountCents: 15000, refundedCents: 15000 } })],
    errors: [[409, 'booking_not_cancellable', '']] },
  { id: 'video-token', method: 'POST', path: '/me/bookings/{bookingId}/video-token', title: 'Get a video call token', auth: true,
    desc: 'A short-lived Zoom Video SDK JWT for <code>flutter_zoom_videosdk</code>. Only for confirmed bookings, from <code>joinOpensAt</code> until <code>endsAt</code>. Get a new one each time the user joins.',
    params: [['bookingId', 'path', 'uuid', true, '']], sample: { bookingId: BID },
    response: [200, { token: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…', sessionName: BID, userIdentity: USER.id, role: 'participant', expiresAt: '2026-11-02T22:50:00.000Z' }],
    errors: [[409, 'booking_not_confirmed', ''], [409, 'join_window_closed', ''], [503, 'video_unavailable', '']] },
 ]},
];

// ---------------------------------------------------------------- highlighting
function hlJson(v) {
  const s = typeof v === 'string' ? v : JSON.stringify(v, null, 2);
  return esc(s).replace(/(&quot;|")(?:[^"\\]|\\.)*?"(\s*:)?|\b(true|false|null)\b|-?\b\d+(\.\d+)?\b/g, (m, _q, colon, kw) => {
    if (colon) return `<span class="k">${m.slice(0, -colon.length)}</span>${colon}`;
    if (m[0] === '"') return `<span class="s">${m}</span>`;
    if (kw) return `<span class="kw">${m}</span>`;
    return `<span class="n">${m}</span>`;
  });
}
function hlCode(src) {
  // comments, strings, keywords for dart / shell
  return esc(src).replace(/((?:^|(?<=\s))\/\/[^\n]*)|('(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*")|\b(final|const|await|async|class|return|if|else|for|try|on|catch|void|var|new|import|extends|implements|factory|required|true|false|null|Future|curl)\b/gm,
    (m, c, s, k) => c ? `<span class="c">${c}</span>` : s ? `<span class="s">${s}</span>` : `<span class="kw">${k}</span>`);
}
$$('.js-base').forEach(el => el.textContent = BASE);
$$('.js-origin').forEach(el => el.textContent = BASE.replace(/\/v1$/, ''));
$$('pre.js-json').forEach(el => el.innerHTML = hlJson(JSON.parse(el.textContent)));
$$('pre.js-hl').forEach(el => el.innerHTML = hlCode(el.textContent));

// ---------------------------------------------------------------- samples
function fillPath(ep) { return ep.path.replace(/\{(\w+)\}/g, (_, k) => (ep.sample || {})[k] ?? `{${k}}`); }
function qs(q) { return q ? '?' + new URLSearchParams(q).toString() : ''; }
function curlFor(ep, body) {
  const lines = [`curl -X ${ep.method} "${BASE}${fillPath(ep)}${qs(ep.query)}"`, `  -H "Accept: application/json"`];
  if (body) lines.push(`  -H "Content-Type: application/json"`);
  if (ep.auth) lines.push(`  -H "Authorization: Bearer $ACCESS_TOKEN"`);
  if (ep.idem) lines.push(`  -H "Idempotency-Key: $(uuidgen)"`);
  if (body) lines.push(`  -d '${JSON.stringify(body)}'`);
  return lines.join(' \\\n');
}
function dartLiteral(v, ind = '') {
  const n = ind + '  ';
  if (Array.isArray(v)) return v.length ? `[${v.map(x => dartLiteral(x, n)).join(', ')}]` : '[]';
  if (v && typeof v === 'object') {
    const e = Object.entries(v); if (!e.length) return '{}';
    return `{\n${e.map(([k, x]) => `${n}'${k}': ${dartLiteral(x, n)}`).join(',\n')},\n${ind}}`;
  }
  if (typeof v === 'string') return `'${v.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\$/g, '\\$')}'`;
  return String(v);
}
function dartFor(ep, body) {
  const path = ep.path.replace(/\{(\w+)\}/g, (_, k) => '$' + k);
  const args = [`'${path}'`];
  if (body) args.push(`data: ${dartLiteral(body, '  ')}`);
  if (ep.query) args.push(`queryParameters: ${dartLiteral(ep.query, '  ')}`);
  if (ep.idem) args.push(`options: Options(headers: {'Idempotency-Key': const Uuid().v4()})`);
  const pre = Object.entries(ep.sample || {}).map(([k, v]) => `final ${k} = '${v}';\n`).join('');
  const call = `final res = await api.${ep.method.toLowerCase()}(${args.length > 1 ? '\n  ' + args.join(',\n  ') + ',\n' : args[0]});`;
  const status = ep.response[0];
  const tail = ep.response[1] === null ? `\n// ${status}: no body` : `\nfinal data = res.data; // ${status}`;
  return pre + call + tail;
}

// ---------------------------------------------------------------- render reference
const ref = $('#reference'), navRef = $('#navRef'), errTable = $('#errorTable');
ERRORS.forEach(([s, c, w]) => errTable.insertAdjacentHTML('beforeend', `<tr><td><code>${s}</code></td><td><code>${c.split(' · ').join('</code> <code>')}</code></td><td>${w}</td></tr>`));

const byId = {};
GROUPS.forEach(g => {
  navRef.insertAdjacentHTML('beforeend', `<h5>${esc(g.title)}</h5>` + g.endpoints.map(ep => `<a href="#ep-${ep.id}"><span class="m ${ep.method}">${ep.method}</span>${esc(ep.title)}</a>`).join(''));
  g.endpoints.forEach((ep, i) => {
    byId[ep.id] = ep;
    const exampleKeys = ep.examples ? Object.keys(ep.examples) : null;
    const params = ep.params?.length ? `<h3>Parameters</h3><div class="tbl-wrap"><table><tr><th>Name</th><th>In</th><th>Description</th></tr>${ep.params.map(([n, inn, t, r, d]) => `<tr><td><code>${esc(n)}</code>${r ? '<span class="req">required</span>' : '<span class="opt">optional</span>'}<span class="type">${esc(t)}</span></td><td>${inn}</td><td>${d}</td></tr>`).join('')}</table></div>` : '';
    const errs = ep.errors?.length ? `<h3>Errors</h3><div class="tbl-wrap"><table><tr><th>Status</th><th>Code</th><th>Notes</th></tr>${ep.errors.map(([s, c, n]) => `<tr><td><code>${s}</code></td><td><code>${c.split(' · ').join('</code> <code>')}</code></td><td>${esc(n)}</td></tr>`).join('')}</table></div>` : '';
    const tags = `<span class="tags">${ep.auth ? '<span class="tag lock">🔒 Bearer</span>' : '<span class="tag">No auth</span>'}${ep.idem ? '<span class="tag">Idempotency-Key</span>' : ''}</span>`;
    ref.insertAdjacentHTML('beforeend', `
<section class="doc" id="ep-${ep.id}" data-search="${esc([g.title, ep.title, ep.method, ep.path, ep.desc.replace(/<[^>]+>/g, '')].join(' ').toLowerCase())}">
  ${i === 0 ? `<div class="eyebrow">API reference · ${esc(g.title)}</div>` : `<div class="eyebrow">${esc(g.title)}</div>`}
  <div class="split">
    <div class="prose">
      <h2>${esc(ep.title)}</h2>
      <div class="endpoint-line"><span class="badge ${ep.method}">${ep.method}</span><code>${esc(ep.path)}</code>${tags}</div>
      ${i === 0 && g.intro ? `<p class="lead" style="font-size:15px">${g.intro}</p>` : ''}
      <p>${ep.desc}</p>
      ${ep.danger ? '<div class="note danger"><b>Destructive.</b> The console asks you to confirm before it sends this request.</div>' : ''}
      ${params}${errs}
      <div class="ep-actions">
        ${exampleKeys ? `<select class="ex" data-ep="${ep.id}" aria-label="Event type">${exampleKeys.map(k => `<option>${k}</option>`).join('')}</select>` : ''}
        <button class="btn btn-red" data-try="${ep.id}">Try it →</button>
      </div>
    </div>
    <div class="code-col">
      <div class="codebox" data-samples="${ep.id}">
        <div class="head"><span class="title">Request</span><button class="tab on" data-lang="curl">cURL</button><button class="tab" data-lang="dart">Dart</button><button class="copy">Copy</button></div>
        <pre></pre>
      </div>
      <div class="codebox">
        <div class="head"><span class="title">Response</span><span class="status ${ep.response[0] >= 400 ? 'err' : ''}">${ep.response[0]}</span><button class="copy">Copy</button></div>
        <pre>${ep.headers ? `<span class="c">${esc(ep.headers)}</span>\n\n` : ''}${ep.response[1] === null ? '<span class="c">// no body</span>' : hlJson(ep.response[1])}</pre>
      </div>
    </div>
  </div>
</section>`);
  });
});

const lang = { v: 'curl' };
try { lang.v = localStorage.getItem('me-lang') || 'curl'; } catch (e) {}
function currentBody(ep) {
  if (ep.examples) return ep.examples[$(`select.ex[data-ep="${ep.id}"]`).value];
  return ep.body || null;
}
function renderSamples(epId) {
  const ep = byId[epId], box = $(`[data-samples="${epId}"]`);
  const body = currentBody(ep);
  const src = lang.v === 'dart' ? dartFor(ep, body) : curlFor(ep, body);
  $('pre', box).innerHTML = hlCode(src);
  $('pre', box).dataset.raw = src;
  $$('.tab', box).forEach(t => t.classList.toggle('on', t.dataset.lang === lang.v));
}
Object.keys(byId).forEach(renderSamples);
// Reference sections are rendered here, after the browser tried to jump to the hash.
if (location.hash) requestAnimationFrame(() => document.getElementById(decodeURIComponent(location.hash.slice(1)))?.scrollIntoView());

document.addEventListener('click', (e) => {
  const tab = e.target.closest('.codebox .tab');
  if (tab) { lang.v = tab.dataset.lang; try { localStorage.setItem('me-lang', lang.v); } catch (err) {} Object.keys(byId).forEach(renderSamples); return; }
  const copy = e.target.closest('.copy');
  if (copy) {
    const pre = copy.dataset.copy ? $('#' + copy.dataset.copy) : $('pre', copy.closest('.codebox'));
    navigator.clipboard?.writeText(pre.dataset.raw || pre.textContent).then(() => { copy.textContent = 'Copied'; setTimeout(() => copy.textContent = 'Copy', 1200); });
    return;
  }
  const tr = e.target.closest('[data-try]');
  if (tr) openConsole(byId[tr.dataset.try]);
});
document.addEventListener('change', (e) => { if (e.target.matches('select.ex')) renderSamples(e.target.dataset.ep); });

// ---------------------------------------------------------------- nav: active + search + mobile
const sections = $$('section.doc');
const links = () => $$('nav.side a');
const io = new IntersectionObserver((entries) => {
  entries.forEach(en => { if (en.isIntersecting) { links().forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + en.target.id)); } });
}, { rootMargin: '-70px 0px -70% 0px' });
sections.forEach(s => io.observe(s));
$('#search').addEventListener('input', (e) => {
  const q = e.target.value.trim().toLowerCase();
  links().forEach(a => {
    const sec = document.getElementById(a.getAttribute('href').slice(1));
    const hit = !q || a.textContent.toLowerCase().includes(q) || (sec?.dataset.search || '').includes(q);
    a.style.display = hit ? '' : 'none';
  });
  $$('nav.side h5').forEach(h => {
    let n = h.nextElementSibling, any = false;
    while (n && n.tagName === 'A') { if (n.style.display !== 'none') any = true; n = n.nextElementSibling; }
    h.style.display = any ? '' : 'none';
  });
});
document.addEventListener('keydown', (e) => {
  if (e.key === '/' && !/INPUT|TEXTAREA/.test(document.activeElement.tagName)) { e.preventDefault(); $('#search').focus(); }
  if (e.key === 'Escape') { closeConsole(); side.classList.remove('open'); }
});
const side = $('#side');
$('#menuBtn').addEventListener('click', () => side.classList.toggle('open'));
side.addEventListener('click', (e) => { if (e.target.closest('a')) side.classList.remove('open'); });
$('#themeBtn').addEventListener('click', () => {
  const root = document.documentElement;
  const dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  root.dataset.theme = dark ? 'light' : 'dark';
  try { localStorage.setItem('me-theme', root.dataset.theme); } catch (e) {}
});
try { const t = localStorage.getItem('me-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}

// ---------------------------------------------------------------- console
const auth = { access: null, refresh: null, email: null }; // memory only
let active = null;
function paintAuth() {
  const on = !!auth.access;
  $('#authChip').classList.toggle('on', on);
  $('#authChip span').textContent = on ? auth.email || 'Signed in' : 'Not signed in';
  $('#signinBox').hidden = on; $('#signedBox').hidden = !on;
  $('#signedAs').textContent = auth.email || 'Signed in';
}
function captureTokens(path, data) {
  if (data && data.accessToken) { auth.access = data.accessToken; auth.refresh = data.refreshToken || auth.refresh; if (data.user) auth.email = data.user.email; paintAuth(); }
  if ((/\/auth\/logout$/.test(path) || /^DELETE \/me$/.test(path))) { auth.access = auth.refresh = auth.email = null; paintAuth(); }
}
function fillBodyPlaceholders(body) {
  if (!body) return '';
  let s = JSON.stringify(body, null, 2);
  if (auth.refresh) s = s.replace('"<refreshToken>"', JSON.stringify(auth.refresh));
  return s;
}
function openConsole(ep) {
  if (ep) {
    active = ep;
    $('#cMethodBadge').textContent = ep.method; $('#cMethodBadge').className = 'badge ' + ep.method;
    $('#cUrl').value = fillPath(ep) + qs(ep.query);
    $('#cAuth').checked = !!ep.auth; $('#cIdem').checked = !!ep.idem;
    const body = currentBody(ep);
    $('#cBodyWrap').hidden = !body; $('#cBody').value = fillBodyPlaceholders(body);
  }
  $('#console').classList.add('open'); $('#overlay').classList.add('show');
}
function closeConsole() { $('#console').classList.remove('open'); $('#overlay').classList.remove('show'); }
$('#openConsole').addEventListener('click', () => openConsole(active || byId.me));
$('#closeConsole').addEventListener('click', closeConsole);
$('#overlay').addEventListener('click', closeConsole);

async function send(method, path, { body, bearer, idem } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (bearer && auth.access) headers.Authorization = 'Bearer ' + auth.access;
  if (idem) headers['Idempotency-Key'] = UUID();
  const t0 = performance.now();
  const res = await fetch(BASE + path, { method, headers, body: body !== undefined ? body : undefined });
  const text = await res.text();
  let data = null; try { data = text ? JSON.parse(text) : null; } catch (e) { data = text; }
  return { res, data, ms: Math.round(performance.now() - t0), headers };
}
function show({ res, data, ms, headers }) {
  const st = $('#cStatus'); st.hidden = false; st.textContent = `${res.status} ${res.statusText}`; st.classList.toggle('err', res.status >= 400);
  const pick = ['etag', 'x-content-version', 'idempotent-replayed', 'retry-after', 'x-ratelimit-remaining'].map(h => res.headers.get(h) ? `${h}: ${res.headers.get(h)}` : null).filter(Boolean);
  $('#cMeta').textContent = `${ms} ms` + (headers['Idempotency-Key'] ? ` · key ${headers['Idempotency-Key'].slice(0, 8)}…` : '');
  const out = $('#cOut'); out.className = '';
  const json = data === null ? '' : (typeof data === 'string' ? esc(data) : hlJson(data));
  out.innerHTML = (pick.length ? `<span class="c">${esc(pick.join('\n'))}</span>\n\n` : '') + (json || '<span class="c">// no body</span>');
  out.dataset.raw = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
}
function showFail(err) { const out = $('#cOut'); out.className = 'c'; out.textContent = 'Request failed: ' + err.message; $('#cStatus').hidden = true; }

$('#cSend').addEventListener('click', async () => {
  const method = $('#cMethodBadge').textContent;
  let path = $('#cUrl').value.trim(); if (!path.startsWith('/')) path = '/' + path;
  if (method === 'DELETE' && path === '/me' && !confirm('This permanently deletes the signed-in account and all its data. Continue?')) return;
  let body;
  if (!$('#cBodyWrap').hidden && $('#cBody').value.trim()) {
    try { JSON.parse($('#cBody').value); } catch (e) { return showFail(new Error('Body is not valid JSON: ' + e.message)); }
    body = $('#cBody').value;
  }
  const btn = $('#cSend'); btn.disabled = true; btn.textContent = 'Sending…';
  try {
    const r = await send(method, path, { body, bearer: $('#cAuth').checked, idem: $('#cIdem').checked });
    show(r);
    if (r.res.ok) captureTokens(method === 'DELETE' ? 'DELETE ' + path : path, r.data);
  } catch (e) { showFail(e); }
  btn.disabled = false; btn.textContent = 'Send request';
});
$('#cSignin').addEventListener('click', async () => {
  try {
    const r = await send('POST', '/auth/login', { body: JSON.stringify({ email: $('#cEmail').value, password: $('#cPass').value }) });
    show(r); if (r.res.ok) captureTokens('/auth/login', r.data);
  } catch (e) { showFail(e); }
});
$('#cRefresh').addEventListener('click', async () => {
  try { const r = await send('POST', '/auth/refresh', { body: JSON.stringify({ refreshToken: auth.refresh }) }); show(r); if (r.res.ok) captureTokens('/auth/refresh', r.data); else { auth.access = auth.refresh = null; paintAuth(); } } catch (e) { showFail(e); }
});
$('#cSignout').addEventListener('click', async () => {
  try { const r = await send('POST', '/auth/logout', { body: JSON.stringify({ refreshToken: auth.refresh }) }); show(r); } catch (e) { showFail(e); }
  auth.access = auth.refresh = auth.email = null; paintAuth();
});
paintAuth();
})();
</script>
@endverbatim
</body>
</html>
