window.addEventListener("DOMContentLoaded", function () {
  setTimeout(() => {
    // l'autoloader di Shoelace carica i componenti a parte: prima name e value non esistono
    const tags = new Set(
      [...document.querySelectorAll(":not(:defined)")]
        .map((e) => e.localName)
        .filter((t) => t.startsWith("sl-"))
    );
    Promise.all([...tags].map((t) => customElements.whenDefined(t))).then(init);
  }, 1500);
});

function init() {
  console.log("Avacy Dashboard JS Loaded");
  const form = document.querySelector("form#avacy-form");
  const wrap = document.querySelector(
    "body.toplevel_page_avacy-plugin-settings .wrap"
  );
  const edit_button = document.querySelector(".Edit");
  const submit_button = document.querySelector(".Submit");
  const checkboxes = document.querySelectorAll("sl-checkbox[name^=avacy_]");
  const inputConsentSolutionToken = document.querySelector(
    "sl-input[name=avacy_api_token]"
  );
  const switches = document.querySelectorAll("sl-switch[name^=avacy_]");
  const selects = document.querySelectorAll("sl-select[name^=avacy_]");
  const tab_group = document.querySelector("sl-tab-group");
  const active_tab_input = document.querySelector(
    "input[name=avacy_active_tab]"
  );
  const loader = document.querySelector(".AvacyLoader");
  const EditAccountPanel = document.querySelector(".EditAccountPanel");
  const RenderAccountPanel = document.querySelector(".RenderAccountPanel");
  const GlobalSubmit = document.querySelector(".Submit.Submit--Global");
  let disableSubmit = false;

  loader.classList.add("hidden");
  wrap.classList.remove("hide");

  edit_button &&
    edit_button.addEventListener("click", () => {
      EditAccountPanel.classList.remove("hidden");
      RenderAccountPanel.classList.add("hidden");
      globalSubmitStatus();
    });

  submit_button &&
    submit_button.addEventListener("click", () => {
      const url = new URL(window.location.href);
      // remove edit query parameter if it exists
      if (url.searchParams.has("edit")) {
        setTimeout(() => {
          url.searchParams.delete("edit");
          window.location
            .replace(url.toString())
            .then(() => console.log("Redirected to view mode"));
        }, 100);
      }
    });

  switches &&
    switches.forEach((element) => {
      element.addEventListener("sl-change", () => {
        const group = element.closest(".AvacyForms__Group");
        const select = group.querySelector("sl-select[name$=_form_user_identifier]");

        if (element.checked) {
          // add required attribute
          select.setAttribute("required", "");
        } else {
          // remove required attribute
          select.removeAttribute("required");
        }
      });
    });

  [checkboxes, switches, selects].forEach((elements) => {
    elements &&
      elements.forEach((element) => {
        element.addEventListener("sl-change", (event) => {
          validateRows();
          globalSubmitStatus();
        });
      });
  });

  // L'avviso sotto il menu di una caratteristica, aggiornato al cambio del menu (anche prima di salvare).
  // Solo informativo: la casella scelta deve avere l'obbligatorieta' attesa dalla caratteristica.
  selects.forEach((select) => {
    if (!select.name.includes("_consent_feature_")) return;
    select.addEventListener("sl-change", () => {
      const warning = select.closest(".AvacyFeature").querySelector(".AvacyFeatureWarning");
      const option = Array.from(select.querySelectorAll("sl-option")).find(
        (o) => o.value === select.value
      );
      const required = option ? option.getAttribute("data-required") : null;
      let text = "";
      if (warning.dataset.field === "required" && required === "0") {
        text = warning.dataset.textOptionalBox;
      }
      if (warning.dataset.field === "optional" && required === "1") {
        text = warning.dataset.textRequiredBox;
      }
      warning.textContent = text;
      warning.hidden = text === "";
    });
  });

  // Il pixel su una casella che attiva anche un'altra caratteristica dello stesso form.
  selects.forEach((select) => {
    if (!select.name.includes("_consent_feature_")) return;
    select.addEventListener("sl-change", () => {
      const group = select.closest("tbody");
      const pixel = Array.from(group.querySelectorAll("sl-select")).find((s) =>
        s.name.endsWith("_consent_feature_tracking_pixel")
      );
      const warning = group.querySelector(".AvacyFeatureWarning--Pixel");
      if (!pixel || !warning) return;
      const shared =
        pixel.value.startsWith("field:") &&
        Array.from(group.querySelectorAll("sl-select")).some(
          (s) => s !== pixel && s.name.includes("_consent_feature_") && s.value === pixel.value
        );
      warning.hidden = !shared;
    });
  });

  // L'identificativo scelto di nuovo e' uno dei campi del form: l'avviso non serve piu'.
  selects.forEach((select) => {
    if (!select.name.endsWith("_form_user_identifier")) return;
    select.addEventListener("sl-change", () => {
      const warning = select.closest(".AvacyData__Identifier").querySelector(".AvacyFeatureWarning--Identifier");
      if (warning) warning.hidden = true;
    });
  });

  // I link della notice portano alla riga del form, nella scheda dell'archivio.
  const showFormRow = () => {
    if (!window.location.hash.startsWith("#avacy-form-")) return;
    const row = document.getElementById(window.location.hash.slice(1));
    if (!row) return;
    tab_group && tab_group.show("consent-archive");
    setTimeout(() => row.scrollIntoView({ block: "center" }), 150);
  };
  window.addEventListener("hashchange", showFormRow);
  showFormRow();

  inputConsentSolutionToken &&
    inputConsentSolutionToken.addEventListener("input", (event) => {
      globalSubmitStatus();
    });

  tab_group &&
    tab_group.addEventListener("sl-tab-show", (event) => {
      active_tab_input.value = event.detail.name;
    });

  globalSubmitStatus = () => {
    if (disableSubmit) {
      GlobalSubmit.setAttribute("disabled", "");
    } else {
      GlobalSubmit.removeAttribute("disabled");
    }
  };

  // Ogni form con l'invio acceso vuole l'identificativo e almeno un dato: altrimenti niente salvataggio
  validateRows = () => {
    const groups = document.querySelectorAll(".AvacyForms .AvacyForms__Group");
    let incomplete = 0;

    groups.forEach((group) => {
      const checkboxes = group.querySelectorAll(".AvacyData__Checkboxes sl-checkbox");
      const save_switch = group.querySelector("sl-switch");
      const select = group.querySelector("sl-select[name$=_form_user_identifier]");

      const on = save_switch.checked;
      const hasData = Array.from(checkboxes).some((checkbox) => checkbox.checked);
      // un valore salvato che non e' piu' tra i campi del form non conta
      const hasIdentifier = Array.from(select.querySelectorAll("sl-option")).some((o) => o.value === select.value);

      group.classList.toggle("warning", on && !hasData);
      group.classList.toggle("warning-identifier", on && !hasIdentifier);
      on ? select.setAttribute("required", "") : select.removeAttribute("required");

      if (on && (!hasData || !hasIdentifier)) incomplete++;
    });

    disableSubmit = incomplete > 0;
    if (submitHint) submitHint.hidden = incomplete === 0;
  };

  // Il pulsante e' unico per tutte le schede: il messaggio dice dove guardare
  const submitHint = document.querySelector(".AvacySubmitHint");
  const submitHintLink = document.querySelector(".AvacySubmitHint a");
  submitHintLink &&
    submitHintLink.addEventListener("click", (event) => {
      event.preventDefault();
      tab_group && tab_group.show("consent-archive");
    });

  // Gia' al caricamento: chi aggiorna vede subito i form da completare
  validateRows();
}
