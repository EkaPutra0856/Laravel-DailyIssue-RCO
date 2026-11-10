<div class="section-label">Filter</div>
<section class="card filters">
  <div class="f-row">
    <span class="f-label">Periode</span>
    <div class="seg" id="presets">
      <button data-p="today">Hari ini</button>
      <button data-p="yesterday">Kemarin</button>
      <button data-p="week">Minggu ini</button>
      <button data-p="lastweek">Minggu lalu</button>
      <button data-p="month">Bulan ini</button>
      <button data-p="lastmonth">Bulan lalu</button>
    </div>
    <div class="dates">
      <label class="date-field">
        <input type="text" id="start" inputmode="numeric" placeholder="DD/MM/YYYY" aria-label="Tanggal mulai" autocomplete="off">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
        <input class="date-picker" type="date" id="start-picker" aria-label="Pilih tanggal mulai" tabindex="-1">
      </label>
      <span class="muted">s/d</span>
      <label class="date-field">
        <input type="text" id="end" inputmode="numeric" placeholder="DD/MM/YYYY" aria-label="Tanggal akhir" autocomplete="off">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
        <input class="date-picker" type="date" id="end-picker" aria-label="Pilih tanggal akhir" tabindex="-1">
      </label>
    </div>
  </div>
  <div class="f-row">
    <span class="f-label">Tim</span>
    <div class="chips" id="team-chips">
      @foreach ($teams as $t)<button class="chip" data-t="{{ $t }}">{{ $t }}</button>@endforeach
    </div>
    <label class="month-pick" title="Lihat rekap bulan tertentu">Bulan
      <input type="month" id="month" aria-label="Pilih bulan">
    </label>
    <input class="pill-search grow" id="q" type="search" placeholder="Cari nomor, aplikasi, pelapor, PIC, isi issue... (tekan /)">
    <button class="btn" id="reset-filter">Reset filter</button>
  </div>
</section>
