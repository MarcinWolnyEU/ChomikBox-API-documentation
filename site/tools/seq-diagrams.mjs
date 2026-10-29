#!/usr/bin/env node
/*
 * seq-diagrams.mjs - renders the inline-SVG sequence diagrams of flows.html.
 *
 * Each diagram is defined below as data (actors + steps). The script lays it out
 * (evenly spaced lifelines, label rows above arrows, frames for loops/options,
 * notes) using the .dg classes of assets/css/site.css, and writes the result into
 * the marked block of the page:
 *
 *   <!-- seq:<id> --> ... <!-- /seq:<id> -->
 *
 * Deterministic and re-runnable. Labels may cross dashed lifelines (they carry a
 * halo); they are shifted to stay inside the canvas, and a label that cannot fit at
 * all is reported as a warning. Text is escaped.
 *
 * Usage:
 *   node site/tools/seq-diagrams.mjs           rewrite the blocks
 *   node site/tools/seq-diagrams.mjs --check   exit 1 if a block is out of date
 *
 * Step types:
 *   { from, to, label, sub, reply }       message (reply = dashed)
 *   { self, label, sub }                  call on one lifeline
 *   { note: [lines], over: [a, b] }       note box spanning lifelines a..b
 *   { frame: "loop" | "opt" | "alt", label, steps: [...] }
 */
import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { siteDir, esc } from "./chrome.mjs";

const PAGE = join(siteDir, "flows.html");
const W = 1000;
const MARGIN = 120;
const CH_MSG = 7.3; // average glyph width at 14px (Tahoma/Segoe UI)
const CH_SUB = 6.3; // at 12px

