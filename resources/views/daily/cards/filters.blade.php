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
      <input type="date" id="start" aria-label="Tanggal mulai"> <span class="muted">s/d</span>
      <input type="date" id="end" aria-label="Tanggal akhir">
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
