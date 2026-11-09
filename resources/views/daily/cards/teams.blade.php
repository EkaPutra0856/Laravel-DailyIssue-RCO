<aside class="card side-panel">
  <div class="panel-h"><h2 class="card-title">Per Tim</h2></div>
  <div class="teams" id="teams"></div>
  <div class="legend">
    @foreach ($statuses as $k => $label)<span><span class="dot {{ $k }}"></span> {{ $label }}</span>@endforeach
  </div>
  @php
    // Kata semangat di panel Per Tim. Tambah atau ubah kalimatnya di sini.
    $semangat = [
      'Tidak ada superman, yang ada adalah superteam.',
      'Masalah sebesar apa pun terasa lebih ringan kalau dikerjakan bersama.',
      'Satu issue selesai, satu rekan terbantu.',
      'Tenang, teliti, tuntaskan.',
      'Terima kasih sudah berjaga hari ini.',
      'Pause before execute -APS 2026',
    ];
  @endphp
  <section class="spirit" id="spirit" aria-label="Kata semangat untuk rekan-rekan RCO">
    <div class="spirit-head">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Semangat, rekan-rekan RCO!</span>
    </div>
    <div class="spirit-slides">
      @foreach ($semangat as $i => $kalimat)
        <p class="spirit-slide{{ $i === 0 ? ' on' : '' }}">{{ $kalimat }}</p>
      @endforeach
    </div>
    <div class="spirit-dots">
      @foreach ($semangat as $i => $kalimat)
        <button type="button" class="{{ $i === 0 ? 'on' : '' }}" data-slide="{{ $i }}" aria-label="Tampilkan kata semangat {{ $i + 1 }}"></button>
      @endforeach
    </div>
  </section>
</aside>
