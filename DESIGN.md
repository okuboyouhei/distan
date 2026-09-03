---
name: Distan Dispatch
colors:
  ink: "#1d2327"
  slate: "#50575e"
  line: "#c3c4c7"
  panel: "#ffffff"
  ground: "#f0f0f1"
  accent: "var(--wp-admin-theme-color, #2271b1)"
  ok: "#007017"
  warn: "#996800"
  danger: "#b32d2e"
typography:
  h1:
    fontFamily: inherit
    fontSize: 23px
    fontWeight: 600
  section:
    fontFamily: inherit
    fontSize: 13px
    fontWeight: 600
    letterSpacing: 0.02em
  body:
    fontFamily: inherit
    fontSize: 13px
  label-caps:
    fontFamily: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace
    fontSize: 11px
    letterSpacing: 0.08em
    textTransform: uppercase
  numeral:
    fontFamily: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace
    fontSize: 27px
    fontWeight: 400
rounded:
  none: 0
  sm: 2px
spacing:
  xs: 6px
  sm: 12px
  md: 18px
  lg: 26px
---

## Overview

A dispatch note for a shipping desk. Distan's job is to pack a WordPress site
into a set of deliverable files and hand them over, so its admin screen behaves
like the packing slip that rides along with the shipment: a checked, dated,
itemised record of what was produced and what needs attention before it goes
out. The register is that of a warehouse worksheet or a printshop job ticket —
plain, exact, and proudly unconcerned with first impressions. The person
reading it wants to know what shipped, what changed, and what is broken, in
that order.

The brand is the book cover ("Distan 解体新書"): a mascot carrying a kraft
"dist/" shipping box across warm stock. That kraft world is the identity — it
lives in the mascot, the docs, and the concept figure. The admin screen is a
different matter: it is a plugin screen, and a plugin screen should sit *inside*
WordPress rather than skin it. So the colours come from the WordPress admin
itself — its neutral greys for surface and type, and its accent (the colour each
user picks in their profile) for the one primary action. The layout stays a
packing slip; only the ink is WordPress's. Numerals are monospace because they
are counts on a manifest, not marketing figures.

## Colors

```yaml
colors:
  ink: "#1d2327"
  slate: "#50575e"
  line: "#c3c4c7"
  panel: "#ffffff"
  ground: "#f0f0f1"
  accent: "var(--wp-admin-theme-color, #2271b1)"
```

The WordPress admin palette: neutral greys, plus one accent that follows the
user's chosen admin colour scheme.

- **Ink** {colors.ink} is the WordPress admin's primary text colour. It carries all typography and all rules.
- **Slate** {colors.slate} is the admin's secondary text: captions, metadata, hints, labels.
- **Line** {colors.line} is the admin's border grey — hairline dividers and panel edges, structure never fill.
- **Panel** {colors.panel} is the card surface: plain white sheets on the ground.
- **Ground** {colors.ground} is WordPress's own content background. It is not painted; the native admin grey shows through, and white panels read as sheets laid on the desk.
- **Accent** {colors.accent} is the one saturated colour, taken from `--wp-admin-theme-color` so it tracks whichever scheme the user has set (blue by default, but coffee, ectoplasm, and the rest all flow through). It marks primary action and progress only; its scarcity is the point. In code it is the `--dsp-stamp` token.

Status colors ({colors.ok}, {colors.warn}, {colors.danger}) follow WordPress's
own status conventions and appear only on badges and status rules, never as
decoration. Everyday status is shown in neutral greys; saturated colour is kept
for a real problem — a broken link is red, an "OK" is calm.

## Typography

The face is the WordPress admin stack (system UI) for prose, but **data is set
in monospace**: counts, file paths, page totals, progress figures. On a packing
slip the quantities are typed in a fixed pitch so a column of numbers lines up
and can be read down. That single choice — numerals and paths in mono, prose in
sans — carries most of the character.

- **Numeral** {typography.numeral} is the stat figure: large, monospace, plain weight. A quantity on a manifest.
- **Label-caps** {typography.label-caps} is the small uppercase monospace label — the pre-printed field name on a form ("OUTPUT", "REMOVED", "BROKEN LINKS").
- **Section** {typography.section} headings are only ~1.1× body. Modest. The worksheet does not shout its section titles.
- **Body** {typography.body} is the WordPress admin body size.

## Structure

Cards are worksheets: a white sheet with a titled header rule and itemised rows
beneath. Stat tiles are the quantity boxes of a slip — a monospace count over a
small uppercase field label, divided by hairlines. Disclosures are the
attention rows: a status color on the left edge marks what a checker needs to
look at (broken links, warnings) without coloring the whole sheet. The section
sub-navigation is a plain in-flow bar, not a sticky one — it scrolls with the
page so it never covers a heading beneath it.

## Motion

```yaml
motion:
  feedback: 120ms
  fill: 180ms
  easing: "ease-out"
```

Mechanical and brief. The one moving part is the progress bar filling as pages
are packed — a tally advancing, not an animation. Nothing bounces or lingers.
`prefers-reduced-motion` collapses the fill transition to none.

## Do's and Don'ts

- **Don't** use rounded corners beyond a 2px softening. This is a printed
  worksheet, not a consumer app card. Hairline rules do the structural work.
- **Don't** override the accent with a fixed colour. It comes from
  `--wp-admin-theme-color` so it follows the user's admin scheme; pinning it to
  one hue breaks that and makes the screen fight WordPress. Reserve it for
  primary action and progress — using it on prose spends its meaning.
- **Don't** paint the ground. Let WordPress's own admin background show; white
  panels read as sheets on it. The plugin lives inside WordPress, it does not
  reskin it.
- **Don't** set counts, totals, or file paths in the proportional body face.
  Data is monospace so columns align.
- **Don't** add drop shadows, gradients, glows, or glass on in-flow elements. A
  sheet on a desk has at most a single hairline edge. (The one exception is the
  floating help layer — see below.)
- **Do** keep numerals and paths monospace and tabular so they read as a
  manifest.
- **Do** keep everyday status in neutral greys and let saturated colour mean a
  real problem — a broken link red, an error red. Its scarcity is what makes it
  legible.
- **Do** trust modest type sizes. The section heading is ~1.1× body, the stat
  numeral is the one large element per tile.
- **Do** leave the ground visible between panels. Sheets on a desk have space
  around them.

## Help tool (floating layer)

The header help tool ("使い方") opens the one **floating** element in the admin
screen, and the single sanctioned exception to "no drop shadows". A flat in-flow
card is a sheet lying on the desk and carries no shadow; the help panel is a
sheet **lifted off** the desk to hand over, so it gets one faint lift to
separate it from arbitrary page content beneath. Everything else stays within
the system: 2px radius, hairline border doing the structural work, the accent on
the call button and nowhere in the panel's prose. The tool **orients** (the
環境 → 生成 → 受け取り／公開 flow) and **points** at the sections where
contextual help already lives — its headings link straight into the matching
setting — and it carries copy-paste snippets for the template markers. It never
duplicates the long-form detail; a second copy of the same guidance is the
failure mode.
