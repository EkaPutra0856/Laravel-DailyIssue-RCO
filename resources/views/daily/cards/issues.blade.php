<section class="card">
  <div class="table-head">
    <h2 class="card-title">Daftar Issue</h2>
    <span class="count" id="count"></span>
    <span class="sync" id="sync" role="status" aria-live="polite" hidden></span>
    <span class="spacer"></span>
    <a class="btn small outline" id="btn-excel" href="#" title="Unduh issue periode ini (sesuai filter tim) sebagai Excel">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
      Download Excel
    </a>
    <button class="btn small tg" id="btn-tg" data-only="polisi" title="Pratinjau, salin, atau kirim rekap issue yang tampil ke grup Telegram">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M21.9 4.6 18.7 19.8c-.2 1.1-.9 1.3-1.8.8l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.4-5 9-8.2c.4-.4-.1-.6-.6-.2L6.3 13.4l-4.8-1.5c-1-.3-1.1-1 .2-1.5L20.4 3.2c.9-.3 1.7.2 1.5 1.4z"/></svg>
      Kirim ke Telegram
    </button>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead>
        <tr><th>No</th><th>Lapor</th><th>Issue</th><th>Aplikasi</th><th>Team</th><th>Pelapor</th><th>Status</th><th>PIC</th><th>Note</th><th>Aplikasi (IP)</th><th>Dokumentasi</th><th></th></tr>
      </thead>
      <tbody id="rows"></tbody>
    </table>
    <div class="empty" id="empty" hidden></div>
  </div>
</section>
