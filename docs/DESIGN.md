---
name: "Bindura Science Academy Portal"
description: "The school's own navy-and-crest identity from bindurascienceacademy.co.zw, set calm and orderly for a school administration portal."
colors:
  bg: "#FFFFFF"
  surface: "#F6F7F9"
  surface-alt: "#FAFBFC"
  border: "#E5E7EB"
  ink: "#1D212B"
  text: "#343A48"
  muted: "#6B7280"
  accent: "#1B3674"
  accent-hover: "#132653"
  accent-light: "#376DBE"
  gold: "#CEA378"
  crest-red: "#A71B32"
typography:
  heading:
    fontFamily: "Playfair Display, Georgia, serif"
    fontWeight: "700"
  body:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontWeight: "400"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "40px"
rounded:
  sm: "6px"
  md: "10px"
components:
  buttons: "Solid navy #1B3674, white text, 6px radius, no shadow; hover #132653"
  forms: "White fields, 1px #E5E7EB border, 6px radius, navy focus ring"
  cards: "Only for real grouping (login box, dashboard panels): white on #F6F7F9, 10px radius, 1px border, soft navy-tinted shadow"
  tables: "Hairline #E5E7EB row dividers, #F6F7F9 header row, no zebra stripes"
  status: "Success navy-light, warning gold, error crest red, always with a text label"
dials:
  variance: 0.15
  density: 0.6
  motion: 0.05
---

## Overview

Captured from the school's existing public website (Lovable build) and its official
crest, not invented. Bindura Science Academy is a ZIMSEC secondary school whose motto is
"Passion for Excellence". The portal is an operational tool for staff, parents and
learners, so the direction keeps the school's identity (crest, navy, Playfair headings)
and sets it calm, symmetric and fairly dense: forms, tables and dashboards come first.

## Colors

- Ground white `#FFFFFF`, with `#F6F7F9` and `#FAFBFC` as cool surface tints.
- Ink `#1D212B` for headings; body text `#343A48`; secondary text `#6B7280`.
- **One accent: navy `#1B3674`** (the crest and website primary) for buttons, links and
  focus. Hover `#132653`; `#376DBE` only for informational highlights.
- Gold `#CEA378` and crest red `#A71B32` are brand supports: gold for thin rules and
  "warning" state, red for errors and destructive actions only. Neither is used for text
  on white (gold fails contrast).

## Typography

Playfair Display for headings, Plus Jakarta Sans for everything else, both the website's
own faces, self-hosted. A serif display face is justified because it is the school's
existing brand typeface. Headings sit tight (line-height 1.15–1.2); body 1.6 at 16px;
tables and forms 15px.

## Layout

Composed and symmetric (variance 0.15). One content container shared by header and body.
Moderate density (0.6): compact tables and forms, generous only around the login box.
Mobile: every multi-column layout collapses to one column below 768px; tables scroll
horizontally inside their own wrapper.

## Elevation & Depth

Flat by default. Separation by hairline borders and surface tints. A soft navy-tinted
shadow (`0 8px 24px rgba(27, 54, 116, 0.08)`) only on real containers such as the login box.

## Shapes

6px radius for controls (buttons, inputs), 10px for containers. Nothing pill-shaped.

## Components

Buttons solid navy; secondary buttons navy outline on white. Inputs white with a 1px
border and a 2px navy focus ring. Status always pairs a colour with a word.

## Do's and Don'ts

### Do's
- Use the official crest unmodified, on white or very light grounds, with clear space.
- Use navy as the only action colour on any screen.
- Keep text contrast at WCAG AA or better (navy on white 11.5:1, body text 11.4:1, muted 4.8:1; gold on white is only 2.3:1).
- Always pair status colours with a text label.

### Don'ts
- Never use gold for text or small icons on white.
- Never introduce purple, gradients or glow effects.
- Never load fonts from Google's CDN; use the self-hosted files.
- Avoid decorative stock photography on administrative screens.
- Don't use em-dashes in interface copy.
