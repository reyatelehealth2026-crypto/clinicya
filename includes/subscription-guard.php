<?php
/**
 * Subscription guard helpers for re-ya.com multi-tenant pharmacy SaaS.
 *
 * Functions:
 *   subscriptionGate()            – resolves billing state + produces banner HTML
 *   renderSubscriptionBanner()    – echo the banner if non-empty
 *   requireActiveSubscription()   – SOFT guard: surfaces banner when past_due (non-blocking)
 */

declare(strict_types=1);

require_once __DIR__ . '/../classes/TenantContext.php';

if (!function_exists('subscriptionGate')) {
    /**
     * Resolve the current tenant's subscription state and produce a Thai-language
     * Tailwind banner string.
     *
     * @return array{state: string, locked: bool, banner_html: string}
     */
    function subscriptionGate(): array
    {
        $safeDefault = ['state' => 'no_subscription', 'locked' => false, 'banner_html' => ''];

        try {
            $tid = TenantContext::getCurrentTenantId();
            if ($tid === null) {
                return $safeDefault;
            }

            require_once __DIR__ . '/platform-billing-helpers.php';

            /** @var \PDO $platformDb */
            $platformDb = Database::platform()->getConnection();

            $state = subscriptionState($platformDb, (int) $tid);

            $locked = ($state['state'] === 'past_due');

            $bannerHtml = '';

            switch ($state['state']) {
                case 'trial':
                    $days = isset($state['days_remaining']) ? (int) $state['days_remaining'] : 0;
                    $bannerHtml = sprintf(
                        '<div class="w-full bg-amber-100 border border-amber-400 text-amber-800 px-4 py-2 text-sm flex items-center justify-between">'
                        . '<span>ทดลองใช้ — เหลือ <strong>%d วัน</strong></span>'
                        . '<a href="/billing.php" class="ml-4 underline font-medium hover:text-amber-900">อัปเกรดเลย</a>'
                        . '</div>',
                        $days
                    );
                    break;

                case 'past_due':
                    $bannerHtml = '<div class="w-full bg-red-100 border border-red-500 text-red-800 px-4 py-2 text-sm flex items-center justify-between">'
                        . '<span>หมดอายุการใช้งาน — กรุณาชำระเงินเพื่อใช้งานต่อ</span>'
                        . '<a href="/billing.php" class="ml-4 underline font-medium hover:text-red-900">ชำระเงิน</a>'
                        . '</div>';
                    break;

                case 'active':
                    if (isset($state['days_remaining']) && $state['days_remaining'] <= 7) {
                        $days = (int) $state['days_remaining'];
                        $bannerHtml = sprintf(
                            '<div class="w-full bg-amber-50 border border-amber-300 text-amber-700 px-4 py-2 text-sm">'
                            . 'ครบกำหนดชำระใน <strong>%d วัน</strong>'
                            . '</div>',
                            $days
                        );
                    }
                    break;

                default:
                    // 'no_subscription' or unknown — no banner
                    break;
            }

            return [
                'state'       => $state['state'],
                'locked'      => $locked,
                'banner_html' => $bannerHtml,
            ];
        } catch (\Throwable $e) {
            // Never fatal — degrade silently
            return $safeDefault;
        }
    }
}

if (!function_exists('renderSubscriptionBanner')) {
    /**
     * Echo the subscription banner HTML if the current tenant has one.
     * Safe to call on every page; outputs nothing when no banner is due.
     */
    function renderSubscriptionBanner(): void
    {
        $gate = subscriptionGate();
        if ($gate['banner_html'] !== '') {
            echo $gate['banner_html'];
        }
    }
}

if (!function_exists('requireActiveSubscription')) {
    /**
     * SOFT subscription guard (v1 — intentionally non-blocking).
     *
     * When the tenant's subscription is past_due and the current page is NOT
     * billing.php, this function echoes the billing banner so the user sees a
     * prompt to pay.
     *
     * Hard-gating specific features (e.g. disabling buttons, returning 403, or
     * blocking page rendering entirely) is the CALLER's responsibility.  This
     * function only surfaces the banner; it never redirects, exits, or throws.
     *
     * @param string $billingUrl URL of the billing page (used as fallback reference only).
     */
    function requireActiveSubscription(string $billingUrl = '/billing.php'): void
    {
        $gate = subscriptionGate();

        if (!$gate['locked']) {
            return;
        }

        $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($currentPage === 'billing.php') {
            return;
        }

        if ($gate['banner_html'] !== '') {
            echo $gate['banner_html'];
        }
    }
}
