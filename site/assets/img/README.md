# Images used by the site

All 40 PNGs in this folder are original artwork made for this site: one hamster character (a grey-and-white agouti hamster with a deliberately awkward, sideways-glancing face), rendered with an image model and then trimmed and resized. None of it comes from the ChomikBox skins or animation packages. The master image and the character reference live outside the site, in the git-ignored `.tmp/hamster/`.

Cut-out images have their transparent margins trimmed (the welcome card is padded to an exact 465×465 square). Most are stored at twice their display size so they stay sharp on high-density screens; the `width` and `height` attributes in the pages give the display size.

Every file in this folder is referenced by at least one page. When you add an image, put it here and map it below; when you remove its last use, delete the file.

## Site-wide

| File | Depicts | Used on |
|---|---|---|
| `app-icon.png` | The hamster climbing out of a cardboard box (96 px, shown at 48 px) | every page (header, favicon), title bars of `components.html#msi`, `security.html#client` |
| `hamster-logo.png` | The hamster's head, facing right (64×56, shown at 32×28) | the title bar of every section except the security page |
| `msgbox-information.png` | Hamster beside a yellow information badge | "Note" callouts, title bars |
| `msgbox-warning.png` | Hamster beside an amber warning triangle | "Caveat" callouts, security title bars, API "Security notes" |
| `msgbox-question.png` | Hamster with a thought bubble and "?" | "Unknown / open points" callouts |
| `msgbox-critical.png` | Hamster beside a red badge with a white cross | crash reporting and fault sections |

## Security page title bars

On `security.html` the title-bar logo of every window follows the rating of the finding in it. Windows without a rating (the page title, Client, Service behaviour, Handling) get the terrified default.

| File | Depicts | Used for |
|---|---|---|
| `rating-default.png` | Terrified hamster, paws on its cheeks, sweat drops flying | windows without a rating |
| `rating-high.png` | Cartoon skeleton of the hamster | High |
| `rating-medium.png` | Decaying zombie hamster | Medium |
| `rating-low-medium.png` | Very sick hamster: thermometer, blisters (bullae) and pustules, damp cloth | Low-Medium |
| `rating-low.png` | Hamster in mid-sneeze with a tissue | Low |
| `rating-info.png` | Hamster peeking over the blue padlock shield from `tile-security.png` | Info |

## Page figures

| File | Depicts | Used on |
|---|---|---|
| `protocol-soap.png` | A sad hamster with a tear, looking at four old, crumbling stone letters spelling SOAP | `protocol.html` (first window) |
| `testing-hamster.png` | The tester from `tile-testing.png` at a larger size: clipboard of check marks, magnifying glass, ladybug | `testing.html` (first window) |

## Section logos (title bars)

| File | Depicts | Used on |
|---|---|---|
| `settings-logo.png` | Hamster head with a gear | architecture, components, configuration |
| `uploader-logo.png` | Hamster head with an orange up-arrow | configuration, flows, windows integration |
| `downloader-logo.png` | Hamster head with a cyan down-arrow | components, configuration, flows, windows integration |
| `player-logo.png` | Hamster head wearing headphones | architecture, flows, windows integration |

## `index.html`

| File | Depicts | Used for |
|---|---|---|
| `welcome-card.png` | The hamster on a cloud with music, photo, video and archive icons around it (465×465, transparent background, shown at its natural size) | the figure at the top of the overview |
| `feature-upload.png` | Hamster in profile looking up, holding a document toward an orange up-arrow | Features: Upload; also `components.html#skins` and `configuration.html#uploader` |
| `feature-download.png` | Hamster looking down at a document under a cyan down-arrow | Features: Download |
| `feature-music.png` | Hamster in headphones, swaying, with musical notes | Features: Music player; `components.html#anim` |
| `feature-folders.png` | Hamster looking back over its shoulder, carrying a yellow folder and a grey one | Features: Folders |
| `feature-screenshots.png` | Hamster pointing a camera at the viewer | Features: Screenshots; `components.html#anim` |
| `feature-click-catcher.png` | Hamster lunging with a butterfly net after a mouse pointer | Features: Browser click-catching; title bar of `flows.html#click-catching` |
| `feature-companion.png` | Hamster leaning over the top of a monitor | Features: Desktop companion |
| `tile-architecture.png` | Hamster in a hard hat stacking building blocks | Contents tile: Architecture |
| `tile-components.png` | Hamster holding a jigsaw piece | Contents tile: Components |
| `tile-windows-integration.png` | Hamster looking out of an open window | Contents tile: Windows integration |
| `tile-configuration.png` | Hamster tightening a gear with a wrench | Contents tile: Configuration |
| `tile-security.png` | Hamster behind a shield with a padlock | Contents tile: Security & privacy |
| `tile-protocol.png` | Hamster looking back, holding out a sealed envelope | Contents tile: Protocol |
| `tile-flows.png` | Hamster pointing at a three-box flowchart | Contents tile: Flows |
| `tile-api-reference.png` | Hamster reading a big open book | Contents tile: API reference |
| `tile-testing.png` | Hamster with a magnifying glass, a clipboard of check marks and a ladybug | Contents tile: PHP client & tests |

## `components.html#anim`

Illustrations of the desktop hamster's animation states (not frames from the packages).

| File | Depicts | State |
|---|---|---|
| `anim-idle.png` | The hamster standing | idle |
| `anim-reading-newspaper.png` | Reading a newspaper in profile | idle: reading the paper |
| `anim-drag-file.png` | Looking up, one paw raised toward a hovering document | file dragged over |
| `anim-drop-file.png` | Looking down at a small USB stick | file dropped |
| `anim-typing.png` | Writing on a notepad with a pencil, in profile | typing |

The music and screenshot states reuse `feature-music.png` and `feature-screenshots.png`.
