@extends('layouts.portal')

@section('title', 'Daily Issue RCO')
@section('page', 'daily')

@section('content')
<div id="daily-config" hidden
     data-portal-nama="{{ $portalNama }}"
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
    <button class="btn small outline" id="btn-sync" type="button" title="Ambil data terbaru dari spreadsheet (seperti update-data.bat)">
      <svg class="sync-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
      <span>Update Data</span>
    </button>
    <button class="btn primary" id="btn-new" data-only="polisi">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Catat Issue
    </button>
  </div>
</section>

@include('daily.cards.summary')
@include('daily.cards.follow-up')
@include('daily.cards.filters')

<div class="grid-main">
  @include('daily.cards.issues')
  @include('daily.cards.teams')
</div>
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
    <div class="modal-f"><button type="button" class="btn danger" id="edit-delete" hidden>Hapus Issue</button><span class="spacer"></span><button type="button" class="btn" data-close>Batal</button><button class="btn primary" type="submit" id="new-submit">Simpan Issue</button></div>
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
        <label class="field">Aplikasi (IP)<textarea name="aplikasi_ip" rows="2" maxlength="2000" placeholder="mis. 172.18.x.x&#10;DB : nama database"></textarea></label>
        <label class="field span2">Note<textarea name="note" rows="3" maxlength="20000" placeholder="Catatan pengerjaan, query, langkah yang sudah dilakukan"></textarea></label>
        <label class="field span2">Dokumentasi<textarea name="dokumentasi" rows="2" maxlength="20000" placeholder="Link dokumentasi / folder Drive / keterangan"></textarea>
          <span class="hint">Tidak ikut dikirim ke Telegram</span></label>
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
    <div class="modal-f"><button class="btn danger" id="detail-to-delete" data-only="polisi">Hapus</button><span class="spacer"></span><button class="btn" data-close>Tutup</button><button class="btn outline" id="detail-to-follow-up" data-only="polisi" aria-pressed="false">Tambah Follow-up</button><button class="btn outline" id="detail-to-edit" data-only="polisi">Edit Issue</button><button class="btn primary" id="detail-to-progress" data-only="engineer">Tindak Lanjut</button></div>
  </div>
</div>

{{-- Polisi: hapus issue (konfirmasi) --}}
<div class="modal-bg" id="m-delete" hidden>
  <div class="modal small" role="alertdialog" aria-modal="true" aria-labelledby="m-delete-t">
    <div class="modal-h"><h2 id="m-delete-t">Hapus issue?</h2><button class="x" data-close aria-label="Tutup">×</button></div>
    <div class="modal-b">
      <p id="delete-text"></p>
      <p class="hint">Issue hilang dari tabel, rekap, bot, dan Excel. Data tetap tersimpan di database (bisa dipulihkan admin).</p>
      <p class="form-error" hidden></p>
    </div>
    <div class="modal-f"><button class="btn" data-close>Batal</button><button class="btn danger-solid" id="delete-yes">Ya, hapus</button></div>
  </div>
</div>

{{-- Polisi: kirim rekap ke Telegram (format sama dengan bot /rekap) --}}
<div class="modal-bg" id="m-tg" hidden>
  <div class="modal wide" role="dialog" aria-modal="true" aria-labelledby="m-tg-t">
    <div class="modal-h">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="#229ed9"><path d="M21.9 4.6 18.7 19.8c-.2 1.1-.9 1.3-1.8.8l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.4-5 9-8.2c.4-.4-.1-.6-.6-.2L6.3 13.4l-4.8-1.5c-1-.3-1.1-1 .2-1.5L20.4 3.2c.9-.3 1.7.2 1.5 1.4z"/></svg>
      <h2 id="m-tg-t">Kirim ke Telegram</h2><button class="x" data-close aria-label="Tutup">×</button>
    </div>
    <div class="tg-opts">
      <label class="switch"><input type="checkbox" id="opt-detail"> Tampilkan nama aplikasi</label>
      <label class="switch"><input type="checkbox" id="opt-split"> Tiap kategori bubble terpisah</label>
      <span class="muted" id="tg-info"></span>
    </div>
    <div class="tg-area" id="tg-preview"></div>
    <div class="confirm-box" id="tg-confirm" hidden>
      <span id="tg-confirm-text"></span><span class="spacer"></span>
      <button class="btn" id="tg-confirm-no">Batal</button><button class="btn tg" id="tg-confirm-yes">Ya, kirim sekarang</button>
    </div>
    <div class="modal-f">
      <span class="tg-target" id="tg-target"></span><span class="spacer"></span>
      <button class="btn" data-close>Tutup</button>
      <button class="btn" id="tg-copy">Salin isi chat</button>
      <button class="btn tg" id="tg-send">Kirim ke Telegram</button>
    </div>
  </div>
</div>

<datalist id="apps"></datalist>
<datalist id="penerima"><option>RCO Official</option></datalist>
<datalist id="media">@foreach ($media as $m)<option>{{ $m }}</option>@endforeach</datalist>
@endsection

@section('scripts')
<script src="{{ asset('static/js/daily.js') }}" defer></script>
@endsection
