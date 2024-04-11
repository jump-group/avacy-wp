Promise.all([
  customElements.whenDefined("sl-checkbox"),
  customElements.whenDefined("sl-tab-group"),
  customElements.whenDefined("sl-tab"),
  customElements.whenDefined("sl-button"),
  customElements.whenDefined("sl-input"),
]).then(() => {
  init();
});

function init() {
  const form = document.querySelector("form#avacy-form");
  const edit_button = document.querySelector(".Edit");
  const submit_button = document.querySelector(".Submit");
  const checkboxes = document.querySelectorAll("sl-checkbox[name^=avacy_]");
  const tab_group = document.querySelector("sl-tab-group");
  const first_tab = document.querySelector("sl-tab-group sl-tab:first-child");
  const loader = document.querySelector(".AvacyLoader");

  // loader.classList.add("hidden");
  // form.classList.remove("hidden");

  if (tab_group && first_tab) {
    const active_tab = sessionStorage.getItem("active_tab");
    tab_group.show(active_tab);
  }

  edit_button &&
    edit_button.addEventListener("click", () => {
      const url = new URL(window.location.href);
      // add edit query parameter
      url.searchParams.set("edit", "true");
      window.location
        .replace(url.toString())
        .then(() => console.log("Redirected to edit mode"));
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

  checkboxes &&
    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("sl-change", (event) => {
        form.submit();
      });
    });

  tab_group &&
    tab_group.addEventListener("sl-tab-show", (event) => {
      console.log("Tab shown", event.detail.name);
      sessionStorage.setItem("active_tab", event.detail.name);
    });
}
