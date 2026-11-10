(() => {
  "use strict";

  function create({ root, module, render }) {
    const buttons = [...root.querySelectorAll("[data-list-view]")];
    const mobileDefault = matchMedia("(max-width: 700px)").matches ? "cards" : "table";
    const storageKey = `calight:list-view:${document.body.dataset.userId || "anonymous"}:${module}`;
    let view = localStorage.getItem(storageKey) || mobileDefault;
    if (!buttons.some((button) => button.dataset.listView === view)) view = mobileDefault;

    function apply(next, persist = true) {
      view = next;
      root.dataset.listView = view;
      buttons.forEach((button) => {
        const selected = button.dataset.listView === view;
        button.classList.toggle("is-active", selected);
        button.setAttribute("aria-pressed", String(selected));
      });
      if (persist) localStorage.setItem(storageKey, view);
      render(view);
    }

    buttons.forEach((button) => button.addEventListener("click", () => apply(button.dataset.listView)));
    apply(view, false);
    return { get view() { return view; }, render: () => render(view) };
  }

  function enableRowNavigation(container) {
    const open = (event) => {
      const item = event.target.closest("[data-href]");
      if (!item || event.target.closest("a,button,input,select,textarea,details,summary")) return;
      if (event.type === "keydown" && !["Enter", " "].includes(event.key)) return;
      if (event.type === "keydown") event.preventDefault();
      location.href = item.dataset.href;
    };
    container.addEventListener("click", open);
    container.addEventListener("keydown", open);
  }

  globalThis.CalightListView = Object.freeze({ create, enableRowNavigation });
})();
