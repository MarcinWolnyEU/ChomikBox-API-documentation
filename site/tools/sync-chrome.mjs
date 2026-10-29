#!/usr/bin/env node
/*
 * sync-chrome.mjs - keeps the shared page chrome (skip link + header, navigation
 * panel, footer) identical on every page. The navigation is defined once, in
 * site/tools/nav.json; the markup lives in chrome.mjs (also used by build-api.mjs).
 *
 * Usage (from anywhere):
 *   node site/tools/sync-chrome.mjs           rewrite the marked blocks in place
 *   node site/tools/sync-chrome.mjs --check   exit 1 if any page is out of date
 *
 * No dependencies; Node 18+.
 */
import { readFileSync, writeFileSync } from "node:fs";
import { relative, sep } from "node:path";
import { siteDir, listPages, applyChrome } from "./chrome.mjs";

const checkOnly = process.argv.includes("--check");

let stale = 0;
for (const file of listPages()) {
  const rel = relative(siteDir, file).split(sep).join("/");
  const before = readFileSync(file, "utf8");
  const after = applyChrome(before, rel);
  if (after !== before) {
    stale++;
    if (checkOnly) {
      console.log(`out of date: ${rel}`);
    } else {
      writeFileSync(file, after, "utf8");
      console.log(`updated: ${rel}`);
    }
  }
}
if (checkOnly) {
  console.log(stale ? `${stale} page(s) out of date - run without --check` : "chrome up to date on all pages");
  process.exit(stale ? 1 : 0);
} else {
  console.log(stale ? `${stale} page(s) rewritten` : "nothing to do");
}
