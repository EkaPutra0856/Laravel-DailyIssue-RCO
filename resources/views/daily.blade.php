@extends('layouts.portal')

@section('title', 'Daily Issue RCO')
@section('page', 'daily')

@section('content')
<div id="daily-config" hidden
     data-teams='@json($teams)'
     data-statuses='@json($statuses)'
     data-engineer-statuses='@json($engineerStatuses)'></div>

<section class="page-head">
  <div>
    <h1>Daily Issue RCO</h1>
    <p class="sub">Polisi mencatat issue, engineer menindaklanjuti sampai selesai.</p>
  </div>
  <div class="head-actions">
    <button class="role-chip" id="role-chip" aria-haspopup="dialog" title="Ganti peran & nama"></button>
    <button class="btn primary" id="btn-new" data-only="polisi">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Catat Issue
    </button>
  </div>
</section>

<div class="section-label">Ringkasan</div>
<div class="tiles" id="tiles">
  @foreach (['pending' => ['slate', 'Belum dikerjakan'], 'onprogress' => ['yellow', 'Sedang dikerjakan'], 'trouble' => ['red', 'Ada kendala'], 'done' => ['green', 'Selesai']] as $key => [$color, $desc])
    <button class="tile" data-status="{{ $key }}" title="Klik untuk tampilkan/sembunyikan {{ $statuses[$key] }}">
      <span class="tile-icon {{ $color }}">
        @switch($key)
          @case('pending')<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>@break
          @case('onprogress')<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>@break
          @case('trouble')<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>@break
          @default<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        @endswitch
      </span>
      <span class="tile-body">
        <span class="tile-num" data-count="{{ $key }}">–</span>
        <span class="tile-title">{{ $statuses[$key] }}</span>
        <span class="tile-desc">{{ $desc }}</span>
      </span>
    </button>
  @endforeach
</div>

<div class="section-label">Daftar Issue</div>
<section class="card">
  <div class="toolbar">
    <div class="seg" id="presets">
      <button data-p="today">Hari ini</button>
      <button data-p="yesterday">Kemarin</button>
      <button data-p="week">7 hari</button>
      <button data-p="month">Bulan ini</button>
      <button data-p="lastmonth">Bulan lalu</button>
    </div>
    <label class="month-pick" title="Lihat rekap bulan tertentu">Bulan
      <input type="month" id="month" aria-label="Pilih bulan">
    </label>
    <div class="dates">
      <input type="date" id="start" aria-label="Tanggal mulai"> <span class="muted">s/d</span>
      <input type="date" id="end" aria-label="Tanggal akhir">
    </div>
    <select id="team-filter" aria-label="Filter team">
      <option value="">Semua team</option>
      @foreach ($teams as $t)<option>{{ $t }}</option>@endforeach
    </select>
    <input class="pill-search grow" id="q" type="search" placeholder="Cari issue, aplikasi, pelapor, PIC... (tekan /)">
  </div>
  <div class="table-head">
    <span class="count" id="count"></span>
    <span class="sync" id="sync" role="status" aria-live="polite" hidden></span>
    <span class="spacer"></span>
    <a class="btn small outline" id="btn-excel" href="#" title="Unduh issue periode ini (sesuai filter team) sebagai Excel">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
      Download Excel
    </a>
    <a class="link-btn" href="{{ url('/rekap') }}">Rekap &amp; kirim ke Telegram →</a>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead>
        <tr><th>No</th><th>Lapor</th><th>Issue</th><th>Aplikasi</th><th>Team</th><th>Pelapor</th><th>Status</th><th>PIC</th><th></th></tr>
      </thead>
      <tbody id="rows"></tbody>
    </table>
    <div class="empty" id="empty" hidden></div>
  </div>
</section>
@endsection

@section('modals')
{{-- Pilih peran (tanpa login: portal yang sudah login bisa mengirim ?role=polisi|engineer&nama=...) --}}
<div class="modal-bg" id="m-role" hidden>
  <div class="modal small" role="dialog" aria-modal="true" aria-labelledby="m-role-t">
    <div class="modal-h"><h2 id="m-role-t">Peran & nama</h2><button class="x" data-close aria-label="Tutup">×</button></div>
    <div class="modal-b">
      <div class="role-pick">
        <label class="role-opt"><input type="radio" name="role" value="polisi"><span><b>Polisi</b><small>Mencatat issue</small></span></label>
        <label class="role-opt"><input type="radio" name="role" value="engineer"><span><b>Engineer</b><small>Menindaklanjuti issue</small></span></label>
      </div>
      <label class="field">Nama<input id="role-nama" maxlength="100" placeholder="Nama kamu (dipakai sebagai pencatat / PIC)"></label>
    </div>
    <div class="modal-f"><button class="btn" data-close>Batal</button><button class="btn primary" id="role-save">Simpan</button></div>
  </div>
</div>

