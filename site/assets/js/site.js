/*
 * ChomikBox technical overview - progressive enhancement only.
 * Without JavaScript every page is complete and the navigation panel is always
 * visible. With it, the panel collapses behind the "Menu" button on narrow screens.
 */
(function () {
  "use strict";
  document.documentElement.classList.add("js");

  function init() {
    var button = document.getElementById("nav-toggle");
    var nav = document.getElementById("site-nav");
    if (!button || !nav) {
      return;
    }
    button.addEventListener("click", function () {
      var open = button.getAttribute("aria-expanded") === "true";
      button.setAttribute("aria-expanded", open ? "false" : "true");
      nav.classList.toggle("is-open", !open);
      if (!open) {
        var current = nav.querySelector("a[aria-current]") || nav.querySelector("a");
        if (current) {
          current.focus();
        }
      }
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && nav.classList.contains("is-open")) {
        nav.classList.remove("is-open");
        button.setAttribute("aria-expanded", "false");
        button.focus();
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
