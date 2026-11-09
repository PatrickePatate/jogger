# Jogger — Branding Guide

> Open-source developer tool, UI-heavy. Depth: medium (identity, color, type, voice, visual language). Visual baseline comes from the user-supplied step-progress card (soft cards, hairline borders, muted secondary text, one green accent, dark-ink progress bar).

## 1. Name & Identity

### 1.1 Project Name
- **Name**: Jogger
- **Pronunciation**: /ˈdʒɒɡər/ ("JOG-er")
- **Etymology**: A jogger is a *runner* who keeps a steady, sustainable pace, not a sprinter burning out. It nods to GitHub *runners*: lightweight, always moving, started fresh for each outing and done when the run is over.
- **In code**: `jogger` (repo, images `ghcr.io/<org>/jogger`, `jogger-agent`, `jogger-runner`; env prefix `JOGGER_`; Docker labels `jogger.*`)
- **In prose**: "Jogger" capitalized as a proper noun; "the Jogger agent", "a Jogger runner". Never "JOGGER" or "JoggerCI" (it is not a CI server in v1).
- **Naming check (open item from SPEC §11.4)**: verify no conflicts with existing "Jogger" projects/trademarks, and keep the "not affiliated with GitHub" notice in README and footer. Never use GitHub's logo or "GitHub Runner" as a product name; say "runners for GitHub Actions".

### 1.2 Tagline
- **Primary**: Your GitHub Actions runners, on your own hardware.
- **Technical**: Ephemeral Docker runners for GitHub Actions, with autoscaling and a live dashboard.
- **Marketing**: Stop paying for CI minutes. Run every job on machines you control.

### 1.3 Elevator Pitch
Jogger is a self-hosted platform that runs your GitHub Actions jobs on your own Docker hosts. It spins up a fresh, isolated runner for every job, scales with your queue, and shows everything live: what is waiting, what is running, and why something failed. One `docker compose up` to install, no Kubernetes required.

## 2. Logo

### 2.1 Concept
A **rounded "route" mark**: a single continuous line that starts at a small filled dot (a job, queued), loops once around a ring (a runner, running), and exits as an arrow-less tail (done). It echoes the stepper's node-and-connector motif used throughout the UI, so the logo and product share one visual language. Calm and fast, not aggressive.

### 2.2 Specifications
- **Primary mark**: icon + wordmark horizontally; icon left, "jogger" lowercase right.
- **Icon mark**: the line-and-ring glyph inside a rounded square (radius 22 % of side), ink `#14171C` background, glyph in white with the ring's active arc in brand green `#3DD598`.
- **Wordmark**: lowercase "jogger", Instrument Sans SemiBold, letter-spacing −1 %.
- **Minimum size**: icon 16 px (favicon uses a simplified single-ring version); full logo 96 px wide.
- **Clear space**: height of the ring on all sides.
- **Variants**: ink-on-light, white-on-dark, single-color (for README badges).