const DIAGRAMS = [
  {
    id: "session",
    title: "Login and session keep-alive",
    desc: "The application triggers login; NetProtoBox sends Auth and receives a token, then sends CheckEvents and Folders with that token. CheckEvents repeats on a timer. A BadToken status triggers one relogin per message. Logout ends only this token.",
    actors: [
      { id: "app", label: "Application, UI", sub: "signals and slots", kind: "local" },
      { id: "np", label: "NetProtoBox", sub: "+ NetModule message pool", kind: "local" },
      { id: "box", label: "box.chomikuj.pl", sub: "ChomikBoxService.svc", kind: "server" },
    ],
    steps: [
      { from: "app", to: "np", label: "login()", sub: "start-up, after resume, relogin" },
      { from: "np", to: "box", label: "Auth(name, passHash, ver=4, client)" },
      { from: "box", to: "np", label: "status=Ok, hamsterId, name, token", reply: true },
      { from: "np", to: "app", label: "logged", reply: true },
      { from: "np", to: "box", label: "CheckEvents(token, stats{…})" },
      { from: "np", to: "box", label: "Folders(token, hamsterId, folderId=0, depth=0)" },
      { from: "box", to: "np", label: "folder tree, folderModifyTime", reply: true },
      { from: "np", to: "app", label: "foldersAvailable", reply: true },
      {
        frame: "loop",
        label: "shortest active period: 300 s idle, 60 s uploading, 15 s folder view",
        steps: [
          { from: "np", to: "box", label: "CheckEvents(token, stats{…})" },
          { from: "box", to: "np", label: "accountBalance, settings, changes{folderModifyTime}", reply: true },
          { self: "np", label: "newer folderModifyTime: Folders(0, 0) again" },
        ],
      },
      { from: "box", to: "np", label: "any reply: status=BadToken", reply: true },
      { self: "np", label: "relogin: Auth again, once per message", sub: "kept messages resent with the new token; session-bound ones dropped" },
      { from: "app", to: "np", label: "logout()", sub: "quit or system suspend" },
      { from: "np", to: "box", label: "Logout(token)" },
      { from: "box", to: "np", label: "status=Ok; this token now gets BadToken, others stay valid", reply: true },
    ],
  },
  {
    id: "folders",
    title: "Folder browsing and management",
    desc: "The folder window asks NetProtoBox for the tree with Folders, then creates, renames, moves, modifies and removes folders with the matching operations; the server's replies and two unexpected behaviours are noted.",
    actors: [
      { id: "ui", label: "Folder window", sub: "FolderModel", kind: "local" },
      { id: "np", label: "NetProtoBox", sub: "builders and handlers", kind: "local" },
      { id: "box", label: "box.chomikuj.pl", sub: "ChomikBoxService.svc", kind: "server" },
    ],
    steps: [
      { from: "ui", to: "np", label: "show or refresh a folder" },
      { from: "np", to: "box", label: "Folders(token, hamsterId, folderId, depth)", sub: "depth 0 = whole subtree, 1 = node only, 2 = node and children" },
      { from: "box", to: "np", label: "FolderInfo tree, folderModifyTime", sub: "unknown folderId: the root, status Ok (S-05)", reply: true },
      { from: "ui", to: "np", label: "new folder" },
      { from: "np", to: "box", label: "AddFolder(token, newFolderId = parent, name)" },
      { from: "box", to: "np", label: "folderId, or Error: NameExistsAtDestination, InvalidCharacters, …", reply: true },
      { from: "ui", to: "np", label: "rename, drag to move, folder options" },
      { from: "np", to: "box", label: "RenameFolder / MoveFolder / ModifyFolder" },
      { from: "box", to: "np", label: "status; ModifyFolder also folderDetails and modify times", reply: true },
      { note: ["MoveFolder into itself: fault, yet moved to the root (S-03).", "MoveFolder to an unknown parent: moved to the root, Ok (S-05)."], over: ["np", "box"] },
      { from: "ui", to: "np", label: "remove" },
      { from: "np", to: "box", label: "RemoveFolder(token, folderId, force)" },
      { from: "box", to: "np", label: "Ok, or NotEmptyOrNotExistsFolder if it holds files", sub: "force=false still deletes a folder that holds only subfolders (S-04)", reply: true },
      { from: "np", to: "ui", label: "folderRemoved / folderMoved / folder updated", reply: true },
    ],
  },
  {
    id: "upload",
    title: "Upload with resume",
    desc: "The uploader gets a ticket with UploadToken, posts the file as a non-standard multipart body to the upload server named in the ticket, and after a network error asks the resume check how much was stored before posting the rest.",
    actors: [
      { id: "up", label: "Uploader window", sub: "upload queue", kind: "local" },
      { id: "np", label: "NetProtoBox", sub: "DirectNetworkReply", kind: "local" },
      { id: "box", label: "box.chomikuj.pl", sub: "ChomikBoxService.svc", kind: "server" },
      { id: "srv", label: "upload server", sub: "sNNNN.chomikuj.pl:8084", kind: "server" },
    ],
    steps: [
      { from: "up", to: "np", label: "file queued (folderId, fileName)" },
      { from: "np", to: "box", label: "UploadToken(token, folderId, fileName)" },
      { from: "box", to: "np", label: "key, stamp, server, locale", reply: true },
      { from: "np", to: "srv", label: "POST /file/  multipart/mixed; boundary=!CHB<stamp>", sub: "chomik_id, folder_id, key, time, [thumbnails], client, locale, file" },
      { from: "srv", to: "np", label: "<resp res=\"1\" fileid=\"…\"/>", sub: "forged key: res=\"-42\" errorMessage=\"Bad token\"", reply: true },
      { from: "np", to: "up", label: "uploaded: fileid", reply: true },
      {
        frame: "opt",
        label: "after a network error",
        steps: [
          { from: "np", to: "srv", label: "GET /resume/check/?key=<key>" },
          { from: "srv", to: "np", label: "<resp res=\"1\" file_size=\"N\" skipThumbnails=\"true\"/>", sub: "nothing stored for this key: res=\"-31\"", reply: true },
          { from: "np", to: "srv", label: "POST /file/ with resume_from = N", sub: "the file part carries only the remaining bytes" },
          { from: "srv", to: "np", label: "<resp res=\"1\" fileid=\"…\"/>", reply: true },
        ],
      },
      { note: ["Error reply or unknown key: start again with a new UploadToken.", "Retries: 5 quick, up to 17 in total, 15 s apart."], over: ["np", "srv"] },
    ],
  },
  {
    id: "download",
    title: "Download with agreements",
    desc: "The downloader asks Download for the files, receives the agreement offers without a URL (own, small or transfer, one per file), shows the cost, sends the accepted agreement and gets a signed URL, which it fetches from the file server. An expired URL is renewed with RefreshFileUrl.",
    actors: [
      { id: "dl", label: "Downloader window", sub: "agreement window", kind: "local" },
      { id: "np", label: "NetProtoBox", sub: "NetFile", kind: "local" },
      { id: "box", label: "box.chomikuj.pl", sub: "ChomikBoxService.svc", kind: "server" },
      { id: "fs", label: "file server", sub: "sNNNN.chomikuj.pl", kind: "server" },
    ],
    steps: [
      { from: "dl", to: "np", label: "add files: ids, paths or links" },
      { from: "np", to: "box", label: "Download(token, [sequence], disposition=download, list{id})", sub: "up to 2000 entries per part; non-final parts get an empty Ok" },
      { from: "box", to: "np", label: "files with agreementInfo offers, url = nil; accountBalance", sub: "own and small: cost 0; transfer: cost in kB (foreign file over 1 MB)", reply: true },
      { from: "np", to: "dl", label: "show cost per file", reply: true },
      { self: "dl", label: "user accepts", sub: "free offers may auto-accept; a cost above 0 always asks" },
      { from: "dl", to: "np", label: "accepted agreements" },
      { from: "np", to: "box", label: "Download(…, DownloadReqEntry{id, agreementInfo{name, [cost]}})", sub: "in a sequence only the final message's agreements count" },
      { from: "box", to: "np", label: "signed url: File.aspx?e=…&pv=…", reply: true },
      { from: "np", to: "fs", label: "GET /File.aspx?e=…&pv=…  Range: bytes=N-" },
      { from: "fs", to: "np", label: "200 or 206, Content-Disposition: attachment", reply: true },
      {
        frame: "opt",
        label: "link expired",
        steps: [
          { from: "np", to: "box", label: "RefreshFileUrl(token, id, realId, disposition=download)" },
          { from: "box", to: "np", label: "id, realId, url, expire (nil live)", sub: "the client parses only yyyy-MM-dd HH:mm:ss", reply: true },
          { from: "np", to: "fs", label: "GET the new url" },
        ],
      },
    ],
  },
  {
    id: "music",
    title: "Music player",
    desc: "The player lists audio files with FolderFiles and the player.audio disposition, gets a signed URL from Download with the same disposition, and streams it with a GET that carries the player's headers.",
    actors: [
      { id: "pl", label: "Player window", sub: "PlaylistModel, PlayerCache", kind: "local" },
      { id: "np", label: "NetProtoBox", sub: "builders and handlers", kind: "local" },
      { id: "box", label: "box.chomikuj.pl", sub: "ChomikBoxService.svc", kind: "server" },
      { id: "fs", label: "file server", sub: "sNNNN.chomikuj.pl", kind: "server" },
    ],
    steps: [
      { from: "pl", to: "np", label: "add music from chomikuj.pl" },
      { from: "np", to: "box", label: "FolderFiles(token, hamsterId, folderId, disposition=player.audio)" },
      { from: "box", to: "np", label: "entryList{EntryInfo{id, name, type, size}}: audio files only", sub: "disposition=download here gives WrongDisposition", reply: true },
      { from: "pl", to: "np", label: "play" },
      { from: "np", to: "box", label: "Download(token, disposition=player.audio, list{id, [agreement]})", sub: "like a download: offer first, then a url with the accepted agreement" },
      { from: "box", to: "np", label: "signed url", reply: true },
      { from: "np", to: "fs", label: "GET url  Icy-MetaData: 1, Accept-Encoding: (one space)" },
      { from: "fs", to: "np", label: "200 audio/mpeg", sub: "accepted only with a 2xx, Content-Length > 0 and no x-nofile header", reply: true },
      { from: "np", to: "pl", label: "stream", reply: true },
      { self: "pl", label: "decode (GStreamer 0.10), cache in cache\\player\\" },
    ],
  },
  {
    id: "playlist",
    title: "SavePlaylist",
    desc: "The player sends SavePlaylist with a name and the list of files. The server creates a folder named after the playlist inside a top-level folder Playlisty, reusing or creating Playlisty, and copies the files into it under new file ids. A duplicate playlist name becomes name(1).",
    actors: [
      { id: "pl", label: "Player window", sub: "save to the account", kind: "local" },
      { id: "np", label: "NetProtoBox", sub: "builders and handlers", kind: "local" },
      { id: "box", label: "box.chomikuj.pl", sub: "ChomikBoxService.svc", kind: "server" },
      { id: "tree", label: "account folders", sub: "server side", kind: "server" },
    ],
    steps: [
      { from: "pl", to: "np", label: "save playlist as <name>" },
      { from: "np", to: "box", label: "SavePlaylist(token, name, list{PlayListItem{id, realId=-1}})" },
      { from: "box", to: "tree", label: "create Playlisty at the root", sub: "only if it does not exist; otherwise it is reused" },
      { from: "box", to: "tree", label: "create folder <name> inside Playlisty", sub: "a duplicate name becomes <name>(1), status Ok" },
      { from: "box", to: "tree", label: "copy each file (new file ids)" },
      { from: "box", to: "np", label: "folderId, folderName, parentId", reply: true },
      { from: "np", to: "pl", label: "playlist folder created", reply: true },
      { note: ["The new folder carries a server-generated password", "while passwd is false (S-02)."], over: ["box", "tree"] },
    ],
  },
  {
    id: "click-catching",
    title: "Browser click-catching",
    desc: "When a browser would download a file of a claimed MIME type, the NPAPI plug-in takes the stream URL, checks the ChomikBox2 mutex and either writes -d plus the URL into the running instance's named pipe or cold-starts ChomikBox with ShellExecute. The application turns the URL into a download.",
    actors: [
      { id: "br", label: "Web browser", sub: "NPAPI host", kind: "user" },
      { id: "pg", label: "npcbplugin.dll", sub: "worker thread", kind: "local" },
      { id: "app", label: "chomikbox.exe", sub: "executeRemoteCommand", kind: "local" },
      { id: "box", label: "box.chomikuj.pl", sub: "ChomikBoxService.svc", kind: "server" },
    ],
    steps: [
      { from: "br", to: "pg", label: "NPP_NewStream: a claimed download MIME type" },
      { self: "pg", label: "copy the stream URL, start a worker thread" },
      { self: "pg", label: "CreateMutexA(\"ChomikBox2\"): already exists?" },
      {
        frame: "alt",
        label: "yes: ChomikBox is running",
        steps: [{ from: "pg", to: "app", label: "write to \\\\.\\pipe\\qtsingleapp-Chomik-739b-<sid>", sub: "QDataStream frame: length, then -d + URL + NUL" }],
      },
      {
        frame: "alt",
        label: "no: cold start",
        steps: [{ from: "pg", to: "app", label: "ShellExecute(exe from HKLM\\Software\\ChomikBox, \"-d<url>\")", sub: "fails on a plain MSI install: that registry value is missing" }],
      },
      { self: "app", label: "-d<url>: the link becomes a download", sub: "Image.aspx / ShowVideo.aspx links: chomik://files/…" },
      { from: "app", to: "box", label: "Download(…), agreement window, GET (Download flow)" },
      { note: ["Current browsers no longer load NPAPI plug-ins:", "this path is historical (C-12)."], over: ["br", "pg"] },
    ],
  },
];

