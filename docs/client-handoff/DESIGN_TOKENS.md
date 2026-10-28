# MindEase design tokens

Source: the Figma file (freud UI kit, Main – Light Mode). Values marked **var** are Figma variables; the others were sampled from rendered screens. Code: `lib/core/theme/`.

## Colour

| Token | Hex | Use |
|---|---|---|
| red | `#D64938` | Primary buttons, hero headers, active tab |
| redBright | `#EC4B38` | Active step accent |
| redDeep | `#B93A2B` | Pressed state, text on soft red |
| redSoft | `#FBE3DF` | Support card, soft red fills |
| cream (var Mindful Brown/10) | `#F7F4F2` | App background |
| brown (var Mindful Brown/80) | `#4F3422` | Headings, selected chips |
| ink | `#3F3B39` | Body text |
| grey60 (var Optimistic Gray/60) | `#736B66` | Secondary text |
| grey30 (var Optimistic Gray/30) | `#C9C7C5` | Disabled |
| stone | `#E8DDD9` | Tracks, borders, locked |
| serenityGreen (var focus ring) | `#9BB068` | Phase 1, completed, focus ring |
| empathyOrange | `#ED7E1C` | Phase 2, "not passed yet" |
| kindPurple | `#A694F5` | Phase 3 |
| zenYellow | `#FFCE5C` | Mood "Good" |
| linkGreen | `#3F7F63` | Links. Figma uses `#68AC8F`, darkened to pass WCAG AA on cream |

Phase colours: P1 `#9BB068`, P2 `#ED7E1C`, P3 `#A694F5`, P4 `#D64938` (set in content JSON).

## Type: Urbanist

| Style | Size / line height | Weight | Tracking |
|---|---|---|---|
| display (Heading sm) | 30 / 38 | 800 | -1 |
| title | 22 / 1.25 | 800 | -0.5 |
| heading | 17 / 1.3 | 800 | -0.3 |
| textLg | 18 | 800 | -0.4 |
| body | 15 / 1.55 | 500 | — |
| article | 16.5 / 1.65 | 500 | — |
| label (Text sm Bold) | 14 | 800 | -0.2 |
| small | 13 / 1.45 | 500 | — |
| eyebrow | 12, uppercase | 800 | +1 |

## Shape and spacing

- Side gutter 20. Card radius 24. Option/answer radius 20–22. Buttons and inputs are pills (999).
- Primary button height 56. Minimum tap target 44.
- Hero header: red, bottom radius 34, two darker translucent blobs. Auth screens use a slanted bottom edge.
- Bottom nav: white, active tab is a red pill (44×36) with a white icon.
