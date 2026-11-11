{{-- Signed-in bar for the docs pages: who is signed in, time left, sign out. Session ends at $expiresAt (absolute). --}}
@php($docsUser = auth('web')->user())
@php($docsExpires = \App\Http\Middleware\EnsureDocsAccess::expiresAt())
@if ($docsUser && $docsExpires)
<div id="me-docs-bar" role="region" aria-label="Docs session">
  <span class="me-docs-who">{{ $docsUser->email }}</span>
  <span class="me-docs-left" id="me-docs-left" data-expires="{{ $docsExpires->getTimestamp() }}"></span>
  <form method="POST" action="{{ route('docs.logout') }}">
    @csrf
    <button type="submit">Sign out</button>
  </form>
</div>
<style>
  #me-docs-bar { position: fixed; right: 16px; bottom: 16px; z-index: 2147483000; display: flex; align-items: center; gap: 10px;
    background: #4F3422; color: #fff; padding: 8px 8px 8px 14px; border-radius: 999px; box-shadow: 0 6px 20px rgba(0,0,0,.18);
    font: 600 13px/1.2 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; max-width: calc(100vw - 32px); }
  #me-docs-bar form { margin: 0; }
  #me-docs-bar .me-docs-who { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px; }
  #me-docs-bar .me-docs-left { opacity: .75; white-space: nowrap; }
  #me-docs-bar button { border: 0; border-radius: 999px; background: #D64938; color: #fff; padding: 7px 14px; font: inherit; font-weight: 800; cursor: pointer; }
  #me-docs-bar button:hover { background: #b93a2b; }
  @media (max-width: 520px) { #me-docs-bar .me-docs-who { display: none; } }
</style>
<script>
  (function () {
    var el = document.getElementById('me-docs-left');
    var expires = parseInt(el.dataset.expires, 10) * 1000;
    function tick() {
      var ms = expires - Date.now();
      if (ms <= 0) { window.location.href = @json(route('docs.login')); return; }
      var m = Math.ceil(ms / 60000);
      el.textContent = m + ' min left';
    }
    tick();
    setInterval(tick, 15000);
  })();
</script>
@endif
