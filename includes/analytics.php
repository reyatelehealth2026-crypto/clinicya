<?php
/**
 * Plausible Analytics — privacy-friendly tracking (no cookies, no PII)
 *
 * Currently using Plausible standard CDN directly. Some users with strict
 * ad-blockers will be missed (~10-20%) but server-side proxy doesn't work
 * because Plausible doesn't trust X-Forwarded-For from arbitrary IPs.
 *
 * TODO (Step B): swap to Cloudflare Worker proxy at /_pa/* for 100% capture.
 * Worker runs on Cloudflare edge IPs which Plausible trusts.
 *
 * To disable on a specific page: set $disableAnalytics = true before include.
 */
if (!empty($disableAnalytics)) {
    return;
}
?>
<!-- Plausible Analytics (standard CDN) -->
<script defer data-domain="re-ya.com" src="https://plausible.io/js/script.outbound-links.tagged-events.js"></script>
