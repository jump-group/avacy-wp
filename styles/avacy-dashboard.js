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

  loader.classList.add("hidden");
  wrap.classList.remove("hide");

  edit_button &&
    edit_button.addEventListener("click", () => {
      console.log(EditAccountPanel, RenderAccountPanel);
      EditAccountPanel.classList.remove("hidden");
      RenderAccountPanel.classList.add("hidden");
      // GlobalSubmit remove disabled
      GlobalSubmit.removeAttribute("disabled");
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

  [checkboxes, switches, selects].forEach((elements) => {
    elements &&
      elements.forEach((element) => {
        element.addEventListener("sl-change", (event) => {
          GlobalSubmit.removeAttribute("disabled");
        });
      });
  });

  inputConsentSolutionToken &&
    inputConsentSolutionToken.addEventListener("input", (event) => {
      GlobalSubmit.removeAttribute("disabled");
    });

  tab_group &&
    tab_group.addEventListener("sl-tab-show", (event) => {
      console.log("Tab shown", event.detail.name);
      active_tab_input.value = event.detail.name;
    });
}
