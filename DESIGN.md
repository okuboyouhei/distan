---
name: Distan Dispatch
colors:
  ink: "#201C16"
  slate: "#6A655B"
  line: "#DED9CC"
  panel: "#FFFFFF"
  ground: "#F0EFE3"
  stamp: "#7A5A34"
  ok: "#15654A"
  warn: "#8A5A00"
  danger: "#9E3319"
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

The palette is drawn from the Distan book cover ("Distan 解体新書"): the mascot
carries a kraft "dist/" shipping box across warm cream stock. So the admin uses
that same world — a warm cream kraft ground, a single cardboard-kraft ink stamp
for action, charcoal for type — and numerals are monospace because they are
counts on a manifest, not marketing figures. The one saturated brand element,
like the box on the cover, is the kraft stamp; it marks action and progress and
nothing else.

## Colors

```yaml
colors:
  ink: "#201C16"
  slate: "#6A655B"
  line: "#DED9CC"
  panel: "#FFFFFF"
  ground: "#F0EFE3"
  stamp: "#7A5A34"
```

A single-ink-plus-stamp system over warm kraft stock.

- **Ink** {colors.ink} carries all typography, all rules. Warm graphite, never pure black.
- **Slate** {colors.slate} is for secondary text: captions, metadata, hints, labels. A warm gray.
- **Line** {colors.line} draws hairline dividers and panel edges — structure, never fill.
- **Panel** {colors.panel} is the worksheet surface: clean white cards sit on the ground.
- **Ground** {colors.ground} is the page: warm cream kraft stock, softer than pure white, so white panels read as sheets laid on a desk. This is the cover's paper.
- **Stamp** {colors.stamp} is the one accent — a cardboard-kraft ink stamp, the colour of the "dist/" box on the cover. It marks primary action and progress only. Its scarcity is the point.

Status colors ({colors.ok}, {colors.warn}, {colors.danger}) appear only on
badges and status rules, never as decoration. They are ink stamps too, not
brand color.

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
look at (broken links, warnings) without coloring the whole sheet.

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
- **Don't** color prose, headings, or metadata with the kraft stamp. The stamp
  marks action and progress only; using it on text spends its meaning.
- **Don't** let the kraft warm the whole surface into a cozy or rustic look, and
  don't drift the accent toward terracotta or orange. It is a cardboard-kraft
  stamp on cream stock — the ground stays a plain warm paper, not a texture.
- **Don't** set counts, totals, or file paths in the proportional body face.
  Data is monospace so columns align.
- **Don't** add drop shadows, gradients, glows, or glass on in-flow elements. A
  sheet on a desk has at most a single hairline edge. (The one exception is the
  floating help layer — see below.)
- **Do** keep numerals and paths monospace and tabular so they read as a
  manifest.
- **Do** let status color live only on the left rule of an attention row and on
  badges. Its scarcity is what makes it legible.
- **Do** trust modest type sizes. The section heading is ~1.1× body, the stat
  numeral is the one large element per tile.
- **Do** leave the ground visible between panels. Sheets on a desk have space
  around them.

## Help tool (floating layer)

The bottom-right help tool ("使い方") is the one **floating** element in the
admin screen, and the single sanctioned exception to "no drop shadows". A flat
in-flow card is a sheet lying on the desk and carries no shadow; the help panel
is a sheet **lifted off** the desk to hand over, so it gets one faint lift
(`0 6px 22px rgba(26,29,33,.14)`) to separate it from arbitrary page content
beneath. Everything else stays within the system: 2px radius, hairline border
doing the structural work, the kraft stamp on the call button and nowhere in the
panel's prose, step numbers set in the monospace numeral face. The tool
**orients** (the 環境 → 生成 → 受け取り／公開 flow) and **points** at the
sections where contextual help already lives; it never duplicates that detail.
Keep it that way — a second copy of the same guidance is the failure mode.
