/*
 * chrome.mjs - shared page chrome (skip link + header, navigation panel, footer).
 *
 * The navigation is defined once, in site/tools/nav.json. applyChrome() writes it
 * into the marked blocks of a page:
 *
 *   <!-- chrome:header -->  ...  <!-- /chrome:header -->
 *   <!-- chrome:nav -->     ...  <!-- /chrome:nav -->
 *   <!-- chrome:footer -->  ...  <!-- /chrome:footer -->
 *
 * Used by sync-chrome.mjs (hand-written pages) and build-api.mjs (generated pages),
 * so every page carries byte-identical chrome. No dependencies; Node 18+.
 */
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

export const toolsDir = dirname(fileURLToPath(import.meta.url));
export const siteDir = dirname(toolsDir);
export const nav = JSON.parse(readFileSync(join(toolsDir, "nav.json"), "utf8"));

/** All *.html pages under site/ (tools/ excluded), sorted, absolute paths. */
export function listPages(dir = siteDir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) {
      if (name === "tools" || name === "node_modules" || name.startsWith(".")) continue;
      out.push(...listPages(full));
    } else if (name.endsWith(".html")) {
      out.push(full);
    }
  }
  return out.sort();
}

export const esc = (s) =>
  String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

/** "../" prefix for a page path relative to site/, e.g. "api/auth.html" -> "../". */
export const prefixFor = (rel) => "../".repeat(rel.split("/").length - 1);

function header(prefix) {
  const b = nav.brand;
  return [
    `<a class="skip-link" href="#main">Skip to content</a>`,
    `<header class="site-header">`,
    `  <div class="site-header-inner">`,
    `    <a class="brand" href="${prefix}index.html">`,
    `      <img src="${prefix}${b.icon}" alt="" width="48" height="48">`,
    `      <span class="brand-text">`,
    `        <span class="brand-name">${b.name}</span>`,
    `        <span class="brand-sub">${esc(b.sub)}</span>`,
    `      </span>`,
    `    </a>`,
    `    <button type="button" class="nav-toggle" id="nav-toggle" aria-controls="site-nav" aria-expanded="false"><span class="nav-toggle-bars" aria-hidden="true"></span> Menu</button>`,
    `  </div>`,
    `</header>`,
  ].join("\n");
}

/*
 * aria-current: "page" on the link to the current page; "true" on a section's
 * index link when the current page lives in that section's folder
 * (e.g. api/auth.html marks "API reference" = api/index.html).
 */
function currentAttr(href, rel) {
  if (href === rel) return ` aria-current="page"`;
  if (href.endsWith("/index.html")) {
    const dir = href.slice(0, -"index.html".length);
    if (rel.startsWith(dir)) return ` aria-current="true"`;
  }
  return "";
}

function navBlock(prefix, rel) {
  const lines = [
    `<nav id="site-nav" class="site-nav" aria-label="Site">`,
    `  <p class="panel-caption">Chomik<b>Box</b></p>`,
  ];
  nav.groups.forEach((group, gi) => {
    const gid = `nav-group-${gi + 1}`;
    lines.push(`  <p class="nav-group" id="${gid}">${esc(group.label)}</p>`);
    lines.push(`  <ul aria-labelledby="${gid}">`);
    for (const page of group.pages) {
      const status = page.status ? ` <span class="nav-status">${esc(page.status)}</span>` : "";
      lines.push(`    <li><a href="${prefix}${page.href}"${currentAttr(page.href, rel)}>${esc(page.label)}${status}</a></li>`);
    }
    lines.push(`  </ul>`);
  });
  lines.push(`</nav>`);
  return lines.join("\n");
}

function footer(prefix) {
  const f = nav.footer;
  return [
    `<footer class="site-footer">`,
    `  <div class="site-footer-inner">`,
    `    <p>${esc(f.text)} Last updated <time datetime="${f.date}">${f.date}</time>.</p>`,
    `    <ul class="footer-links">`,
    `      <li><a href="${prefix}index.html">Overview</a></li>`,
    `      <li><a href="${prefix}index.html#sources">Sources and conventions</a></li>`,
    `      <li><a href="#top">Back to top</a></li>`,
    `    </ul>`,
    `  </div>`,
    `</footer>`,
  ].join("\n");
}

function replaceBlock(html, name, content, rel) {
  const re = new RegExp(`(<!-- chrome:${name} -->)[\\s\\S]*?(<!-- /chrome:${name} -->)`);
  if (!re.test(html)) {
    throw new Error(`${rel}: missing <!-- chrome:${name} --> ... <!-- /chrome:${name} --> markers`);
  }
  return html.replace(re, () => `<!-- chrome:${name} -->\n${content}\n<!-- /chrome:${name} -->`);
}

/** Returns html with the three chrome blocks filled in for the page at `rel` (path relative to site/). */
export function applyChrome(html, rel) {
  const prefix = prefixFor(rel);
  let out = html;
  out = replaceBlock(out, "header", header(prefix), rel);
  out = replaceBlock(out, "nav", navBlock(prefix, rel), rel);
  out = replaceBlock(out, "footer", footer(prefix), rel);
  return out;
}
