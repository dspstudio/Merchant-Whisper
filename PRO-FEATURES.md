# MW Sales Toast — Pro feature sketch

Planning doc for later work. **UX/UI first**, then capability layers.

Status key: `idea` · `designed` · `building` · `shipped`

---

## 0. UX / UI foundation (do first)

Before new Pro capabilities, harden the admin and toast experience so Pro features have a clear home.

### 0.1 Admin shell
| Item | Notes | Status |
|------|--------|--------|
| Feature gates in UI | Pro badges, locked rows, upgrade CTA pattern | idea |
| Pro tab / section IA | Clear Free vs Pro grouping without cluttering Free | idea |
| Empty / locked states | Preview of locked controls (blurred or read-only) | idea |
| Onboarding checklist | First-run: enable → source → preview → save | idea |
| Settings search / jump | Find “consent”, “gap”, “CSS” quickly as options grow | idea |
| Mobile admin polish | Tabs + sticky save already exist; tighten Pro panels | idea |

### 0.2 Toast UI system
| Item | Notes | Status |
|------|--------|--------|
| Layout variants | Compact / standard / rich (image + stars + CTA) | idea |
| Theme packs | Light, minimal, brand-match — beyond color pickers | idea |
| Motion presets | Soft / snappy / none (respect reduced motion) | idea |
| Mobile layout rules | Position + max-width + optional hide image | idea |
| Live theme switcher | Admin preview cycles layouts without save | idea |

### 0.3 Design tokens
| Item | Notes | Status |
|------|--------|--------|
| Token map | Document CSS variables (bg, accent, radius, etc.) | idea |
| Theme JSON | Export/import theme as JSON for agency reuse | idea |
| Per-breakpoint tokens | Optional mobile overrides | idea |

---

## 1. Targeting & display rules (Pro)

| Feature | UX sketch | Status |
|---------|-----------|--------|
| Include / exclude URLs | Tag-style list + “current page” helper | idea |
| Product / category rules | Woo search pickers | idea |
| Logged-in / role rules | Simple toggles + role multi-select | idea |
| Device rules | Desktop / tablet / mobile beyond one breakpoint | idea |
| Rule summary chip | Sidebar: “Showing on 12 products · excluded cart” | idea |

---

## 2. Triggers & timing (Pro)

| Feature | UX sketch | Status |
|---------|-----------|--------|
| Trigger mode | Radio: timed · scroll · exit · add-to-cart | idea |
| Scroll depth | Slider 25–75% with live label | idea |
| Page-type timing | Different delay/gap on shop vs product | idea |
| Quiet hours | Optional schedule (store timezone) | idea |
| Fatigue smart mute | “Mute 24h after click/purchase” | idea |

---

## 3. Content & social proof types (Pro)

| Feature | UX sketch | Status |
|---------|-----------|--------|
| Multi-templates | List of templates + default; optional rotate | idea |
| Viewing now | “X people viewing this” — toggle + copy | idea |
| Review toasts | Stars + snippet — style matches sales toast | idea |
| Urgency / stock | “Only 3 left” when Woo stock allows | idea |
| CTA on toast | Optional button: View / Add / Coupon | idea |
| Geo-aware demo | Match demo city language to visitor (approx.) | idea |

---

## 4. Analytics (Pro)

| Feature | UX sketch | Status |
|---------|-----------|--------|
| Dashboard card | Impressions · clicks · CTR · attributed carts | idea |
| Per-product table | Sortable; link to product edit | idea |
| Date range | 7 / 30 / 90 days | idea |
| Privacy note | No PII in analytics; aggregate only | idea |

---

## 5. Ops / agency (Pro)

| Feature | UX sketch | Status |
|---------|-----------|--------|
| Import / export settings | JSON download/upload | idea |
| White-label admin | Hide plugin brand in header | idea |
| License screen | Activate key · status · renew link | idea |
| Priority support badge | Support tab shows Pro SLA copy | idea |

---

## Suggested build order

1. **UX/UI foundation** — gates, theme packs, layout variants, preview polish  
2. **Targeting rules** — highest perceived Pro value  
3. **Analytics MVP** — justifies subscription  
4. **Triggers** — scroll / exit / product-page overrides  
5. **Content types** — viewing + reviews + CTA  
6. **Agency ops** — export, white-label, license  

---

## Free stays free (do not remove)

- Real / demo / mix sales toasts  
- Consent + hide names  
- Basic design colors  
- Timing presets + gap + jitter  
- Pop sound toggle  
- Session mute / max per session  
- Admin live preview + support form  

## Pro (gated)

- Custom CSS  
- Advanced targeting  
- Statistics / analytics  

---

## Open questions

- License model: annual site license vs Freemium + Pro plugin?  
- Analytics storage: WP options/custom table vs external?  
- Geo: free IP DB vs paid API?  
- Review source: Woo product reviews only, or external later?

---

*Last updated: planning sketch — not implemented.*
