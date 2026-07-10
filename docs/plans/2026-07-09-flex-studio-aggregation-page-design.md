# Flex Studio — Aggregate + Per-Shop Theme + Real-Data Preview

**Date:** 2026-07-09
**Branch:** claude/flex-aggregation-page-husepk
**Status:** Draft design — ready for review

## Problem

Flex messages that customers receive are produced by **~17 features across 7 domains**
(order receipts, dispense medicine labels, points/rewards, broadcasts, 7 reminder crons,
AI/consult cards, etc.). There is **no single place** for a pharmacy owner to see everything
the system sends, and no way to adjust the look **per shop** (`line_account_id`).

A drag-drop builder (`flex-builder.php`), a preview renderer (`assets/js/flex-preview.js`),
and a `flex_templates` table (already keyed by `line_account_id`) **already exist** — but
they are disconnected from real sends. Every Flex that actually ships is **hardcoded in
`classes/FlexTemplates.php`** and never reads `flex_templates`. So today, editing a template
in the builder has **zero effect** on the receipt a customer actually gets.

**The real work is closing that gap**, not building another builder.

## Flex Producer Inventory (source of truth)

| Domain | Features that send Flex | Key files |
|---|---|---|
| Bot / Menu | welcome, main menu, quick menu, first-message menu, LIFF menu | `classes/BusinessBot.php`, `classes/FlexTemplates.php` |
| Commerce / Order | product card/carousel, cart summary, checkout receipt, order status, slip received | `api/checkout.php`, `FlexTemplates::receipt/orderStatus/slipReceived` |
| Pharmacy | medicine label (single) + carousel (dispense) | `inbox-v2.php`, `messages.php`, `FlexTemplates::medicineLabel(sCarousel)` |
| Loyalty | points receipt, reward card, member card, referral, points-claim | `FlexTemplates::pointsReceipt`, `api/points-claim.php`, `webhook.php` |
| Broadcast / Marketing | product broadcast, promo card, scheduled/queue | `api/broadcast.php`, `cron/process_broadcast_queue.php`, `cron/send_scheduled.php` |
| Reminders (cron) | medication, refill, appointment, reward-expiry, restock, wishlist, daily-summary | `cron/medication_reminder.php`, `cron/medication_refill_reminder.php`, `cron/appointment_reminder.php`, `cron/reward_expiry_reminder.php`, `cron/restock_notification.php`, `cron/wishlist_notification.php`, `cron/send-daily-summary.php` |
| AI / Consult / Other | AI Studio flex, AIChat product/pharmacy cards, LIFF bridge, Odoo order/invoice, POS receipt, auto-reply | `classes/AiStudioFlex.php`, `modules/AIChat/Templates/*`, `api/liff-bridge.php`, `classes/OdooFlexTemplates.php`, `classes/POSReceiptService.php`, `auto-reply.php` |

## What already exists (do NOT rebuild)

- `flex-builder.php` (1,648 lines) — drag-drop + JSON editor
- `assets/js/flex-preview.js` (`FlexPreview.render`) — visual preview
- Table `flex_templates` (`line_account_id`, `name`, `category`, `flex_json` JSON-validated)
- Table `shared_flex_messages` (share codes)
- `api/ai-studio-flex.php` + `classes/AiStudioFlex.php` — AI-generated flex

## Decisions (locked)

1. **New page = Flex Studio** (`flex-studio.php`), a gallery + theme settings surface — not
   another builder. Reuses `flex-preview.js` and, for deep edits, links into the existing
   `flex-builder.php`.
2. **Per-shop customization = hybrid of two layers**:
   - **Brand Tokens** (broad, low effort) — colors / logo / shop name / address / footer /
     corner style, applied to *all* Flex at once via a token layer in `FlexTemplates`.
   - **Slot Registry override** (deep, per-template) — a shop can override one specific slot
     with custom JSON from `flex_templates`, keyed by `slot_key`. Fallback is always the
     hardcoded template, so migration is incremental and safe.
3. **Preview uses real data.** `api/flex-preview.php` pulls the shop's latest real record
   (order / dispense / points…) and calls the **same** render path used for real sends — the
   preview is what the customer actually gets, not a mock.

## Architecture

```
Flex Studio (flex-studio.php)
├── [Gallery]  ~25 slots × real-data preview per shop
├── [Theme]    Brand Tokens — edit once, affects every slot
└── [Override] per-slot deep edit → flex-builder.php bound to slot_key

Render gateway (single entry, used by producers AND preview):
  FlexTemplates::render($slotKey, $vars, $lineAccountId)
    1. active override JSON in flex_templates for (line_account_id, slot_key)?
         → substitute {{vars}} into stored JSON
    2. else → hardcoded FlexTemplates method, themed via getTokens($lineAccountId)
```

### Layer 1 — Brand Tokens
`FlexTemplates::getTokens($lineAccountId)` merges: hardcoded defaults ← `shop_settings`
← new `flex_brand_settings` row. Hardcoded constants in the templates (`#06C755`, shop name,
sender icon, footer text, corner radius) are replaced with token reads. One edit re-themes
receipts, medicine labels, points, and reminders together without touching layout.

