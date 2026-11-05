// Kerangka PortalRCO: buka/tutup sidebar, cari menu, dan toast.
(() => {
  const body = document.body;
  const toggle = document.getElementById("nav-toggle");
  if (toggle) {
    toggle.addEventListener("click", () => {
      body.classList.toggle(window.innerWidth <= 800 ? "nav-open" : "nav-closed");
    });
  }

  const search = document.getElementById("menu-search");
  if (search) {
    search.addEventListener("input", () => {
      const q = search.value.trim().toLowerCase();
      document.querySelectorAll("#menu .menu-item").forEach((a) => {
        a.hidden = q !== "" && !a.textContent.toLowerCase().includes(q);
      });
    });
  }

  let timer;
  window.Portal = {
    toast(message, isError = false) {
      const t = document.getElementById("toast");
      t.textContent = message;
      t.classList.toggle("err", isError);
      t.classList.add("show");
      clearTimeout(timer);
      timer = setTimeout(() => t.classList.remove("show"), 3500);
    },
  };
})();
