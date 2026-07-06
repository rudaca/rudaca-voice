# Handoff: Rudaca Voice — Ideas & Business-Improvement Platform

## Overview
Rudaca Voice lets an organization collect improvement ideas from employees (operations, HR,
finance, IT, customer experience — not just software), vote and comment on them, have managers
triage/prioritize them through a review queue, track status changes, and optionally link approved
ideas to GitHub issues for development work. This bundle is a clickable prototype of the full
product across three role journeys: **Employee**, **Manager**, and **Admin/Owner**.

## About the Design Files
The files in this bundle are **design references authored in HTML** (a single streaming
"Design Component" prototype plus its tiny runtime). They demonstrate the intended look, layout,
copy, and interaction — **they are not production code to copy directly**.

Your task is to **recreate these designs in the real codebase** — the existing
**Laravel 11 + Livewire 3 + Flux UI + MySQL** app at `rudaca/rudaca-voice` — using that stack's
established patterns (Blade + Livewire components, Flux UI components, Eloquent models, Tailwind).
Treat the HTML as the spec for markup structure, spacing, and behavior; render it with Flux UI
components and your Tailwind theme rather than porting the inline styles verbatim.

### How to use this in VS Code / Claude Code
1. Pull the `rudaca/rudaca-voice` repo and open it in VS Code.
2. Drop this `design_handoff_rudaca_voice/` folder somewhere in the repo (e.g. `docs/design/`).
3. Open `Rudaca Voice.dc.html` in a browser to click through the prototype as you build.
4. Point Claude Code at this README, e.g.:
   > "Implement the Employee dashboard and Ideas list from `docs/design/design_handoff_rudaca_voice/README.md`
   > as Livewire components using Flux UI. Match the layout and behavior described. Use the existing
   > `Idea`, `IdeaBoard`, `IdeaVote` models."
5. Build screen by screen. Each screen below maps to one or more Livewire components.

## Fidelity
**High-fidelity.** Final colors, typography, spacing, states, and interactions are all specified
below. Recreate the UI faithfully, but express it through **Flux UI components + your Tailwind
config** — e.g. use `<flux:badge>` for status pills, `<flux:button>` for actions, `<flux:card>`,
`<flux:select>`, `<flux:modal>`, `<flux:navlist>` for the sidebar. The exact hex/measurement values
are given so you can align your Tailwind theme; prefer semantic Flux/Tailwind tokens where they
already match.

---

## Data Model (maps to your tables)
The prototype is shaped around these entities:

- **organizations** — the workspace (shown as "Northwind Group" in the sidebar).
- **users** + **organization_user** — members with a role per org: `employee` | `manager` | `admin`.
- **idea_boards** — a board (Atlas, App, Platform, Operations, Field Service, People & HR, Finance,
  Customer Experience). Fields used: `name`, `emoji`/icon, `color`, `description`.
- **Board groups** — a *new* two-level grouping above boards (Technology, Operations, Corporate,
  Customer). **Not in the original schema.** Add either a `board_groups` table
  (`id, organization_id, name, position`) with `idea_boards.board_group_id`, **or** a self-referential
  `parent_id` on `idea_boards`. A `board_groups` table is recommended.
- **idea_categories** — Automation, Cost Saving, Process Improvement, Employee Experience,
  Customer Impact, Compliance. Fields: `name`, `color`.
- **ideas** — `title`, `description`, `board_id`, `category`, `status`, `user_id` (author),
  `created_at`. Status enum: `new | review | planned | progress | done | declined`.
- **idea_votes** — one row per (user, idea). Vote count = `count()`; "voted" = current user has a row.
- **idea_comments** — `idea_id`, `user_id`, `body`, `created_at`.
- **idea_status_history** — `idea_id`, `status`, `changed_by` (user), `note` (nullable), `created_at`.
  Append a row on every status change; render newest-first as the "Activity" timeline.
- **idea_github_links** — `idea_id`, `repo`, `issue_number`, `url`, `state` (`open|closed`), `title`.

---

## Roles & Gating (critical)
A **View As** switcher (top-right) toggles the active role. In production this is the user's real
role from `organization_user`, not a switcher. **Regular employees must never see manager/admin
controls.**