### Layer 2 — Slot Registry
`flex_templates` gains `slot_key` + `is_active`. Producers stop calling template methods
directly and go through `FlexTemplates::render()`. If an active override exists for the
`(line_account_id, slot_key)` pair, its JSON is used with `{{variable}}` substitution;
otherwise the hardcoded method runs (through the token layer). **Fallback-to-hardcode makes
this non-breaking** — slots migrate one at a time.

### Layer 3 — Real-Data Preview
`api/flex-preview.php?slot=order_receipt` resolves the shop's latest real sample row, calls
`FlexTemplates::render()` (identical to production), and returns the Flex JSON for
`flex-preview.js`. Guarantees WYSIWYG fidelity.

## Slot Catalog (~25)

| Group | slot_key |
|---|---|
| Bot/Menu | `welcome` · `main_menu` · `quick_menu` · `liff_menu` |
| Order | `order_receipt` · `order_status` · `slip_received` · `cart_summary` |
| Product | `product_card` · `product_carousel` · `promo_card` |
| Pharmacy | `medicine_label` · `medicine_label_carousel` |
| Loyalty | `points_receipt` · `reward_card` · `member_card` · `referral_card` |
| Reminder | `rmd_medication` · `rmd_refill` · `rmd_appointment` · `rmd_reward_expiry` · `rmd_restock` · `rmd_wishlist` |
| Other | `notification` · `odoo_order` · `pos_receipt` |

## Components

| Piece | File | Writes DB? |
|---|---|---|
| 🆕 Flex Studio page | `flex-studio.php` | ⚙️ theme/override save only |
| 🆕 Real-data preview API | `api/flex-preview.php` | ❌ read-only |
| 🆕 Migration | `database/migration_2026-07-09_flex_studio.sql` | schema |
| 🔌 Token layer + render gateway | `classes/FlexTemplates.php` (`getTokens`, `render`) | reads |
| 🔌 Preview renderer (reuse) | `assets/js/flex-preview.js` | ❌ |
| 🔌 Deep edit (reuse) | `flex-builder.php` (bind `slot_key`) | existing save |

## Data model changes

```sql
-- new: per-shop brand tokens
CREATE TABLE IF NOT EXISTS flex_brand_settings (
  line_account_id INT NOT NULL PRIMARY KEY,
  primary_color   VARCHAR(9)  DEFAULT NULL,
  accent_color    VARCHAR(9)  DEFAULT NULL,
  logo_url        VARCHAR(500) DEFAULT NULL,
  sender_icon_url VARCHAR(500) DEFAULT NULL,
  shop_display_name VARCHAR(255) DEFAULT NULL,
  footer_text     VARCHAR(500) DEFAULT NULL,
  corner_style    VARCHAR(20)  DEFAULT NULL,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- extend existing flex_templates for slot binding
ALTER TABLE flex_templates
  ADD COLUMN slot_key  VARCHAR(64) DEFAULT NULL,
  ADD COLUMN is_active TINYINT(1)  DEFAULT 0,
  ADD KEY idx_flex_slot (line_account_id, slot_key, is_active);
```
Add `!database/migration_2026-07-09_flex_studio.sql` to `.gitignore` whitelist (CLAUDE.md).

## Phases

- **Phase 0** — migration (`flex_brand_settings` + `flex_templates.slot_key/is_active`) + whitelist entry.
- **Phase 1** — Flex Studio Gallery + real-data preview (read-only). Fast value, no risk to sends.
- **Phase 2** — Brand Tokens: refactor `FlexTemplates` to read `getTokens()`; theme settings tab.
- **Phase 3** — `render()` gateway + per-slot override, migrated slot-by-slot starting with the
  highest-visibility ones (`order_receipt`, `medicine_label`).

## Guardrails (from CLAUDE.md)

- **Tenant isolation** — every token/override query scoped to `line_account_id` inside the
  tenant DB; no cross-shop leakage.
- **Odoo gate** — `odoo_*` slots only render/appear when `$isOdooMode` is true.
- **Carousel rule** — dispense auto-switches to `medicineLabelsCarousel` when `count > 1`;
  the gateway must preserve this.
- **Admin page template** — `flex-studio.php` follows the standard `config` → `header` →
  logic → `footer` pattern; nav links via `cleanUrl()`.
- **No new UI in `products.php`** (it's a redirect); this is a standalone admin page.
- Same-page POST AJAX gated on `X-Requested-With`, following `inbox-v2.php` convention.

## Known limitations (v1, shipped)

- **Overrides are static in v1.** `render()` call sites currently pass `$vars = []`, so a
  saved override with `{{placeholder}}` tokens ships/previews the literal token. Static
  designs (welcome, promo, menu, receipts-as-fixed-layout) work fully; variable-driven slots
  (e.g. per-item medicine label) should use the default template until a per-slot `$vars` map
  is wired. Substitution machinery (`FlexTemplates::substituteVars`) is in place for that next step.
- **Auto-theming uses ambient context.** `toMessage()` themes to the last `useAccount()` shop.
  Within a single web request this is always one tenant; long-lived multi-tenant loops must
  call `useAccount()` (or `render()`) per tenant before building Flex.

## Open questions

- Should Brand Tokens reuse existing `shop_settings` columns where they overlap (tax name,
  address, logo) instead of duplicating in `flex_brand_settings`? Leaning yes — tokens table
  holds only Flex-specific styling, everything else reads through `shop_settings`.
- Variable-substitution syntax for overrides: `{{var}}` vs LINE's own — confirm no clash with
  Flex JSON.
