#!/usr/bin/env node
/*
 * check-links.mjs - offline link checker for the static site.
 *
 * For every *.html page under site/ (tools/ excluded) it verifies that:
 *   - every relative href/src/xlink:href resolves to an existing FILE
 *     (a directory is an error: file:// does not serve index.html),
 *   - every #fragment (same page or another page) matches an element id,
 *   - ids are unique within a page,
 *   - aria-labelledby / aria-describedby / aria-controls / for point at existing ids,
 *   - every <img> has an alt attribute.
 * Absolute URLs (http:, https:, mailto:, ...) are listed as warnings, because
 * the site must work offline; they are not followed.
 *
 * Usage:  node site/tools/check-links.mjs          (exit code 1 on any error)
 * No dependencies; Node 18+.
 */
import { readFileSync, readdirSync, statSync, existsSync } from "node:fs";
import { join, relative, dirname, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

const siteDir = dirname(dirname(fileURLToPath(import.meta.url)));

function listPages(dir) {
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

const decodeEntities = (s) =>
  s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&");

const stripComments = (html) => html.replace(/<!--[\s\S]*?-->/g, "");

const idCache = new Map();
function idsOf(file) {
  if (!idCache.has(file)) {
    const html = stripComments(readFileSync(file, "utf8"));
    const ids = [];
    for (const m of html.matchAll(/\s(?:id|name)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
      ids.push(decodeEntities(m[1] ?? m[2]));
    }
    idCache.set(file, ids);
  }
  return idCache.get(file);
}

const rel = (f) => relative(siteDir, f).split(sep).join("/");
const errors = [];
const warnings = [];
let refCount = 0;

for (const page of listPages(siteDir)) {
  const html = stripComments(readFileSync(page, "utf8"));
  const pageRel = rel(page);
  const err = (msg) => errors.push(`${pageRel}: ${msg}`);

  // Unique ids (only real id attributes).
  const seen = new Map();
  for (const m of html.matchAll(/\sid\s*=\s*"([^"]*)"/g)) {
    seen.set(m[1], (seen.get(m[1]) || 0) + 1);
  }
  for (const [id, n] of seen) if (n > 1) err(`duplicate id "${id}" (${n}x)`);
  const pageIds = new Set(idsOf(page));

  // ARIA / label references.
  for (const m of html.matchAll(/\s(aria-labelledby|aria-describedby|aria-controls|for)\s*=\s*"([^"]*)"/g)) {
    for (const id of m[2].split(/\s+/).filter(Boolean)) {
      if (!pageIds.has(id)) err(`${m[1]}="${id}" has no matching id`);
    }
  }

  // <img> alt.
  for (const m of html.matchAll(/<img\b[^>]*>/g)) {
    if (!/\salt\s*=/.test(m[0])) err(`<img> without alt: ${m[0].slice(0, 80)}`);
  }

  // Links and sources.
  for (const m of html.matchAll(/\s(href|src|xlink:href)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
    const raw = decodeEntities(m[2] ?? m[3]);
    refCount++;
    if (raw === "") { err(`empty ${m[1]}`); continue; }
    if (/^[a-z][a-z0-9+.-]*:/i.test(raw)) { warnings.push(`${pageRel}: absolute URL ${raw}`); continue; }
    if (raw.startsWith("//")) { warnings.push(`${pageRel}: protocol-relative URL ${raw}`); continue; }
    if (raw.startsWith("/")) { err(`root-relative URL (breaks under file://): ${raw}`); continue; }

    const hashAt = raw.indexOf("#");
    const pathPart = (hashAt >= 0 ? raw.slice(0, hashAt) : raw).split("?")[0];
    const frag = hashAt >= 0 ? raw.slice(hashAt + 1) : "";
    let target = page;
    if (pathPart !== "") {
      let decoded;
      try { decoded = decodeURIComponent(pathPart); } catch { err(`bad URL encoding: ${raw}`); continue; }
      target = resolve(dirname(page), decoded);
      if (!existsSync(target)) { err(`missing target: ${raw}`); continue; }
      if (statSync(target).isDirectory()) { err(`points at a directory (no index under file://): ${raw}`); continue; }
    }
    if (frag !== "") {
      if (!target.endsWith(".html")) { warnings.push(`${pageRel}: fragment on non-HTML target not checked: ${raw}`); continue; }
      const fragDecoded = decodeURIComponent(frag);
      if (!idsOf(target).includes(fragDecoded)) err(`missing anchor #${fragDecoded} in ${rel(target)} (from ${raw})`);
    }
  }
}

const pages = listPages(siteDir).length;
for (const w of warnings) console.log(`warning: ${w}`);
for (const e of errors) console.log(`ERROR: ${e}`);
console.log(`checked ${pages} pages, ${refCount} references: ${errors.length} error(s), ${warnings.length} warning(s)`);
process.exit(errors.length ? 1 : 0);
