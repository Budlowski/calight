// Projektdetailseite: Neuanlage eines Angebots direkt im Projektkontext.
(() => {
  "use strict";

  const root = document.querySelector("#project-detail");
  const template = root?.querySelector("[data-offer-template]");
  if (!root || !template) return;

  root.querySelector("[data-new-offer]")?.addEventListener("click", () => {
    const form = template.content.firstElementChild.cloneNode(true);
    Calight.createDialog({
      title: "Neues Angebot",
      form,
      submitLabel: "Entwurf anlegen",
      discardMessage: "Den begonnenen Angebotsentwurf verwerfen?",
      initialFocus: '[name="title"]',
      submit: async () => {
        const result = await Calight.api("/api/offers", {
          method: "POST",
          body: {
            projectId: root.dataset.projectId,
            customerId: form.elements.customerId.value,
            title: form.elements.title.value,
          },
        });
        location.assign(result.url);
      },
    });
  });
})();