{{-- Polisi: catat issue baru --}}
<div class="modal-bg" id="m-new" hidden>
  <form class="modal" role="dialog" aria-modal="true" aria-labelledby="m-new-t" id="f-new" novalidate>
    <div class="modal-h"><h2 id="m-new-t">Catat Issue Baru</h2><button type="button" class="x" data-close aria-label="Tutup">×</button></div>
    <div class="modal-b grid2">
      <label class="field span2">Issue<textarea name="issue" rows="4" required maxlength="5000" placeholder="Tempel/tulis isi laporan"></textarea></label>
      <div class="field span2">
        <span>Lampiran gambar / file <span class="hint">opsional · maks 10 file, 10 MB per file</span></span>
        <div class="dropzone" id="dropzone">
          <input type="file" id="file-input" multiple hidden
                 accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.log,.zip,.rar,.7z">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 11-8.5 8.5a5 5 0 0 1-7-7L14 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 7"/></svg>
          <span>Tarik file ke sini, <button type="button" class="link" id="pick-files">pilih file</button>, atau tempel screenshot (Ctrl+V)</span>
        </div>
        <div class="att-list" id="att-old" hidden></div>
        <div class="att-list" id="att-new"></div>
      </div>
      <label class="field">Aplikasi
        <input name="aplikasi" list="apps" required maxlength="150" autocomplete="off" placeholder="mis. Rekon ATM">
        <span class="hint" id="app-hint"></span>
      </label>
      <label class="field">Team
        <select name="team" required>
          <option value="">Pilih team</option>
          @foreach ($teams as $t)<option>{{ $t }}</option>@endforeach
        </select>
        <span class="hint" id="team-hint"></span>
      </label>
      <label class="field">Pelapor<input name="pelapor" required maxlength="150" placeholder="Nama / nomor pelapor"></label>
      <label class="field">Media Laporan
        <input name="media_laporan" list="media" required maxlength="100" data-default="{{ $mediaDefault }}" placeholder="WhatsApp, Email, ... (boleh diketik)">
      </label>
      <label class="field">Penerima Laporan<input name="penerima_laporan" list="penerima" required maxlength="150" placeholder="Nama group / RCO Official / nama sendiri"></label>
      <label class="field">Pencatat Issue<input name="pencatat_issue" required maxlength="100"></label>
      <label class="field">Waktu Lapor<input name="waktu_lapor" type="datetime-local" required></label>
      <div class="field"><span>Status</span><span class="badge pending" id="new-status">Pending</span><span class="hint" id="new-status-hint">Otomatis untuk issue baru</span></div>
      <p class="form-error span2" hidden></p>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Batal</button><button class="btn primary" type="submit" id="new-submit">Simpan Issue</button></div>
  </form>
</div>

{{-- Engineer: tindak lanjut --}}
<div class="modal-bg" id="m-progress" hidden>
  <form class="modal" role="dialog" aria-modal="true" aria-labelledby="m-progress-t" id="f-progress" novalidate>
    <div class="modal-h"><h2 id="m-progress-t">Tindak Lanjut Issue</h2><button type="button" class="x" data-close aria-label="Tutup">×</button></div>
    <div class="modal-b">
      <div class="summary" id="progress-summary"></div>
      <div class="grid2">
        <label class="field">Status
          <select name="status" required>
            @foreach ($statuses as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
          </select>
        </label>
        <label class="field">PIC<input name="pic" maxlength="100" placeholder="Nama engineer yang mengerjakan"></label>
        <label class="field">Waktu Mulai<input name="waktu_mulai" type="time"></label>
        <label class="field">Tanggal Finish<input name="tanggal_finish" type="date"></label>
        <label class="field">Waktu Selesai<input name="waktu_selesai" type="time"></label>
      </div>
      <p class="hint" id="submit-hint"></p>
      <p class="form-error" hidden></p>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Batal</button><button class="btn primary" type="submit" id="progress-submit" disabled>Submit</button></div>
  </form>
</div>

{{-- Detail (read-only) --}}
<div class="modal-bg" id="m-detail" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-detail-t">
    <div class="modal-h"><h2 id="m-detail-t">Detail Issue</h2><button class="x" data-close aria-label="Tutup">×</button></div>
    <div class="modal-b"><div class="summary" id="detail-body"></div></div>
    <div class="modal-f"><button class="btn" data-close>Tutup</button><button class="btn outline" id="detail-to-edit" data-only="polisi">Edit Issue</button><button class="btn primary" id="detail-to-progress" data-only="engineer">Tindak Lanjut</button></div>
  </div>
</div>

<datalist id="apps"></datalist>
<datalist id="penerima"><option>RCO Official</option></datalist>
<datalist id="media">@foreach ($media as $m)<option>{{ $m }}</option>@endforeach</datalist>
@endsection

@section('scripts')
<script src="{{ asset('static/js/daily.js') }}" defer></script>
@endsection
