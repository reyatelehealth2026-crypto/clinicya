# ADR 0003: Prefer LINE Reply Then Push Fallback

## Status

Inferred, needs confirmation.

## Context

`webhook.php::sendMessageWithFallback()` and `classes/LineAPI.php::sendMessage()` try LINE reply token delivery first and fall back to push messaging when reply fails or is unavailable. Comments explicitly state reply is quota-friendly/free and push counts against quota.

## Decision

Outbound webhook responses should prefer `replyMessage` when a reply token is available, then fall back to `pushMessage`.

## Consequences

- Reduces push quota usage for normal webhook replies.
- Reply token failures still reach users through push when `line_user_id` is known.
- Logs should identify fallback cases because they affect quota and timing.

## Evidence

- `webhook.php`: `sendMessageWithFallback`.
- `classes/LineAPI.php`: `replyMessage`, `pushMessage`, `sendMessage`.
- `cron/send_scheduled.php`: uses push for scheduled sends where no reply token exists.

## Last Verified From Code

Verified on 2026-07-03 from `webhook.php`, `classes/LineAPI.php`, `cron/send_scheduled.php`.