/* ---------------------------------------------------------------- layout */

const warnings = [];

function render(d) {
  const n = d.actors.length;
  const gap = (W - 2 * MARGIN) / (n - 1);
  const X = Object.fromEntries(d.actors.map((a, i) => [a.id, MARGIN + i * gap]));
  const boxW = Math.min(gap - 24, 210);
  const parts = [];
  const marker = `ah-${d.id}`;
  let y = 82;

  const text = (x, yy, cls, s, anchor) =>
    `<text class="${cls}" x="${Math.round(x)}" y="${Math.round(yy)}"${anchor ? ` text-anchor="${anchor}"` : ""}>${esc(s)}</text>`;
  const check = (s, ch, room, where) => {
    if (s.length * ch > room) warnings.push(`${d.id}: "${s}" (~${Math.round(s.length * ch)}px) wider than ${Math.round(room)}px ${where}`);
  };
  /** Start x for a left-aligned label so that it stays inside the canvas. */
  let rightmost = 0; // right end of the widest label rendered so far (for frames)
  const fitX = (x, s, ch) => {
    const w = s.length * ch;
    check(s, ch, W - 16, "(canvas)");
    const start = x + w > W - 8 ? Math.max(8, W - 8 - w) : x;
    rightmost = Math.max(rightmost, start + w);
    return start;
  };
  const actorsOf = (steps) => {
    const ids = [];
    for (const s of steps) {
      if (s.from) ids.push(s.from, s.to);
      if (s.self) ids.push(s.self);
      if (s.over) ids.push(...s.over);
      if (s.steps) ids.push(...actorsOf(s.steps));
    }
    return ids;
  };

  function steps(list) {
    for (const s of list) {
      if (s.from) {
        const x1 = X[s.from];
        const x2 = X[s.to];
        const left = Math.min(x1, x2);
        const span = Math.abs(x2 - x1);
        void span;
        parts.push(text(fitX(left + 10, s.label, CH_MSG), y + 12, "msg halo", s.label));
        let ly = y + 12;
        if (s.sub) {
          ly += 16;
          parts.push(text(fitX(left + 10, s.sub, CH_SUB), ly, "t-s halo", s.sub));
        }
        const ay = ly + 10;
        const tip = x2 > x1 ? x2 - 2 : x2 + 2;
        parts.push(`<path class="${s.reply ? "arrow-dash" : "arrow"}" d="M${Math.round(x1)},${Math.round(ay)} H${Math.round(tip)}" marker-end="url(#${marker})"/>`);
        y = ay + 18;
      } else if (s.self) {
        const x = X[s.self];
        const need = Math.max(s.label.length * CH_MSG, s.sub ? s.sub.length * CH_SUB : 0);
        const last = s.self === d.actors[n - 1].id || x + 42 + need > W - 8;
        const loopX = last ? x - 32 : x + 32;
        parts.push(`<path class="arrow" d="M${Math.round(x)},${Math.round(y + 4)} H${Math.round(loopX)} V${Math.round(y + 22)} H${Math.round(last ? x - 4 : x + 4)}" marker-end="url(#${marker})"/>`);
        const tx = last ? x - 42 : x + 42;
        const anchor = last ? "end" : null;
        parts.push(text(tx, y + 17, "msg halo", s.label, anchor));
        const room = last ? x - 42 - 10 : W - tx - 10;
        check(s.label, CH_MSG, room, "(self)");
        let ly = y + 17;
        if (s.sub) {
          ly += 16;
          parts.push(text(tx, ly, "t-s halo", s.sub, anchor));
          check(s.sub, CH_SUB, room, "(self sub)");
        }
        y = Math.max(y + 34, ly + 14);
      } else if (s.note) {
        const xs = s.over.map((id) => X[id]);
        const x1 = Math.min(...xs) - 70;
        const x2 = Math.max(...xs) + 70;
        const h = 14 + s.note.length * 17;
        parts.push(`<rect class="note" x="${Math.round(x1)}" y="${Math.round(y)}" width="${Math.round(x2 - x1)}" height="${h}" rx="4"/>`);
        s.note.forEach((line, i) => {
          parts.push(text(x1 + 10, y + 20 + i * 17, "t-s", line));
          check(line, CH_SUB, x2 - x1 - 20, "(note)");
        });
        y += h + 14;
      } else if (s.frame) {
        const ids = actorsOf(s.steps);
        const xs = ids.map((id) => X[id]);
        const x1 = Math.max(8, Math.min(...xs) - 60);
        let x2 = Math.min(W - 8, Math.max(...xs) + 60);
        const top = y;
        const idx = parts.length;
        y += 28;
        const outer = rightmost;
        rightmost = 0;
        steps(s.steps);
        x2 = Math.min(W - 4, Math.max(x2, rightmost + 12));
        rightmost = Math.max(outer, rightmost);
        const h = y - top + 2;
        parts.splice(
          idx,
          0,
          `<rect class="loop" x="${Math.round(x1)}" y="${Math.round(top)}" width="${Math.round(x2 - x1)}" height="${Math.round(h)}" rx="4"/>`,
          text(x1 + 10, top + 17, "t-s", `${s.frame}: ${s.label}`),
        );
        check(`${s.frame}: ${s.label}`, CH_SUB, x2 - x1 - 20, "(frame)");
        y += 14;
      }
    }
  }
  steps(d.steps);

  const height = y + 10;
  const head = [];
  for (const a of d.actors) {
    const x = X[a.id];
    const cls = a.kind === "server" ? "chip-srv" : a.kind === "user" ? "chip" : a.kind === "off" ? "chip-off" : "chip-green";
    head.push(`<rect class="${cls}" x="${Math.round(x - boxW / 2)}" y="14" width="${Math.round(boxW)}" height="48" rx="6"/>`);
    head.push(text(x, 34, "t-b", a.label, "middle"));
    if (a.sub) head.push(text(x, 52, "t-s", a.sub, "middle"));
    head.push(`<line class="lifeline" x1="${Math.round(x)}" y1="62" x2="${Math.round(x)}" y2="${Math.round(height - 6)}"/>`);
  }
  const svg = [
    `<svg class="dg dg-mid" viewBox="0 0 ${W} ${Math.round(height)}" role="img" aria-labelledby="dg-${d.id}-title dg-${d.id}-desc">`,
    `<title id="dg-${d.id}-title">${esc(d.title)}</title>`,
    `<desc id="dg-${d.id}-desc">${esc(d.desc)}</desc>`,
    `<defs><marker id="${marker}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#1d7373"/></marker></defs>`,
    ...head,
    ...parts,
    `</svg>`,
  ].join("\n");
  return `<section class="figure-scroll" aria-labelledby="dg-${d.id}-cap" tabindex="0">\n${svg}\n</section>`;
}

/* ------------------------------------------------------------------ main */

const checkOnly = process.argv.includes("--check");
const before = readFileSync(PAGE, "utf8");
let html = before;
for (const d of DIAGRAMS) {
  const re = new RegExp(`(<!-- seq:${d.id} -->)[\\s\\S]*?(<!-- /seq:${d.id} -->)`);
  if (!re.test(html)) throw new Error(`flows.html: missing <!-- seq:${d.id} --> markers`);
  const out = render(d);
  html = html.replace(re, () => `<!-- seq:${d.id} -->\n${out}\n<!-- /seq:${d.id} -->`);
}
for (const w of warnings) console.log(`note: ${w}`);
if (checkOnly) {
  const stale = html !== before;
  console.log(stale ? "flows.html diagrams out of date" : "flows.html diagrams up to date");
  process.exit(stale ? 1 : 0);
}
if (html !== before) writeFileSync(PAGE, html, "utf8");
console.log(`${DIAGRAMS.length} diagrams rendered${html !== before ? ", flows.html updated" : ", no change"}`);
