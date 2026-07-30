---
name: Distan Dispatch
colors:
  ink: "#1A1D21"
  slate: "#5B6572"
  line: "#DDE1E6"
  panel: "#FFFFFF"
  ground: "#F3F1EC"
  stamp: "#15544A"
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

This is deliberately **not** the warm-cream-and-terracotta look that generic
tooling reaches for. The ground is a cool recycled-stock off-white, the single
accent is an inked stamp green, and numerals are monospace because they are
counts on a manifest, not marketing figures.

## Colors

```yaml
colors:
  ink: "#1A1D21"
  slate: "#5B6572"
  line: "#DDE1E6"
  panel: "#FFFFFF"
  ground: "#F3F1EC"
  stamp: "#15544A"
```

A single-ink-plus-stamp system over recycled stock.

- **Ink** {colors.ink} carries all typography, all rules. Graphite-warm, never pure black.
- **Slate** {colors.slate} is for secondary text: captions, metadata, hints, labels.
- **Line** {colors.line} draws hairline dividers and panel edges — structure, never fill.
- **Panel** {colors.panel} is the worksheet surface: clean white cards sit on the ground.
- **Ground** {colors.ground} is the page: a cool paper stock, softer than pure white, so white panels read as sheets laid on a desk.
- **Stamp** {colors.stamp} is the one accent — an inked rubber-stamp green. It marks primary action and progress only. Its scarcity is the point.

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
- **Don't** color prose, headings, or metadata with the stamp green. The stamp
  marks action and progress only; using it on text spends its meaning.
- **Don't** reach for warm cream + terracotta. The ground is cool paper and the
  accent is stamp green precisely to avoid that default.
- **Don't** set counts, totals, or file paths in the proportional body face.
  Data is monospace so columns align.
- **Don't** add drop shadows, gradients, glows, or glass. A sheet on a desk has
  at most a single hairline edge.
- **Do** keep numerals and paths monospace and tabular so they read as a
  manifest.
- **Do** let status color live only on the left rule of an attention row and on
  badges. Its scarcity is what makes it legible.
- **Do** trust modest type sizes. The section heading is ~1.1× body, the stat
  numeral is the one large element per tile.
- **Do** leave the ground visible between panels. Sheets on a desk have space
  around them.
