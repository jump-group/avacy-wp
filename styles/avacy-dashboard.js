window.addEventListener("DOMContentLoaded", function () {
  setTimeout(() => {
    init();
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
        let closestTr = element.closest("tr");
        const select = closestTr.querySelector("sl-select");

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

  validateRows = () => {
    const tbody = document.querySelector(".AvacyForms tbody");

    if (!tbody) return;
    const rows = tbody.querySelectorAll("tr");

    if (!rows) return;

    let countNotValidRows = 0;

    rows.forEach((row) => {
      const checkboxes = row.querySelectorAll("sl-details sl-checkbox");
      const save_switch = row.querySelector("sl-switch");
      const select = row.querySelector("sl-select");

      let checked = Array.from(checkboxes).some((checkbox) => checkbox.checked);

      if (!checked && !save_switch.checked) {
        row.classList.remove("warning");
        validRows = true;

        select.removeAttribute("required");
      }

      if (!checked && save_switch.checked) {
        row.classList.add("warning");
        validRows = false;

        select.setAttribute("required", "");
        countNotValidRows++;
      }

      if (checked && !save_switch.checked) {
        row.classList.remove("warning");
        validRows = true;

        select.removeAttribute("required");
      }

      if (checked && save_switch.checked) {
        row.classList.remove("warning");
        validRows = true;

        select.setAttribute("required", "");
      }

      disableSubmit = !validRows;
    });

    if (countNotValidRows > 0) {
      disableSubmit = true;
    }
  };
}
