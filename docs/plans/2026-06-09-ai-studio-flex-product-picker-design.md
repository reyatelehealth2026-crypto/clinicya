# AI Studio Flex — Real-Product Picker (Hybrid Builder)

**Date:** 2026-06-09
**Branch:** reconcile/prod-plus-fixes-20260602
**Status:** Approved design — ready to implement

## Problem

`ai-chat.php?tab=studio` → "ออกแบบ Flex" generates LINE Flex via Gemini from a free-text
prompt only. Product name, **price**, and image are invented by the model — prices can be
wrong, which is unacceptable for a pharmacy storefront.

The `/broadcast` + `/update-products` skills already solve the *data* half well:
real products (name / price / image / stock / SKU) and a clean **2-products-per-bubble**
deterministic Flex layout (`build-flex-2up.cjs`). The platform also already stores those
products and exposes them through `api/shop-products.php` (search + pagination, source
auto-selected per tenant: `business_items` / `cny_products` / `shop_products`).

The gap is a **convenient product picker**. We bring over only the *good parts* of the
skills (real-product selection + the 2-up layout). We do **not** port the auto-broadcast
submit pipeline (cookie / cron / `inbox.re-ya.com`).

## Decisions (locked)

1. **Picker = separate read-only page**, opened as a popup from both AI Studio Flex and
   Flex Builder. Reads `api/shop-products.php` only. **No new DB tables, no migration, no
   writes to the system.**
2. **Generation = Hybrid.** Structure + price/SKU/image are deterministic (locked from
   DB). Gemini writes *only* the marketing copy (title / intro / CTA / badge / footer /
   closing). AI can never touch a number.
3. **Theme colors** expanded from 6 → ~18 swatches + custom hex picker.
4. **AI Refinement** section kept; made price-safe (see below).
5. **Reference image upload** raised to **10** (backend `MAX_REFS` is already 10).

## Components

| Piece | File | Writes DB? |
|---|---|---|
| 🆕 Product picker page | `product-picker.php` | ❌ read-only |
| 🆕 Flex product builder (JS) | `assets/js/flex-product-builder.js` | ❌ client-side |
| 🔌 AI copy mode | `api/ai-studio-flex.php` (`mode:'copy'`) + `classes/AiStudioFlex.php` | ❌ Gemini only |
| 🔌 AI Studio Flex tab | `includes/ai-chat/studio.php` | ❌ |
| 🔌 Flex Builder | `flex-builder.php` | ❌ (uses existing template save only) |

## Data flow

```
[เลือกสินค้า] → window.open('product-picker.php?return=studio')
  picker: GET api/shop-products.php?action=products&search=&page=
          → grid (รูป + ชื่อ + รหัส/SKU + ราคา), multi-select basket
          → "ยืนยัน" → window.opener.postMessage({type:'reya:products', products:[...]}, origin)
          → window.close()   (fallback: sessionStorage + navigate back)
parent: receive message → JS state `studioPickedProducts`
        → chips "เลือกแล้ว N ชิ้น"
[ให้ AI เขียนคำโปรย] (optional) → POST mode:'copy' → {title,intro,ctaLabel,badgeText,footerText,closingText}
[สร้าง Flex] → FlexProductBuilder.build({products, copy, theme, layout}) → FlexPreview.render
```

### Picker return shape (normalized client-side by `product_source`)

```js
{ sku, name, image, basePrice, promotionPrice, unit, url }
```

| field | business_items | cny_products | shop_products |
|---|---|---|---|
| basePrice | `price` | `product_price[].price` | `price` |
| promotionPrice | `sale_price` (if `< price`) | `product_price[].promotion_price` | `sale_price` |
| image | `image_url` | `MANAGER + photo_path` | `image_url` |
| unit | `unit` | `unit` | `unit` |
| url | shop detail / fallback | `cnypharmacy.com/product/{sku}` | shop detail |

## AI copy mode (`api/ai-studio-flex.php`)

Request:
```json
{ "mode":"copy", "type":"promo", "theme":"promotion",
  "product_names":["ซีฟอร์ซ-1000", ...], "hint":"โปรลดราคาสิ้นเดือน" }
```
Response (text-only — no numbers):
```json
{ "success":true, "copy":{
  "title":"...", "intro":"...", "ctaLabel":"...",
  "badgeText":"...", "footerText":"...", "closingText":"..." }}
```
New `AiStudioFlex` helpers: `buildCopySystemPrompt()`, `parseCopyJson()` (whitelists the 6
fields), `generateCopy()` (reuses the existing HTTP transport).

## Flex product builder (`assets/js/flex-product-builder.js`)

Direct port of `build-flex-2up.cjs`:
- `FlexProductBuilder.build({ products, copy, theme, color?, layout })`
- `layout: '2up'` → 1 cover bubble + ⌈N/2⌉ bubbles (2 products each, separator between).
  `layout: '1up'` → 1 product per bubble.
- Output: `{ type:'carousel', contents:[...] }` (single bubble when N=1).
- Price / promo-strikethrough / unit / SKU / hero image come straight from `products`.
- `theme` → color+icon map (`promotion #E53E3E 🔥`, `flash_sale #D69E2E ⚡`,
  `bestseller #15803D 🏆`, `new_arrival #805AD5 ✨`, `product_catalog #4299E1 🛍️`);
  an explicit `color` (user swatch) overrides the theme color.

## AI Refinement (price-safe)

The existing instruction box stays. Behaviour branches on whether products are selected:
- **Products selected** → instruction is sent to copy-regeneration; the Flex is then
  **rebuilt deterministically** so price/SKU/image stay locked.
- **No products (pure-AI flex)** → unchanged (`mode:'edit'`, edits the whole object).

## Out of scope

- Auto-broadcast submission (cookie / cron / `inbox.re-ya.com`).
- Any DB schema change or new persisted state.
- New product sync logic (uses the existing `api/shop-products.php`).

## Verification

- `composer lint` (PSR-12) on touched PHP.
- `node --check assets/js/flex-product-builder.js`.
- Manual: pick products in both pages → preview renders with real prices → AI copy →
  refine → "ใช้ใน Broadcast" handoff still works.