- **employee** — Dashboard, All Ideas + boards, Submit, Idea detail (vote + comment only).
- **manager** — everything above **plus** the Review Queue nav item, and the "Manage status" panel
  + "Create GitHub issue" panel on idea detail.
- **admin/owner** — everything above **plus** the Organization settings screen.

Gate with policies/`@can` and Livewire authorization — do not merely hide with CSS.

---

## Global Layout
- **App shell:** fixed left **sidebar (250px)** + main column (top bar 60px + scrollable content).
- **Sidebar:** brand lockup (logo tile + "Rudaca Voice" + org name) → scrollable nav → user footer
  pinned to bottom. Nav order: Dashboard, All Ideas, Submit Idea, **BOARDS** (grouped, collapsible),
  then role-gated **Management/Administration** section (Review Queue, Organization).
- **Top bar:** search input (left, max 400px), spacer, **View As** segmented control, primary
  **+ New Idea** button.
- **Content:** centered, `max-width` ~1080px (detail 1000px, submit 680px), `padding: 28px 32px 56px`.

---

## Screens / Views

### 1. Employee Dashboard
- **Purpose:** landing view; orient the user and surface trending ideas + boards.
- **Layout:** greeting + H1; a row of **4 stat cards** (`grid-template-columns: repeat(4,1fr); gap:14px`);
  then a two-column split (`1.55fr / 1fr`, gap 20px): left = idea cards list, right = "Boards" panel.