### 2.3 AI Generation Prompt
> Minimal flat vector app icon on a rounded-square dark charcoal (#14171C) background. A single continuous white line with uniform stroke weight: it starts at a small solid dot at the bottom left, curves up into one clean circular ring in the center, and ends in a short straight tail toward the top right. One quarter of the ring is highlighted in mint green (#3DD598). Geometric, lots of negative space, perfectly centered, optically balanced, no text, no gradients, no shadows, no 3D, no robot or running-person clichés, no GitHub octocat. 1:1, 1024×1024, crisp edges suitable for downscaling to 16 px.

Create variants by changing the background to white (#FFFFFF) with an ink line for light contexts.

## 3. Color Palette

Principle: **ink for action, green for progress and success, color only for state.** The primary button is dark ink (as in the reference's dark progress bar); green appears on completed steps, success states, and the logo highlight, so it keeps its meaning.

### 3.1 Brand Colors

| Role | Name | Hex | RGB | Usage |
|------|------|-----|-----|-------|
| Primary | Ink | #14171C | rgb(20,23,28) | Primary buttons, progress bars, headings, logo background |
| Accent | Jog Green (light UI) | #0F7A57 | rgb(15,122,87) | Completed steps, success, links on light |
| Accent | Jog Mint (dark UI / logo) | #3DD598 | rgb(61,213,152) | Same roles in dark mode, logo highlight |
| Secondary | Slate | #5B6270 | rgb(91,98,112) | Secondary text, icons, inactive connectors |

### 3.2 Neutrals (light)

| Role | Hex | Usage |
|------|-----|-------|
| Text Primary | #14171C | Body text, headings |
| Text Secondary | #5B6270 | Captions, metrics lines under step labels |
| Background | #F6F7F9 | Page background |
| Surface | #FFFFFF | Cards, panels, modals |
| Border | #E3E6EB | Card outlines, dividers (1 px hairline) |
| Border strong | #C9CED6 | Inputs, pending step rings |

### 3.3 Semantic Colors (light)

| Role | Hex | Usage | Used for (Jogger states) |
|------|-----|-------|--------------------------|
| Success | #0F7A57 | Completed, healthy | job `success`, runner `idle` healthy, host `online` |
| Error | #B42318 | Failed, destructive | job `failure`, runner `failed`, host `offline` |
| Warning | #92400E | Needs attention | `queued` too long, host `draining`, unmatched jobs |
| Info | #1D4ED8 | Running / active | job `in_progress`, runner `busy`, active step spinner |
| Neutral | #5B6270 | Inactive | `cancelled`, `skipped`, `cordoned`, pending steps |

Backgrounds for badges use 10 % tints of the same hues. State is always paired with an icon and a text label (never color alone).

### 3.4 Dark Mode

| Role | Hex | Notes |
|------|-----|-------|
| Background | #0E1116 | Page |
| Surface | #161A21 | Cards |
| Border | #262C36 | Hairlines |
| Text Primary | #EDEFF3 | |
| Text Secondary | #9AA3B2 | |
| Primary (button) | #EDEFF3 on text #0E1116 | Inverted ink |
| Success / accent | #3DD598 | |
| Error | #F97066 | |
| Warning | #FDB022 | |
| Info | #7AA2FF | |

Contrast (computed): all text/background pairs above meet WCAG AA. Highlights: Text Primary on Surface 16:1, Text Secondary on Surface 6.9:1 (dark) and 5.7:1 (light, on #F6F7F9), accent green on white 5.3:1, error on white 6.6:1, mint on dark surface 9.3:1.

### 3.5 CSS Variables / Tailwind 4 tokens

Drop into `resources/css/app.css` (Task 3). Dark values apply under `.dark` (class strategy, system default via script).

```css
@import 'tailwindcss';
@custom-variant dark (&:where(.dark, .dark *));

@theme {
  --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;

  --color-ink: #14171c;
  --color-bg: #f6f7f9;
  --color-surface: #ffffff;
  --color-border: #e3e6eb;
  --color-border-strong: #c9ced6;
  --color-text: #14171c;
  --color-text-secondary: #5b6270;

  --color-success: #0f7a57;
  --color-error: #b42318;
  --color-warning: #92400e;
  --color-info: #1d4ed8;

  --radius-card: 16px;
  --radius-control: 10px;
  --shadow-card: 0 1px 2px rgb(20 23 28 / 0.04), 0 4px 12px rgb(20 23 28 / 0.04);
}

.dark {
  --color-ink: #edeff3;
  --color-bg: #0e1116;
  --color-surface: #161a21;
  --color-border: #262c36;
  --color-border-strong: #3a4250;
  --color-text: #edeff3;
  --color-text-secondary: #9aa3b2;
  --color-success: #3dd598;
  --color-error: #f97066;
  --color-warning: #fdb022;
  --color-info: #7aa2ff;
}
```
Components use semantic utilities (`bg-surface`, `text-text-secondary`, `border-border`) so the dark swap needs no per-component rules.

## 4. Typography

### 4.1 Font Stack

| Role | Font | Weights | Fallback |
|------|------|---------|----------|
| Headings & UI | Instrument Sans (already configured via the Bunny fonts plugin in `vite.config.js`) | 500, 600 | system-ui, sans-serif |
| Body | Instrument Sans | 400, 500 | system-ui, sans-serif |
| Code, logs, IDs | JetBrains Mono (add to `vite.config.js` fonts) | 400, 500 | ui-monospace, Menlo, monospace |

Numbers in tables, metrics, and durations use `font-variant-numeric: tabular-nums`. Both fonts are open source (OFL).

### 4.2 Type Scale (app UI is compact; marketing/docs may scale up)

| Element | Size | Weight | Line Height |
|---------|------|--------|-------------|
| Page title (H1) | 1.5rem | 600 | 1.3 |
| Section / card title (H2) | 1rem | 600 | 1.4 |
| Body | 0.875rem | 400 | 1.55 |
| Step label | 0.9375rem | 500 | 1.4 |
| Secondary / metric line | 0.8125rem | 400 | 1.4 |
| Counter ("2 of 4") | 0.8125rem | 500 | 1.2 |
| Code / logs | 0.8125rem | 400 | 1.5 |
| Docs/README H1 | 2.25rem | 700 | 1.2 |

## 5. Voice & Tone

### 5.1 Personality
- **Calm**: no alarmism, even on failure. "Runner provisioning timed out after 3 min. Retrying on another host."
- **Specific**: always say which thing and why. "No pool matches `gpu, linux`. Create a pool with these labels."
- **Plainspoken**: short sentences, no hype, no mascot puns beyond the name.
- **Helpful**: every error suggests the next action.

### 5.2 Writing Rules
- UI labels are sentence case ("Create pool", not "Create Pool").
- Buttons are verbs; destructive ones name the object ("Terminate runner").
- Errors state what happened, why if known, and what to do; include the request ID in details, never a stack trace.
- Empty states teach: say what belongs here and offer the single next action.
- Docs: assume the reader knows Docker and GitHub Actions; explain Jogger-specific concepts using the Core Concepts glossary terms exactly (Pool, Runner, Host, Agent).
- Marketing: lead with cost, control, and visibility; avoid "blazing fast", "revolutionary".

### 5.3 Vocabulary

| Prefer | Avoid |
|--------|-------|
| runner | worker, bot, node (for runners) |
| host | server, machine (in UI; "machine" is fine in prose) |
| pool | group, fleet (except marketing "fleet") |
| job | task, build |
| terminate (a runner) | kill, delete |
| queued / running / completed | pending / active / done (in job context; the stepper uses done/active/pending visually only) |
| "ephemeral" | "disposable" |

## 6. Visual Language

### 6.1 Border Radius
Cards 16 px, controls (inputs, buttons, menus) 10 px, pills/badges full-round, modals 20 px. Never mix sharp and soft corners in one view.

### 6.2 Shadows and Borders
Prefer **1 px hairline borders** over shadows; cards carry a barely-there shadow (`--shadow-card`). Elevated layers (dropdowns, modals, toasts) use a larger, softer shadow and a backdrop dim of 40 % ink.

### 6.3 Spacing
4 px base unit; scale 4, 8, 12, 16, 20, 24, 32, 48. Card padding 20–24 px; stepper row gap 20 px; page gutters 16 px mobile / 32 px desktop.

### 6.4 Signature Component: Stepper Card
The brand's most recognizable element, taken from the user's reference:
- Card with title left and "N of M" counter + 4–6 px high progress bar (ink fill on border-color track, rounded) right.
- Vertical steps: 24 px node, 2 px connector (`success` when the step is done, `border-strong` otherwise), label in Text Primary, metric line in Text Secondary.
- Node states: done = filled `success` circle with white check; active = 2 px ring in `info` with a 270° spinning arc (1.2 s linear, off under reduced motion); pending = 2 px `border-strong` ring; failed = filled `error` with ×; skipped = dashed ring.
- Appears on: job detail, runner drawer, host enrollment, GitHub connect, getting-started checklist.

### 6.4b Buttons (inspired by shadcn/ui's Base UI button)
Flat and quiet: no shadows or gloss, hairline borders, 10 px radius, `font-medium`, 150 ms `transition-all`.
- **Variants:** `primary` (ink fill, hover 80 %), `secondary` (muted fill), `outline` (surface + border, hover muted), `ghost` (transparent, hover muted), `destructive` (soft red tint `danger/10` with red text, hover `/20`), `danger` (solid vivid red `#dc2626`, white text 4.8:1, for final confirmations), `link` (underline on hover).
- **Sizes:** xs 28 px, sm 32 px, md 36 px (default, matches inputs), lg 40 px; `icon-only` squares of the same heights; `pill` for rounded-full.
- **States:** active nudges 1 px down; keyboard focus shows a 3 px `ring/50` ring plus a ring-colored border (mouse focus shows nothing); disabled is 50 % opacity with no pointer events; `loading` shows a spinner and disables.
- **Icons:** 16 px (14 px at xs), leading via `icon`, trailing via `icon-end`. Pointer cursor restored globally (Tailwind v4 defaults to `cursor: default`).
- **Tokens:** `--color-danger`, `--color-danger-hover`, `--color-danger-ink` (red text/tints, lighter in dark mode), `--color-muted`, `--color-ring`.

### 6.5 Icons
Phosphor Icons (regular weight, 1.5 px optical stroke, 16/20 px sizes) as inline SVG Blade components, matching Pines UI's icon family. Status icons: check-circle, spinner-gap, x-circle, clock, pause-circle, warning, server, cpu, terminal-window, git-branch. Icons inherit `currentColor`.

### 6.6 Motion
150 ms ease-out for hover/focus, 200 ms for modals and drawers, 1.2 s linear spinner. Live updates fade/slide in 120 ms to avoid flashing rows. Everything respects `prefers-reduced-motion`.

### 6.7 Data Display
Tables are dense (40 px rows), mono for IDs/SHAs/durations, relative times with absolute on hover, sticky headers. Log viewer uses the dark palette regardless of theme, with ANSI colors mapped to a muted 16-color set.

## 7. Assets Checklist

| Asset | Format | Size | Status |
|-------|--------|------|--------|
| Logo (full, light/dark/mono) | SVG + PNG | vector / 1024 px | [TBD: generate from §2.3, hand-clean in SVG] |
| Icon | SVG + PNG | 512, 192, 64 px | [TBD] |
| Favicon | .ico + SVG + PNG | 32, 16 px (simplified single ring) | [TBD] |
| Apple touch / PWA icons | PNG | 180, 192, 512 px | [TBD] |
| OG image | PNG | 1200×630 (stepper-card hero on dark) | [TBD] |
| Social banner | PNG | 1500×500 | [TBD] |
| README header | SVG | 800×200 | [TBD] |
| README screenshots (light + dark) | PNG | 1600 px wide | [TBD after Task 45] |
| Empty-state illustrations | SVG (line, 1 color + accent) | 160 px | [TBD, optional] |

## 8. Implementation Notes (feed to Tasks 3, 4, 61)

- Task 3: apply §3.5 tokens, add JetBrains Mono to `vite.config.js` fonts, build the dark toggle with `.dark` on `<html>`.
- Task 4: implement the stepper exactly per §6.4; add a snapshot test of node classes per state.
- Task 59: verify contrast with the computed values in §3.4 and axe-core.
- Task 61: README uses logo, tagline §1.2, and light/dark screenshots (`<picture>` with `prefers-color-scheme`).
