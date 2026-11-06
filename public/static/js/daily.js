// Daily Issue RCO: tabel issue, popup catat issue (polisi), popup tindak lanjut (engineer),
// rekomendasi Aplikasi -> Team, dan pembaruan otomatis tiap 30 detik.
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const cfgEl = $("#daily-config");
  const TEAMS = JSON.parse(cfgEl.dataset.teams);
  const STATUS = JSON.parse(cfgEl.dataset.statuses);           // {pending: "Pending", ...}
  const ENGINEER_OK = JSON.parse(cfgEl.dataset.engineerStatuses); // status yang boleh disubmit engineer
  const POLL_MS = 30000;
  const ROLE_KEY = "dailyIssue.role";
  const PORTAL_NAMA = cfgEl.dataset.portalNama || "";  // nama dari akun portal (terkunci)

  const st = {
    role: "polisi", nama: "",
    preset: "today", start: "", end: "",
    teams: new Set(TEAMS), q: "", hidden: new Set(),   // tim yang dipilih; status yang disembunyikan
    rows: [], byId: new Map(), apps: new Map(),
    loading: false, error: "", fetchedAt: null, current: null,
  };

  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const pad = (n) => String(n).padStart(2, "0");
  const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const fmtDate = (s) => (s ? s.split("-").reverse().join("/") : "");
  const nowLocal = () => { const d = new Date(); return `${isoDate(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };
  const nowTime = () => { const d = new Date(); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
  const normApp = (s) => String(s || "").trim().replace(/\s+/g, " ").toLowerCase();

  async function api(path, method = "GET", body) {
    const opt = { method, headers: { Accept: "application/json" } };
    if (body instanceof FormData) {
      // Unggah lampiran: browser mengatur Content-Type multipart; header ini menandai request dari halaman sendiri.
      opt.headers["X-Requested-With"] = "XMLHttpRequest";
      opt.body = body;
    } else if (body !== undefined) { opt.headers["Content-Type"] = "application/json"; opt.body = JSON.stringify(body); }
    let r;
    try { r = await fetch(path, opt); } catch (e) { throw new Error("Server tidak bisa dihubungi"); }
    const data = await r.json().catch(() => ({}));
    if (!r.ok) { const err = new Error(data.error || `HTTP ${r.status}`); err.errors = data.errors; throw err; }
    return data;
  }

  // ---------- peran (tanpa login: portal bisa mengirim ?role=&nama=) ----------
  function loadRole() {
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(ROLE_KEY) || "{}"); } catch (e) { /* storage diblokir */ }
    const url = new URLSearchParams(location.search);
    const role = url.get("role") || saved.role;
    st.role = role === "engineer" ? "engineer" : "polisi";
    st.nama = (PORTAL_NAMA || url.get("nama") || saved.nama || "").slice(0, 100);
    if (url.get("role") || url.get("nama")) saveRole();
    applyRole();
  }
  function saveRole() {
    try { localStorage.setItem(ROLE_KEY, JSON.stringify({ role: st.role, nama: st.nama })); } catch (e) { /* abaikan */ }
  }
  function applyRole() {
    document.body.classList.toggle("role-polisi", st.role === "polisi");
    document.body.classList.toggle("role-engineer", st.role === "engineer");
    const label = `${st.role === "engineer" ? "Engineer" : "Polisi"}${st.nama ? " · " + st.nama : ""}`;
    $("#role-chip").textContent = label;
    // Topbar: nama + label peran (mis. "Eka [Polisi]")
    const top = $("#topbar-user");
    if (top) {
      const role = st.role === "engineer" ? "Engineer" : "Polisi";
      top.innerHTML = `${st.nama ? `<span class="tu-name">${esc(st.nama)}</span>` : ""}<span class="role-badge ${st.role}">${role}</span>`;
      top.title = `Masuk sebagai ${role}${st.nama ? ` (${st.nama})` : ""} · klik untuk ganti`;
    }
    renderRows();
  }

  // ---------- periode ----------
  function setPreset(p) {
    const t = new Date();
    const d = (off) => { const x = new Date(t); x.setDate(x.getDate() + off); return isoDate(x); };
    st.preset = p;
    if (p === "today") [st.start, st.end] = [d(0), d(0)];
    if (p === "yesterday") [st.start, st.end] = [d(-1), d(-1)];
    const dow = (t.getDay() + 6) % 7;  // Senin = 0
    if (p === "week") [st.start, st.end] = [d(-dow), d(0)];
    if (p === "lastweek") [st.start, st.end] = [d(-dow - 7), d(-dow - 1)];
    if (p === "month") [st.start, st.end] = [isoDate(new Date(t.getFullYear(), t.getMonth(), 1)), d(0)];
    if (p === "lastmonth") [st.start, st.end] = [isoDate(new Date(t.getFullYear(), t.getMonth() - 1, 1)), isoDate(new Date(t.getFullYear(), t.getMonth(), 0))];
    syncPeriod();
  }

  // Satu bulan penuh (dari pemilih bulan): untuk melihat & mengunduh rekap bulan-bulan sebelumnya.
  function setMonth(ym) {
    const [y, m] = ym.split("-").map(Number);
    st.preset = "";
    [st.start, st.end] = [isoDate(new Date(y, m - 1, 1)), isoDate(new Date(y, m, 0))];
    syncPeriod();
  }

  function syncPeriod() {
    $("#start").value = st.start; $("#end").value = st.end;
    $$("#presets button").forEach((b) => b.classList.toggle("on", b.dataset.p === st.preset));
    // Pemilih bulan menunjukkan bulannya kalau periode tepat satu bulan penuh
    const s = st.start.slice(0, 7);
    const [y, m] = s.split("-").map(Number);
    const full = st.start.endsWith("-01") && st.end === isoDate(new Date(y, m, 0));
    $("#month").value = full ? s : "";
    updateExcelLink();
  }

  function updateExcelLink() {
    const q = new URLSearchParams({ start: st.start, end: st.end });
    if (st.teams.size < TEAMS.length) q.set("teams", [...st.teams].join(","));
    $("#btn-excel").href = `api/daily/export?${q}`;
  }

  // ---------- data ----------
  let reqId = 0;
  async function load(quiet = false) {
    if (!st.start || !st.end) return;
    const id = ++reqId;
    if (!quiet) { st.loading = true; st.error = ""; renderSync(); }
    try {
      const data = await api(`api/daily/issues?start=${st.start}&end=${st.end}`);
      if (id !== reqId) return;
      const before = new Set(st.rows.map((r) => `${r.id}:${r.updated_at}`));
      st.rows = data.issues;
      st.byId = new Map(st.rows.map((r) => [r.id, r]));
      st.fetchedAt = new Date(data.fetched_at);
      st.error = "";
      renderAll(quiet ? before : null);
    } catch (e) {
      if (id !== reqId) return;
      if (!quiet) st.error = e.message;
    } finally {
      if (id === reqId) { st.loading = false; renderSync(); }
    }
  }

  async function loadApps() {
    try {
      const apps = await api("api/daily/aplikasi");
      st.apps = new Map(apps.map((a) => [normApp(a.nama), a]));
      $("#apps").innerHTML = apps.map((a) => `<option value="${esc(a.nama)}">${esc(a.team)}</option>`).join("");
    } catch (e) { /* rekomendasi tidak wajib */ }
  }

  // ---------- render ----------
  function visibleRows() {
    const q = st.q.toLowerCase();
    return st.rows.filter((r) => !st.hidden.has(r.status) && st.teams.has(r.team) && (!q ||
      `${r.nomor} ${r.issue} ${r.aplikasi} ${r.team} ${r.pelapor} ${r.pic} ${r.pencatat_issue} ${STATUS[r.status]} ${r.note} ${r.aplikasi_ip} ${r.dokumentasi}`.toLowerCase().includes(q)));
  }

  function renderTiles() {
    const base = st.rows.filter((r) => st.teams.has(r.team));
    for (const k of Object.keys(STATUS)) {
      $(`[data-count="${k}"]`).textContent = st.rows.length || !st.loading ? base.filter((r) => r.status === k).length : "–";
      $(`.tile[data-status="${k}"]`).classList.toggle("off", st.hidden.has(k));
    }
  }

  function actionButton(r) {
    if (st.role === "engineer") {
      const label = r.status === "pending" ? "Kerjakan" : "Update";
      return `<button class="btn small ${r.status === "pending" ? "primary" : "outline"}" data-act="progress" data-id="${r.id}">${label}</button>`;
    }
    return `<button class="btn small outline" data-act="edit" data-id="${r.id}">Edit</button>`;
  }

  // "Issue 5 · Nov 2026": nomor mulai dari 1 lagi tiap bulan.
  const BULAN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
  const issueLabel = (r) => `Issue ${r.nomor}${r.bulan ? ` · ${BULAN[Number(r.bulan.slice(5)) - 1]} ${r.bulan.slice(0, 4)}` : ""}`;

  const CLIP = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 11-8.5 8.5a5 5 0 0 1-7-7L14 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 7"/></svg>';
  // Penanda di tabel: issue ini punya lampiran (isinya dilihat saat issue dibuka).
  function attBadge(r) {
    const att = r.lampiran || [];
    if (!att.length) return "";
    const img = att.filter((a) => a.gambar).length;
    const parts = [img && `${img} gambar`, att.length - img && `${att.length - img} file`].filter(Boolean);
    return `<span class="att-badge" title="${esc(att.map((a) => a.nama).join("\n"))}">${CLIP}${parts.join(" · ")}</span>`;
  }

  function renderRows(changed = null) {
    const rows = visibleRows();
    $("#count").textContent = `${rows.length} issue${rows.length !== st.rows.length ? ` dari ${st.rows.length}` : ""}`;
    const openTitle = st.role === "engineer" ? "Klik untuk tindak lanjut" : "Klik untuk lihat detail";
    $("#rows").innerHTML = rows.map((r) => {
      const flash = changed && !changed.has(`${r.id}:${r.updated_at}`) ? " flash" : "";
      return `<tr class="s-${r.status}${flash}" data-id="${r.id}" title="${openTitle}">
        <td class="no" title="${esc(issueLabel(r))}">${r.nomor}</td>
        <td class="lapor">${fmtDate(r.tanggal)}<br><span class="muted">${esc(r.waktu_lapor || "")}</span></td>
        <td class="issue"><div class="clamp">${esc(r.issue)}</div>${attBadge(r)}</td>
        <td>${esc(r.aplikasi) || '<span class="muted">—</span>'}</td>
        <td><span class="team-tag">${esc(r.team)}</span></td>
        <td>${esc(r.pelapor)}</td>
        <td><span class="badge ${r.status}">${esc(STATUS[r.status] || r.status)}</span></td>
        <td>${esc(r.pic) || '<span class="muted">—</span>'}</td>
        <td class="note">${r.note ? `<div class="clamp" title="${esc(r.note)}">${esc(r.note)}</div>` : '<span class="muted">—</span>'}</td>
        <td class="ip">${r.aplikasi_ip ? `<div class="clamp" title="${esc(r.aplikasi_ip)}">${esc(r.aplikasi_ip)}</div>` : '<span class="muted">—</span>'}</td>
        <td class="dok">${r.dokumentasi ? `<div class="clamp">${linkify(r.dokumentasi)}</div>` : '<span class="muted">—</span>'}</td>
        <td>${actionButton(r)}</td>
      </tr>`;
    }).join("");
    const empty = $("#empty");
    empty.hidden = rows.length > 0 || st.loading;
    empty.textContent = st.rows.length ? "Tidak ada issue yang cocok dengan filter." : "Belum ada issue di periode ini.";
  }

  function renderSync() {
    const el = $("#sync");
    el.hidden = false;
    if (st.loading) { el.className = "sync busy"; el.innerHTML = '<span class="spin"></span>Mengambil data…'; el.title = ""; }
    else if (st.error) { el.className = "sync err"; el.textContent = "Gagal memuat"; el.title = st.error; }
    else if (st.fetchedAt) {
      el.className = "sync ok";
      el.textContent = `Diperbarui ${st.fetchedAt.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}`;
      el.title = "Otomatis diperbarui tiap 30 detik";
    } else el.hidden = true;
    renderTiles();
    if (st.loading && !st.rows.length) $("#empty").hidden = true;
  }

  // Chip tim & kategori + panel Per Tim (bar: pending, on progress, trouble, done)
  function renderFilters() {
    $$("#team-chips .chip").forEach((b) => b.classList.toggle("on", st.teams.has(b.dataset.t)));
    $("#teams").innerHTML = TEAMS.map((t) => {
      const rows = st.rows.filter((r) => r.team === t);
      const c = Object.fromEntries(Object.keys(STATUS).map((k) => [k, rows.filter((r) => r.status === k).length]));
      const tot = rows.length || 1;
      const parts = [`${c.pending} pending`, `${c.onprogress} progress`, c.trouble ? `${c.trouble} trouble` : "", `${c.done} done`].filter(Boolean);
      return `<div class="team-row ${st.teams.has(t) ? "" : "off"}">
        <div class="tr-h"><b>${esc(t)}</b><span class="muted">${parts.join(" · ")}</span></div>
        <div class="bar">${Object.keys(STATUS).map((k) => `<span class="b-${k}" style="width:${(c[k] / tot) * 100}%"></span>`).join("")}</div>
      </div>`;
    }).join("");
    updateExcelLink();
  }

  function renderAll(changed = null) { renderTiles(); renderFilters(); renderRows(changed); }

  // ---------- popup umum ----------
  function openModal(id) {
    const m = $(id);
    m.hidden = false;
    const first = m.querySelector("textarea, input:not([type=hidden]):not([type=radio]), select");
    if (first) setTimeout(() => first.focus(), 30);
  }
  function closeModal(m) {
    m.hidden = true;
    if (new URLSearchParams(location.search).has("issue")) {
      const u = new URL(location.href); u.searchParams.delete("issue"); history.replaceState(null, "", u);
    }
  }
  const anyModalOpen = () => $$(".modal-bg").some((m) => !m.hidden);
  function showFormError(form, err) {
    const box = form.querySelector(".form-error");
    box.textContent = err.message;
    box.hidden = false;
    $$(".invalid", form).forEach((el) => el.classList.remove("invalid"));
    Object.keys(err.errors || {}).forEach((name) => form.elements[name]?.classList.add("invalid"));
  }
  function clearFormError(form) {
    form.querySelector(".form-error").hidden = true;
    $$(".invalid", form).forEach((el) => el.classList.remove("invalid"));
  }

  // ---------- polisi: catat issue ----------
  const fNew = $("#f-new");
  // Popup yang sama dipakai untuk catat baru dan edit (polisi memperbaiki salah ketik).
  let editing = null;           // issue yang sedang diedit, null = catat baru
  const removeIds = new Set();  // lampiran lama yang akan dihapus saat disimpan

  function setFormMode(r) {
    editing = r;
    removeIds.clear();
    $("#m-new-t").textContent = r ? `Edit ${issueLabel(r)}` : "Catat Issue Baru";
    $("#new-submit").textContent = r ? "Simpan Perubahan" : "Simpan Issue";
    $("#edit-delete").hidden = !r;
    const status = r ? r.status : "pending";
    $("#new-status").className = `badge ${status}`;
    $("#new-status").textContent = STATUS[status];
    $("#new-status-hint").textContent = r ? "Status & PIC diisi engineer, tidak berubah saat edit" : "Otomatis untuk issue baru";
    renderOldFiles();
  }

  function renderOldFiles() {
    const box = $("#att-old");
    const att = editing?.lampiran || [];
    box.hidden = !att.length;
    box.innerHTML = att.map((a) => {
      const del = removeIds.has(a.id);
      const thumb = a.gambar ? `<img src="${esc(a.url)}" alt="">` : `<span class="file-ico">${esc(ext(a.nama))}</span>`;
      return `<div class="att-item${del ? " removed" : ""}">${thumb}<span><span class="att-name" title="${esc(a.nama)}">${esc(a.nama)}</span><br>
        <span class="att-size">${del ? "akan dihapus" : fmtSize(a.ukuran)}</span></span>
        <button type="button" class="att-del" data-old="${a.id}" aria-label="${del ? "Batal hapus" : "Hapus"} ${esc(a.nama)}">${del ? "↺" : "×"}</button></div>`;
    }).join("");
  }

  function openEdit(r) {
    fNew.reset();
    clearFormError(fNew);
    setFormMode(r);
    const f = fNew.elements;
    f.issue.value = r.issue;
    f.aplikasi.value = r.aplikasi;
    f.team.value = r.team;
    f.pelapor.value = r.pelapor;
    f.media_laporan.value = r.media_laporan;
    f.penerima_laporan.value = r.penerima_laporan;
    f.pencatat_issue.value = r.pencatat_issue;
    f.waktu_lapor.value = `${r.tanggal}T${r.waktu_lapor || "00:00"}`;
    // Tampilkan rekomendasi team tanpa mengubah team yang sudah tercatat
    const app = st.apps.get(normApp(r.aplikasi));
    teamAuto = app ? app.team : null;
    $("#app-hint").textContent = "";
    onTeamChange();
    newFiles = [];
    renderNewFiles();
    openModal("#m-new");
  }

  function openNew() {
    fNew.reset();
    clearFormError(fNew);
    setFormMode(null);
    fNew.elements.pencatat_issue.value = st.nama;
    fNew.elements.waktu_lapor.value = nowLocal();
    fNew.elements.media_laporan.value = fNew.elements.media_laporan.dataset.default || "";
    $("#app-hint").textContent = ""; $("#team-hint").textContent = "";
    teamAuto = null;
    newFiles = [];
    renderNewFiles();
    openModal("#m-new");
  }

  // Rekomendasi Aplikasi -> Team: aplikasi yang sudah dikenal otomatis mengisi team penanggung jawabnya.
  let teamAuto = null;
  function onAppInput() {
    const app = st.apps.get(normApp(fNew.elements.aplikasi.value));
    const appHint = $("#app-hint");
    if (app) {
      teamAuto = app.team;
      fNew.elements.team.value = app.team;
      appHint.className = "hint ok";
      appHint.textContent = `Aplikasi terdaftar · ${app.jumlah_issue} issue sebelumnya`;
    } else {
      teamAuto = null;
      appHint.className = "hint";
      appHint.textContent = fNew.elements.aplikasi.value.trim() ? "Aplikasi baru: akan didaftarkan dengan team yang dipilih" : "";
    }
    onTeamChange();
  }
  function onTeamChange() {
    const hint = $("#team-hint");
    const team = fNew.elements.team.value;
    if (teamAuto && team && team !== teamAuto) { hint.className = "hint warn"; hint.textContent = `Biasanya aplikasi ini ditangani ${teamAuto}`; }
    else if (teamAuto) { hint.className = "hint ok"; hint.textContent = "Diisi otomatis dari data aplikasi"; }
    else hint.textContent = "";
  }

  async function submitNew(e) {
    e.preventDefault();
    clearFormError(fNew);
    const body = new FormData(fNew);
    newFiles.forEach((f) => body.append("lampiran[]", f, f.name));
    removeIds.forEach((id) => body.append("hapus_lampiran[]", id));
    const btn = fNew.querySelector("[type=submit]");
    btn.disabled = true;
    const wasEditing = editing;
    try {
      const row = await api(wasEditing ? `api/daily/issues/${wasEditing.id}` : "api/daily/issues", "POST", body);
      closeModal($("#m-new"));
      newFiles = [];
      renderNewFiles();
      const att = row.lampiran.length ? ` dengan ${row.lampiran.length} lampiran` : "";
      Portal.toast(wasEditing ? `${issueLabel(row)} diperbarui${row.bulan !== wasEditing.bulan ? " (pindah bulan, nomor baru)" : ""}`
        : `${issueLabel(row)} tersimpan (Pending)${att}`);
      if (row.tanggal < st.start || row.tanggal > st.end) setPreset("today");
      await Promise.all([load(true), loadApps()]);
    } catch (err) {
      showFormError(fNew, err);
    } finally { btn.disabled = false; }
  }

  // ---------- engineer: tindak lanjut ----------
  const fProg = $("#f-progress");
  // Link http(s) di Dokumentasi bisa diklik (teks lain tetap di-escape)
  function linkify(text) {
    return String(text).split(/(https?:\/\/[^\s<>"']+)/g).map((part, i) =>
      i % 2 ? `<a href="${esc(part)}" target="_blank" rel="noopener noreferrer">${esc(part)}</a>` : esc(part)).join("");
  }

  function summaryHtml(r) {
    const item = (k, v) => `<dt>${k}</dt><dd>${v || '<span class="muted">—</span>'}</dd>`;
    return `<dl class="summary">
      ${item("Issue", esc(r.issue))}
      ${item("Aplikasi", esc(r.aplikasi))}
      ${item("Team", `<span class="team-tag">${esc(r.team)}</span>`)}
      ${item("Pelapor", esc(r.pelapor))}
      ${item("Media / penerima", esc([r.media_laporan, r.penerima_laporan].filter(Boolean).join(" · ")))}
      ${item("Pencatat", esc(r.pencatat_issue))}
      ${item("Waktu lapor", `${fmtDate(r.tanggal)} ${esc(r.waktu_lapor || "")}`)}
      ${item("Status", `<span class="badge ${r.status}">${esc(STATUS[r.status])}</span>`)}
      ${item("PIC", esc(r.pic))}
      ${item("Mulai / selesai", esc([r.waktu_mulai, r.tanggal_finish && fmtDate(r.tanggal_finish), r.waktu_selesai].filter(Boolean).join(" · ")))}
      ${item("Note", esc(r.note))}
      ${item("Aplikasi (IP)", esc(r.aplikasi_ip))}
      ${item("Dokumentasi", r.dokumentasi ? linkify(r.dokumentasi) : "")}
      ${r.sumber ? item("Sumber", `Impor spreadsheet (${esc(r.sumber)})`) : ""}
    </dl>${attView(r)}`;
  }

  const fmtSize = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
  const ext = (name) => (String(name).split(".").pop() || "file").slice(0, 4);

  // Lampiran di popup: gambar sebagai thumbnail (klik = buka ukuran penuh), file lain sebagai link unduh.
  function attView(r) {
    const att = r.lampiran || [];
    if (!att.length) return "";
    const imgs = att.filter((a) => a.gambar);
    const files = att.filter((a) => !a.gambar);
    return `<div class="att-view">
      ${imgs.length ? `<h3>Gambar (${imgs.length})</h3><div class="att-grid">${imgs.map((a) =>
        `<a href="${esc(a.url)}" target="_blank" rel="noopener" title="${esc(a.nama)}"><img src="${esc(a.url)}" alt="${esc(a.nama)}" loading="lazy"></a>`).join("")}</div>` : ""}
      ${files.length ? `<h3>File (${files.length})</h3><div class="att-files">${files.map((a) =>
        `<a class="att-item" href="${esc(a.url)}" download="${esc(a.nama)}" title="Unduh ${esc(a.nama)}"><span class="file-ico">${esc(ext(a.nama))}</span>
          <span><span class="att-name">${esc(a.nama)}</span><br><span class="att-size">${fmtSize(a.ukuran)}</span></span></a>`).join("")}</div>` : ""}
    </div>`;
  }

  // ---------- polisi: lampiran sebelum disimpan ----------
  const MAX_FILES = 10;
  const MAX_BYTES = 10 * 1024 * 1024;
  const OK_EXT = ["jpg", "jpeg", "png", "gif", "webp", "bmp", "pdf", "doc", "docx", "xls", "xlsx", "csv", "ppt", "pptx", "txt", "log", "zip", "rar", "7z"];
  let newFiles = [];

  function addFiles(list) {
    const errors = [];
    for (let f of list) {
      // Screenshot yang ditempel bernama "image.png": beri nama yang lebih jelas.
      if (/^image\.(png|jpe?g|gif|webp)$/i.test(f.name)) {
        const d = new Date();
        f = new File([f], `screenshot-${isoDate(d)}-${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}.${f.name.split(".").pop()}`, { type: f.type });
      }
      const e = f.name.split(".").pop().toLowerCase();
      if (!OK_EXT.includes(e)) { errors.push(`${f.name}: jenis file tidak didukung`); continue; }
      if (f.size > MAX_BYTES) { errors.push(`${f.name}: lebih dari 10 MB`); continue; }
      const kept = (editing?.lampiran || []).filter((a) => !removeIds.has(a.id)).length;
      if (kept + newFiles.length >= MAX_FILES) { errors.push(`Maksimal ${MAX_FILES} lampiran`); break; }
      newFiles.push(f);
    }
    renderNewFiles();
    if (errors.length) Portal.toast(errors.join(" · "), true);
  }

  function renderNewFiles() {
    $$("#att-new img").forEach((img) => URL.revokeObjectURL(img.src));
    $("#att-new").innerHTML = newFiles.map((f, i) => {
      const thumb = f.type.startsWith("image/") ? `<img src="${URL.createObjectURL(f)}" alt="">` : `<span class="file-ico">${esc(ext(f.name))}</span>`;
      return `<div class="att-item">${thumb}<span><span class="att-name" title="${esc(f.name)}">${esc(f.name)}</span><br>
        <span class="att-size">${fmtSize(f.size)}</span></span><button type="button" class="att-del" data-i="${i}" aria-label="Hapus ${esc(f.name)}">×</button></div>`;
    }).join("");
  }

  function openProgress(r) {
    st.current = r;
    clearFormError(fProg);
    $("#m-progress-t").textContent = `Tindak Lanjut ${issueLabel(r)}`;
    $("#progress-summary").outerHTML = `<div class="summary-wrap" id="progress-summary">${summaryHtml(r)}</div>`;
    const f = fProg.elements;
    f.status.value = r.status === "pending" ? "onprogress" : r.status;
    f.pic.value = r.pic || st.nama;
    f.waktu_mulai.value = r.waktu_mulai || "";
    f.tanggal_finish.value = r.tanggal_finish || "";
    f.waktu_selesai.value = r.waktu_selesai || "";
    f.note.value = r.note || "";
    f.aplikasi_ip.value = r.aplikasi_ip || "";
    f.dokumentasi.value = r.dokumentasi || "";
    onStatusChange();
    openModal("#m-progress");
  }

  // Submit hanya aktif kalau PIC terisi dan status On Progress / Trouble / Done.
  function updateSubmit() {
    const f = fProg.elements;
    const okStatus = ENGINEER_OK.includes(f.status.value);
    const okPic = f.pic.value.trim() !== "";
    $("#progress-submit").disabled = !(okStatus && okPic);
    const why = [];
    if (!okStatus) why.push("pilih status On Progress, Trouble, atau Done");
    if (!okPic) why.push("isi PIC");
    const hint = $("#submit-hint");
    hint.className = why.length ? "hint warn" : "hint";
    hint.textContent = why.length ? `Submit belum bisa: ${why.join(" dan ")}.` : "";
  }
  function onStatusChange() {
    const f = fProg.elements;
    if (["onprogress", "trouble", "done"].includes(f.status.value) && !f.waktu_mulai.value) f.waktu_mulai.value = nowTime();
    if (f.status.value === "done") {
      if (!f.tanggal_finish.value) f.tanggal_finish.value = isoDate(new Date());
      if (!f.waktu_selesai.value) f.waktu_selesai.value = nowTime();
    }
    updateSubmit();
  }

  async function submitProgress(e) {
    e.preventDefault();
    if ($("#progress-submit").disabled) return;
    clearFormError(fProg);
    const body = Object.fromEntries(new FormData(fProg).entries());
    const btn = $("#progress-submit");
    btn.disabled = true;
    try {
      const row = await api(`api/daily/issues/${st.current.id}/tindak-lanjut`, "PUT", body);
      closeModal($("#m-progress"));
      Portal.toast(`${issueLabel(row)}: ${STATUS[row.status]}`);
      await load(true);
    } catch (err) {
      showFormError(fProg, err);
      updateSubmit();
    }
  }

  function openDetail(r) {
    st.current = r;
    $("#m-detail-t").textContent = `Detail ${issueLabel(r)}`;
    $("#detail-body").outerHTML = `<div id="detail-body">${summaryHtml(r)}</div>`;
    openModal("#m-detail");
  }

  async function openById(id) {
    let r = st.byId.get(id);
    if (!r) {
      try { r = await api(`api/daily/issues/${id}`); } catch (e) { Portal.toast(e.message, true); return; }
    }
    st.role === "engineer" ? openProgress(r) : openDetail(r);
  }

  // ---------- polisi: hapus issue ----------
  let deleting = null;
  function openDelete(r) {
    deleting = r;
    const box = $("#m-delete");
    box.querySelector(".form-error").hidden = true;
    $("#delete-text").innerHTML = `Hapus <b>${esc(issueLabel(r))}</b> (${esc(r.aplikasi || "tanpa aplikasi")}, ${esc(r.team)})?`;
    $$(".modal-bg").filter((m) => !m.hidden).forEach(closeModal);
    openModal("#m-delete");
  }
  async function confirmDelete() {
    const btn = $("#delete-yes");
    btn.disabled = true;
    try {
      await api(`api/daily/issues/${deleting.id}`, "DELETE", { nama: st.nama });
      closeModal($("#m-delete"));
      Portal.toast(`${issueLabel(deleting)} dihapus`);
      await load(true);
    } catch (e) {
      const err = $("#m-delete .form-error");
      err.textContent = e.message; err.hidden = false;
    } finally { btn.disabled = false; }
  }

  // ---------- polisi: kirim rekap ke Telegram (format sama dengan bot /rekap) ----------
  const CAT_OF = { pending: "pending", onprogress: "progress", trouble: "progress", done: "done" };
  let tgReq = 0, tgMessages = [];
  // Yang dikirim = issue yang sedang tampil di tabel (periode, tim, kategori, pencarian).
  function tgBody() {
    const rows = visibleRows();
    const cats = [...new Set(Object.keys(STATUS).filter((k) => !st.hidden.has(k)).map((k) => CAT_OF[k]))];
    return { start: st.start, end: st.end, categories: cats, teams: [...st.teams], detail: $("#opt-detail").checked,
      split: $("#opt-split").checked, keys: rows.map((r) => `${r.bulan}#${r.nomor}`) };
  }
  async function tgPreview() {
    const id = ++tgReq;
    $("#tg-preview").innerHTML = '<div class="bubble"><span class="spin"></span> Menyusun pesan…</div>';
    $("#tg-send").disabled = true; $("#tg-copy").disabled = true; $("#tg-confirm").hidden = true;
    try {
      const data = await api("api/preview", "POST", tgBody());
      if (id !== tgReq) return;
      tgMessages = data.messages;
      const now = new Date().toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
      // Pesan berisi HTML Telegram yang disusun & di-escape server (sama dengan yang dikirim bot)
      $("#tg-preview").innerHTML = data.messages.map((m) =>
        `<div class="bubble"><div class="bot-name">Handover Bot</div>${m}<span class="bmeta">${now}</span></div>`).join("");
      const chat = data.chat;
      $("#tg-target").innerHTML = chat ? `Tujuan: <b>${esc(chat.title || "Grup Telegram")}</b> <span class="muted">(${esc(chat.id)})</span>`
        : "Tujuan Telegram belum diatur di .env";
      $("#tg-info").textContent = `${data.messages.length} pesan · ${visibleRows().length} issue`;
      $("#tg-send").disabled = !chat; $("#tg-copy").disabled = false;
    } catch (e) {
      if (id !== tgReq) return;
      $("#tg-preview").innerHTML = `<div class="bubble err">❌ ${esc(e.message)}</div>`;
    }
  }
  function openTelegram() {
    if (!visibleRows().length) { Portal.toast("Tidak ada issue yang tampil untuk dikirim", true); return; }
    openModal("#m-tg");
    tgPreview();
  }
  const plainText = (html) => { const d = document.createElement("div"); d.innerHTML = html; return d.textContent; };
  async function tgCopy() {
    const text = tgMessages.map(plainText).join("\n\n");
    try { await navigator.clipboard.writeText(text); Portal.toast("Isi chat disalin, tinggal tempel di Telegram/WhatsApp"); }
    catch (e) {
      // Fallback: browser lama / iframe tanpa izin clipboard
      const ta = document.createElement("textarea"); ta.value = text; document.body.appendChild(ta); ta.select();
      const ok = document.execCommand("copy"); ta.remove();
      Portal.toast(ok ? "Isi chat disalin" : "Browser menolak akses clipboard", !ok);
    }
  }
  async function tgSend() {
    const btn = $("#tg-confirm-yes");
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Mengirim…';
    try {
      const r = await api("api/send", "POST", tgBody());
      closeModal($("#m-tg"));
      Portal.toast(`${r.sent} pesan terkirim ke ${r.chat.title || r.chat.id}`);
    } catch (e) {
      Portal.toast(e.message, true);
    } finally { btn.disabled = false; btn.textContent = "Ya, kirim sekarang"; }
  }

  // ---------- event ----------
  function bind() {
    $$("#presets button").forEach((b) => b.addEventListener("click", () => { setPreset(b.dataset.p); load(); }));
    ["#start", "#end"].forEach((s) => $(s).addEventListener("change", () => {
      st.start = $("#start").value; st.end = $("#end").value; st.preset = "";
      syncPeriod();
      if (st.start && st.end && st.start <= st.end) load();
    }));
    $("#month").addEventListener("change", (e) => { if (e.target.value) { setMonth(e.target.value); load(); } });
    // Chip tim (minimal satu) & reset. Kategori difilter lewat kartu ringkasan; panel Per Tim hanya tampilan.
    $("#team-chips").addEventListener("click", (e) => {
      const b = e.target.closest(".chip"); if (!b) return;
      const t = b.dataset.t;
      if (st.teams.has(t) && st.teams.size > 1) st.teams.delete(t); else st.teams.add(t);
      renderAll();
    });
    $("#reset-filter").addEventListener("click", () => {
      st.teams = new Set(TEAMS); st.hidden.clear(); st.q = ""; $("#q").value = "";
      renderAll();
    });
    let qTimer;
    $("#q").addEventListener("input", (e) => { clearTimeout(qTimer); qTimer = setTimeout(() => { st.q = e.target.value.trim(); renderRows(); }, 150); });
    $$(".tile").forEach((t) => t.addEventListener("click", () => {
      const s = t.dataset.status;
      st.hidden.has(s) ? st.hidden.delete(s) : st.hidden.add(s);
      renderAll();
    }));

    // Klik tombol atau di mana saja pada baris: engineer -> popup tindak lanjut, polisi -> detail (ada tombol Edit).
    $("#rows").addEventListener("click", (e) => {
      if (e.target.closest("a") || window.getSelection().toString()) return;  // jangan ganggu link / seleksi teks
      const b = e.target.closest("[data-act]");
      const tr = e.target.closest("tr[data-id]");
      const r = st.byId.get(Number((b || tr)?.dataset.id));
      if (!r) return;
      const act = b ? b.dataset.act : (st.role === "engineer" ? "progress" : "detail");
      ({ progress: openProgress, edit: openEdit, detail: openDetail }[act] || openDetail)(r);
    });

    // Lampiran: pilih file, tarik-lepas, atau tempel (Ctrl+V) di popup catat issue.
    const input = $("#file-input");
    $("#pick-files").addEventListener("click", () => input.click());
    input.addEventListener("change", () => { addFiles(input.files); input.value = ""; });
    const dz = $("#dropzone");
    ["dragenter", "dragover"].forEach((t) => $("#m-new").addEventListener(t, (e) => { e.preventDefault(); dz.classList.add("over"); }));
    ["dragleave", "drop"].forEach((t) => $("#m-new").addEventListener(t, (e) => { e.preventDefault(); dz.classList.remove("over"); }));
    $("#m-new").addEventListener("drop", (e) => { if (e.dataTransfer?.files.length) addFiles(e.dataTransfer.files); });
    $("#m-new").addEventListener("paste", (e) => {
      const files = [...(e.clipboardData?.files || [])];
      if (files.length) { e.preventDefault(); addFiles(files); }  // teks biasa tetap ditempel ke kolom seperti biasa
    });
    $("#att-new").addEventListener("click", (e) => {
      const del = e.target.closest(".att-del");
      if (del) { newFiles.splice(Number(del.dataset.i), 1); renderNewFiles(); }
    });
    // Lampiran lama saat edit: klik × untuk tandai hapus, klik ↺ untuk batal
    $("#att-old").addEventListener("click", (e) => {
      const b = e.target.closest("[data-old]");
      if (!b) return;
      const id = Number(b.dataset.old);
      removeIds.has(id) ? removeIds.delete(id) : removeIds.add(id);
      renderOldFiles();
    });
    $("#btn-new").addEventListener("click", openNew);
    $("#btn-tg").addEventListener("click", openTelegram);
    ["#opt-detail", "#opt-split"].forEach((s) => $(s).addEventListener("change", tgPreview));
    $("#tg-copy").addEventListener("click", tgCopy);
    $("#tg-send").addEventListener("click", () => {
      const chat = $("#tg-target b")?.textContent || "Telegram";
      $("#tg-confirm-text").innerHTML = `Kirim <b>${tgMessages.length} pesan</b> ke <b>${esc(chat)}</b>?`;
      $("#tg-confirm").hidden = false;
    });
    $("#tg-confirm-no").addEventListener("click", () => { $("#tg-confirm").hidden = true; });
    $("#tg-confirm-yes").addEventListener("click", tgSend);
    $("#edit-delete").addEventListener("click", () => openDelete(editing));
    $("#detail-to-delete").addEventListener("click", () => openDelete(st.current));
    $("#delete-yes").addEventListener("click", confirmDelete);
    $("#detail-to-progress").addEventListener("click", () => { closeModal($("#m-detail")); openProgress(st.current); });
    $("#detail-to-edit").addEventListener("click", () => { closeModal($("#m-detail")); openEdit(st.current); });

    fNew.addEventListener("submit", submitNew);
    fNew.elements.aplikasi.addEventListener("input", onAppInput);
    fNew.elements.aplikasi.addEventListener("change", onAppInput);
    fNew.elements.team.addEventListener("change", onTeamChange);
    fProg.addEventListener("submit", submitProgress);
    fProg.elements.status.addEventListener("change", onStatusChange);
    fProg.elements.pic.addEventListener("input", updateSubmit);

    // Peran
    const openRole = () => {
      $$('#m-role input[name=role]').forEach((i) => { i.checked = i.value === st.role; });
      $("#role-nama").value = st.nama;
      $("#role-nama").readOnly = !!PORTAL_NAMA;
      openModal("#m-role");
    };
    $("#role-chip").addEventListener("click", openRole);
    $("#topbar-user")?.addEventListener("click", openRole);
    $("#role-save").addEventListener("click", () => {
      st.role = $('#m-role input[name=role]:checked')?.value === "engineer" ? "engineer" : "polisi";
      st.nama = PORTAL_NAMA || $("#role-nama").value.trim().slice(0, 100);
      saveRole(); applyRole();
      closeModal($("#m-role"));
    });

    $$(".modal-bg").forEach((m) => {
      m.addEventListener("click", (e) => { if (e.target === m || e.target.closest("[data-close]")) closeModal(m); });
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") $$(".modal-bg").filter((m) => !m.hidden).forEach(closeModal);
      if (e.key === "/" && !anyModalOpen() && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); $("#q").focus(); }
    });

    // Pembaruan otomatis (issue baru dari polisi / update engineer), hanya saat tab terlihat & tidak ada popup.
    setInterval(() => { if (!document.hidden && !anyModalOpen()) load(true); }, POLL_MS);
    document.addEventListener("visibilitychange", () => { if (!document.hidden && !anyModalOpen()) load(true); });
  }

  async function init() {
    bind();
    loadRole();
    setPreset("today");
    await Promise.all([load(), loadApps()]);
    const id = Number(new URLSearchParams(location.search).get("issue"));
    if (id) openById(id);
  }
  init();
})();