- **Stat cards:** white, `border:1px solid #e4e4e7; border-radius:13px; padding:16px 18px`. A colored
  9px dot + label (12.5px/#71717a/600), a big value (29px/800, letter-spacing -1px), and a sub caption
  (12px/#a1a1aa). Content varies by role:
  - employee: Your ideas / Votes cast / In progress / Implemented
  - manager: Awaiting review / In progress / Implemented / Total ideas
  - admin: Total ideas / Members / Awaiting review / Implemented
- **Idea card (compact):** vote button (left) + title + status badge + board + comment count. Whole
  card clickable → idea detail. Hover: `border-color:#c7d2fe; box-shadow:0 2px 10px rgba(30,32,60,.06)`.
- **Boards panel:** rows of board icon tile + name + "N ideas" + chevron; click → All Ideas scoped to that board.

### 2. All Ideas (list) + board scoping
- **Purpose:** browse/filter every idea; or a single board when navigated from the sidebar.
- **Board header (top-left):** when a board is selected, show a 44px icon tile + group breadcrumb
  (12px/600/#8b8f99) + board name H1 + sub line "`N ideas · <board description>`". When viewing all,
  header is "All Ideas" + "`N ideas across M boards`" and no icon/breadcrumb.
- **Controls row:** left = segmented **sort** control (Top voted / Newest / Trending) in a
  `#eeeef1` track; right = **status** `<flux:select>` (All statuses / New / Under Review / Planned /
  In Progress / Implemented / Declined). *(Board is chosen from the sidebar, not a dropdown here.)*
- **Idea card (full):** `border-radius:13px; padding:16px 18px; gap:16px`. Left **vote button**
  (52px wide, column: triangle chevron + count). Right: status badge + category chip row → title
  (16px/700) → excerpt (13.5px/#71717a, truncated ~130 chars) → meta row (author avatar+name, board,
  comment count with speech icon). Hover as above (`box-shadow:0 2px 12px rgba(30,32,60,.07)`).
- **Empty state:** dashed card, centered muted text "No ideas match these filters."
- **Sort logic:** Top = votes desc; Newest = creation order; Trending = `(votes + comments*3)` desc.

### 3. Submit Idea
- **Purpose:** create a new idea.
- **Layout:** back link → H1 + intro paragraph → white form card (`border-radius:14px; padding:24px`).
- **Fields:** Title (text, 42px), then a 2-col grid: **Board** `<select>` grouped by board group via
  `<optgroup>`, **Category** `<select>`; then Description `<textarea>` (min-height 130px) + helper text.
- **Actions:** primary **Post idea** (#4f46e5) + secondary Cancel. **Validation:** title and board
  required — else inline error "Add a title and pick a board first." On submit: create idea with
  status `new`, seed the author's own vote (count starts at 1), append a `new` status-history row,
  navigate to the new idea's detail, show a success toast.

### 4. Idea Detail
- **Purpose:** read an idea, vote, discuss; managers/admins manage it.
- **Layout:** back link → two columns (`1fr / 300px`, gap 24px).
- **Left (main):** big **vote button** (72px, xl) + header (status badge, category chip, board;
  title H1 24px/800; author avatar + "Submitted by **Name** · <time>") → description paragraph
  (15px/1.65) → divider → **Comments** ("N comments") → comment composer (avatar + textarea + Comment
  button; button disabled/gray until non-empty) → comment list (avatar, name, staff role badge if
  manager/admin, timestamp, body).
- **Right rail (sticky):**
  - **Manage status panel** *(manager/admin only)* — star icon + "Manage status", a stacked list of
    the 6 statuses as selectable buttons (active one tinted with its status color + ✓), plus an
    optional note input. Selecting a status writes an `idea_status_history` row and toasts.
  - **Development / GitHub panel** *(manager/admin only)* — if a link exists, show a card with
    state pill (Open green / Closed purple), `#number`, issue title, `repo` (monospace), linking out.
    If not, muted text + black **Create GitHub issue** button opening the modal (see Interactions).
  - **Activity** — vertical timeline of `idea_status_history`, newest first: status-colored dot +
    connector line, bold status label, optional note, "`by · time`".

### 5. Manager Review Queue *(manager/admin only)*
- **Purpose:** triage ideas awaiting a decision (status `new` or `review`), highest-voted first.
- **Layout:** H1 + intro → 3 summary stat cards (Awaiting review / New this week / Total votes in
  queue) → a table card.
- **Table:** header row (`#fafafa`), columns **Votes | Idea | Board | Status | Decision**
  (`grid-template-columns: 64px 1fr 150px 130px 190px`). Votes cell = 44px rounded tile with count +
  small chevron. Idea cell clickable → detail. Decision cell = **Approve** (green, → status `planned`)
  and **Decline** (red outline, → status `declined`); both append history + toast. Cleared-queue empty
  state: "Queue is clear — nothing awaiting review. 🎉".

### 6. Admin Status/Update
Delivered as the **Manage status panel** on the Idea Detail right rail (screen 4) and via the Review
Queue Approve/Decline actions (screen 5). Every change appends an `idea_status_history` row with
optional note and re-renders the Activity timeline. No separate screen needed.

### 7. GitHub Create-Issue Flow *(manager/admin only)*
- Triggered by "Create GitHub issue" on the idea detail Development panel. Opens a centered **modal**
  (560px, `border-radius:16px`, dimmed backdrop, `modalIn` animation).
- **Fields:** Repository `<select>` (prefilled from org default repo), Issue title (prefilled from
  idea title), Description `<textarea>` (prefilled from idea description + a footer line), and
  **Labels** as toggle chips (enhancement + a category-derived label auto-selected).
- **Footer:** Cancel + green **Create issue** (#1f883d). On create: persist an `idea_github_links`
  row (repo, generated issue_number, url, state `open`, title), close modal, show a toast, and render
  the linked-issue card. In production, call the GitHub API (or queue a job) instead of faking the number.

### 8. Organization Settings *(admin/owner only)*
- **Layout:** H1 + intro → underline tab bar: **Boards | Categories | Members | Integrations**.
- **Boards tab:** list of board rows (icon tile, name, description, "N ideas" chip) + an add-board
  input row. *(Extend to support assigning a board to a board group.)*
- **Categories tab:** wrap of category chips (color dot + name + count) + add-category input.
- **Members tab:** table **Member | Email | Role** with avatar and a colored role chip
  (admin=pink, manager=teal, employee=gray).
- **Integrations tab:** GitHub connection card (Connected badge), default repository input, and an
  "Auto-create issues on approval" toggle.

---

## Interactions & Behavior
- **Navigation:** SPA-like screen switching; in Livewire use route/component swaps or `wire:navigate`.
- **Voting:** optimistic toggle — flips voted state and increments/decrements the count; button turns
  indigo-tinted (`bg #eef2ff`, border `#c7d2fe`, fill `#4f46e5`) when voted.
- **Board groups:** each group header toggles expand/collapse (chevron rotates 0°↔90°, `.15s`).
  Selecting a board scopes the list and highlights the active board row (`bg #eef2ff; color #4338ca`).
- **Comments:** submit appends immediately; composer clears; Comment button is disabled while empty.
- **Status change:** updates idea status, appends a history entry ("Just now", current user, note),
  toasts confirmation.
- **Toasts:** bottom-center dark pill with green check, auto-dismiss ~2.6s (`toastIn` .25s).
- **Modal:** closes on backdrop click / Cancel / ✕; inner click stops propagation.
- **Transitions:** content screens fade/rise in (`fadeIn` .25s); card hovers ease border+shadow .15s.

## State Management (prototype → production)
Prototype state (map to Livewire public properties / DB): `role`, `screen`, `selectedId`,
`filterBoard`, `filterStatus`, `sort`, `expandedGroups`, `form{title,description,board,category}`,
`commentDraft`, `statusNote`, `settingsTab`, GitHub modal `gh{repo,title,body,labels}`, `toast`.
In production, persisted data (ideas, votes, comments, history, links, boards, categories, members)
lives in MySQL via Eloquent; only ephemeral UI state (filters, drafts, expanded groups, modal open)
stays in the Livewire component.

## Design Tokens
- **Accent (indigo):** `#4f46e5` (primary), `#4338ca` (hover / active text), tint `#eef2ff`,
  border tint `#c7d2fe`.
- **Neutrals (zinc):** text `#18181b`; secondary `#52525b` / `#71717a`; muted `#8b8f99` / `#a1a1aa`;
  borders `#e4e4e7`; hairlines `#eeeef0` / `#f4f4f5`; app bg `#f4f4f5`; surface `#ffffff`; input bg `#fafafa`.
- **Status colors** (badge `bg` / `text` / `dot`):
  - New `#f1f1f4` / `#52525b` / `#a1a1aa`
  - Under Review `#fef4e6` / `#b45309` / `#f59e0b`
  - Planned `#e8f0fe` / `#1d4ed8` / `#3b82f6`
  - In Progress `#eef0fe` / `#4338ca` / `#6366f1`
  - Implemented `#e9f7ef` / `#15803d` / `#22c55e`
  - Declined `#fdecec` / `#b91c1c` / `#ef4444`
- **GitHub green:** `#1f883d` (hover `#1a7433`); open pill green, closed pill `#8957e5`.
- **Board colors:** Atlas `#4f46e5`, App `#0ea5e9`, Platform `#6366f1`, Operations `#0d9488`,
  Field Service `#f59e0b`, People & HR `#7c3aed`, Finance `#16a34a`, Customer Experience `#db2777`.
  (Icon tiles use the board color at ~10% alpha — hex + `18` suffix.)
- **Typography:** Public Sans (400/500/600/700/800). H1 24px/800, letter-spacing -0.5px; section
  headings 15px/700; body 13.5–15px; captions 11.5–12.5px. Prototype uses Public Sans; align to your
  app's font (Flux defaults to Inter — either is fine, just be consistent).
- **Radius:** cards 12–14px; buttons/inputs 8–9px; pills/badges 999px; modal 16px.
- **Shadow:** card hover `0 2px 10–12px rgba(30,32,60,.06–.07)`; primary button `0 1px 2px rgba(79,70,229,.3)`;
  modal `0 24px 60px rgba(0,0,0,.28)`.
- **Spacing:** content padding `28px 32px`; card padding `14–24px`; common gaps 10–16px.

## Assets
- **Fonts:** Public Sans via Google Fonts (swap to your app font if preferred).
- **Icons:** inline SVGs (nav glyphs, chevrons, search, comment, GitHub mark, check). Replace with
  Flux UI's icon set (`<flux:icon.*>`) / Heroicons in production.
- **Board icons:** emoji placeholders (🛰️ 📱 🧱 ⚙️ 🚚 👥 💰 💬). Swap for real icons if desired.
- No raster images or logos — the brand mark is an inline SVG in the sidebar.

## Files
- `Rudaca Voice.dc.html` — the full interactive prototype (all 8 screens + role switching + GitHub
  modal). Open in a browser to explore; read the source for exact markup, values, and the state/logic
  (data seed, decorators, handlers) that describe intended behavior.
- `support.js` — the tiny runtime that renders the prototype. **Reference only — do not port.**
