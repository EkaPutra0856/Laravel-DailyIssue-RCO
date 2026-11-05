<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') – PortalRCO</title>
<link rel="icon" href="{{ asset('static/favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="{{ asset('static/css/portal.css') }}">
</head>
{{-- ?embed=1: ditampilkan di dalam web portal (iframe), jadi topbar & sidebar milik halaman ini disembunyikan. --}}
<body class="{{ request()->boolean('embed') ? 'embed' : '' }}" data-page="@yield('page')">
<header class="topbar">
  <button class="hamburger" id="nav-toggle" aria-label="Buka/tutup menu">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>
  <div class="brand">PortalRCO</div>
  <div class="spacer"></div>
  <div class="topbar-user" id="topbar-user"></div>
</header>

<aside class="sidebar" id="sidebar">
  <input class="pill-search" id="menu-search" type="search" placeholder="Search menu..." aria-label="Cari menu">
  <nav class="menu" id="menu">
    @if ($portalUrl ?? config('handover.web.portal_url'))
      <a class="menu-item" href="{{ $portalUrl ?? config('handover.web.portal_url') }}" target="_top">
        <span class="mi"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>Home
      </a>
    @endif
    <a class="menu-item @if (request()->is('/')) active @endif" href="{{ url('/') }}">
      <span class="mi"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg></span>Daily Issue RCO
    </a>
  </nav>
</aside>

<main class="content">
  @yield('content')
</main>

@yield('modals')
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="{{ asset('static/js/portal.js') }}" defer></script>
@yield('scripts')
</body>
</html>
