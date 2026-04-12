# LINE Mini App — UAT script (B4 + G2–G8 smoke) / G8

**Environment:** Staging LIFF + `NEXT_PUBLIC_*` + DB `line_accounts` / `shop_settings` aligned.

## Devices

- Android LINE, iOS LINE (minimum one physical device each for release).

## Script (record Expected / Actual)

1. **Open LIFF** from OA / rich menu → home loads.
2. **G2 Shop:** search + category chip → list updates; add to cart.
3. **Cart:** ± qty, remove line; proceed to checkout.
4. **G3 Transfer:** on checkout, verify **bank rows + copy** + PromptPay QR; **COD** path shows **no** bank account block.
5. **Order:** confirm → **G5** customer receives LINE text (transfer: bank + link; COD: confirmation) + staff Telegram/NotificationService as configured.
6. **Order detail:** `/order/{id}` — transfer pending shows **QR + bank block** + slip upload.
7. **G4 Deep link:** tap Flex / OA button built as `https://liff.line.me/{LIFF_ID}/order/{id}` → opens correct order.
8. **G1 Register:** `/register` completes or **ข้ามไปช้อปก่อน** works.
9. **Phase D routes:** `/notifications`, `/wishlist`, `/appointments`, `/video`, `/health` render placeholder without crash.

## Evidence

Screenshots or screen recording + version / commit hash attached to release ticket.

## Exit criteria

No P0/P1 against core journey; P2 listed or backlog.
