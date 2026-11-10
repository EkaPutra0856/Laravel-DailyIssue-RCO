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
    <table id="issue-table">
      <thead>
        <tr>
          <th data-sort="nomor"><button type="button" class="sort-button" aria-label="Urutkan No">No</button></th>
          <th data-sort="lapor"><button type="button" class="sort-button" aria-label="Urutkan Lapor">Lapor</button></th>
          <th data-sort="issue"><button type="button" class="sort-button" aria-label="Urutkan Issue">Issue</button></th>
          <th data-sort="aplikasi"><button type="button" class="sort-button" aria-label="Urutkan Aplikasi">Aplikasi</button></th>
          <th data-sort="team"><button type="button" class="sort-button" aria-label="Urutkan Team">Team</button></th>
          <th data-sort="pelapor"><button type="button" class="sort-button" aria-label="Urutkan Pelapor">Pelapor</button></th>
          <th data-sort="status"><button type="button" class="sort-button" aria-label="Urutkan Status">Status</button></th>
          <th data-sort="pic"><button type="button" class="sort-button" aria-label="Urutkan PIC">PIC</button></th>
          <th data-sort="note"><button type="button" class="sort-button" aria-label="Urutkan Note">Note</button></th>
          <th data-sort="aplikasi_ip"><button type="button" class="sort-button" aria-label="Urutkan Aplikasi IP">Aplikasi (IP)</button></th>
          <th data-sort="dokumentasi"><button type="button" class="sort-button" aria-label="Urutkan Dokumentasi">Dokumentasi</button></th>
          <th></th>
        </tr>
      </thead>
      <tbody id="rows"></tbody>
    </table>
    <div class="empty" id="empty" hidden></div>
  </div>
</section>
