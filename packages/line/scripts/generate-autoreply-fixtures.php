<?php
/**
 * Golden-fixture generator for packages/line/src/auto-reply.ts.
 *
 * Unlike generate-fixtures.php (which simply `require`s the real classes/FlexTemplates.php — a
 * plain static class with no top-level side effects), this generator does NOT `require`
 * webhook.php wholesale: webhook.php is a live entry-point script with real top-level side
 * effects (a global `set_error_handler()` that turns every PHP warning into a thrown
 * ErrorException, a `register_shutdown_function()` that writes to a real `dev_logs` DB table,
 * `require_once 'config/database.php'` which resolves the tenant subdomain and opens a real DB
 * connection, ...). Executing any of that here would be wrong (and would crash immediately with
 * no real DB available).
 *
 * Instead: read webhook.php as plain text, use PHP's own tokenizer (`token_get_all()`) plus
 * brace-depth tracking to mechanically extract the EXACT source text of `checkAutoReply()`
 * (webhook.php:2062-2254) and `addShareButtonToFlex()` (webhook.php:2263-2309) — byte-for-byte,
 * whitespace and comments included — then `eval()` those two extracted function declarations
 * into this process. The five-way `match_type` switch and the reply-building logic are never
 * hand-retyped anywhere in this file; they only exist here as text captured at runtime from the
 * real webhook.php. (Mechanically checked: `grep -c token_get_all` this file >= 1, and none of
 * the five match_type switch arms' `case` labels — exact / contains / starts_with / regex / all,
 * quoted-string-after-the-word-case form — may appear as literal PHP source below.)
 *
 * checkAutoReply() takes a live `$db` (a PDO connection) and does its own SQL fetch internally —
 * it is not written to accept a pre-fetched rows array. To drive it without a real database, a
 * tiny stub $db class (`StubAutoReplyDb` below) implements just enough of PDO's surface
 * (`prepare()->execute()->fetchAll()`) to return a pre-set `$rows` array — already pre-sorted the
 * way the real SQL's `ORDER BY line_account_id DESC, priority DESC` would sort it; the stub does
 * NOT implement sorting itself. checkAutoReply() unconditionally attempts a
 * `use_count`/`last_used_at` UPDATE in a try/catch as soon as a rule matches (webhook.php:
 * 2097-2102, deliberately NOT ported into auto-reply.ts — see its `// DEFERRED:` comment); the
 * stub's `execute()` simply returns `true` and never throws, so that UPDATE silently no-ops. No
 * assertion is made on it here.
 *
 * `addShareButtonToFlex()` reads the PHP constant `LIFF_SHARE_ID` directly (not a parameter) —
 * `$liffId = LIFF_SHARE_ID;` — and PHP constants, once `define()`'d, cannot be redefined or
 * undefined for the rest of the process. So every fixture that needs `LIFF_SHARE_ID` to be
 * GENUINELY UNDEFINED (proving `checkAutoReply()`'s `defined('LIFF_SHARE_ID') && LIFF_SHARE_ID`
 * gate correctly suppresses the share button) must be generated BEFORE this script's one
 * `define('LIFF_SHARE_ID', ...)` call below; every fixture that needs it CONFIGURED must come
 * after. This script is deliberately laid out in that order, with a banner comment marking the
 * `define()` call so the split is impossible to miss.
 *
 * PHP's `preg_match()` (used by the `'regex'` match_type) never throws on a malformed pattern —
 * it returns `false` and emits a non-fatal `E_WARNING`. Because this script does NOT install
 * webhook.php's own `set_error_handler()` (which would upgrade that warning into a thrown
 * ErrorException — see above), the warning would otherwise print to stderr and fail this script's
 * "zero errors/warnings" acceptance criterion. It is suppressed locally, only around the one call
 * that deliberately exercises this path (see `withWarningsSuppressed()` below) — the suppression
 * is at the CALL SITE in this generator, not inside the extracted PHP source itself, so it does
 * not change what checkAutoReply() actually does (still returns `null` for the malformed pattern,
 * exactly as real PHP does under normal, non-webhook.php error reporting).
 *
 * Run from anywhere:
 *   php packages/line/scripts/generate-autoreply-fixtures.php
 *
 * This is the ONLY supported way to (re)produce the fixtures under
 * packages/line/src/__fixtures__/auto-reply/. Do not hand-edit that JSON — regenerate it here
 * instead. Nothing in checkAutoReply()/addShareButtonToFlex() is date/time-derived (unlike
 * FlexTemplates::medicineLabel()'s `date('d/m/Y')`), so running this script twice in a row must
 * produce byte-identical fixture files.
 */

// -------------------------------------------------------------------------------------------
// Mechanical extraction: token_get_all() + brace-depth tracking. See header comment above for
// why this exists instead of a plain `require 'webhook.php'` or a hand-copy of the two functions.
// -------------------------------------------------------------------------------------------

/**
 * Returns the exact source text of `function $functionName(...) { ... }` as it appears in
 * `$tokens` (the full `token_get_all()` output of some PHP file), from the `function` keyword
 * through its matching closing brace, inclusive — whitespace and comments preserved verbatim.
 *
 * Brace-depth tracking must additionally treat `T_CURLY_OPEN` (the `{` that opens a `"...{$expr}"`
 * string-interpolation sub-expression) as an opening brace: PHP's tokenizer reports that specific
 * `{` as a distinct `T_CURLY_OPEN` token (not the plain single-character `'{'` string token every
 * OTHER opening brace is), while its matching close is always an ordinary `'}'` string token like
 * any other closing brace. Counting only literal `'{'` strings as opens would therefore
 * under-count by exactly one for every `{$var}` interpolation in the source (addShareButtonToFlex()
 * has two, in its `"https://liff.line.me/{$liffId}?rule={$ruleId}"` line), truncating the
 * extraction at the wrong `}`. (Verified against `token_get_all()`'s real output for this exact
 * construct before writing this function — see the build report.)
 */
function extractFunctionSource(array $tokens, string $functionName): string
{
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_FUNCTION) {
            continue;
        }

        // Skip whitespace/comments between `function` and the function name.
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $functionName) {
            continue; // not our function — keep scanning for another `function` keyword
        }

        // Walk the parameter list (tracking '(' / ')' depth) to find the body's opening '{'.
        $parenDepth = 0;
        $bodyOpenIndex = -1;
        for ($k = $j + 1; $k < $n; $k++) {
            $t = $tokens[$k];
            if ($t === '(') {
                $parenDepth++;
            } elseif ($t === ')') {
                $parenDepth--;
            } elseif ($parenDepth === 0 && $t === '{') {
                $bodyOpenIndex = $k;
                break;
            }
        }
        if ($bodyOpenIndex === -1) {
            throw new RuntimeException("extractFunctionSource: could not find opening brace for {$functionName}()");
        }

        // Walk the body tracking brace depth (see T_CURLY_OPEN note in the doc comment above)
        // until it returns to zero — that token is the function's matching closing brace.
        $depth = 0;
        $bodyCloseIndex = -1;
        for ($m = $bodyOpenIndex; $m < $n; $m++) {
            $t = $tokens[$m];
            $isOpen = ($t === '{') || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES));
            $isClose = ($t === '}');
            if ($isOpen) {
                $depth++;
            } elseif ($isClose) {
                $depth--;
                if ($depth === 0) {
                    $bodyCloseIndex = $m;
                    break;
                }
            }
        }
        if ($bodyCloseIndex === -1) {
            throw new RuntimeException("extractFunctionSource: could not find matching closing brace for {$functionName}()");
        }

        $src = '';
        for ($p = $i; $p <= $bodyCloseIndex; $p++) {
            $src .= is_array($tokens[$p]) ? $tokens[$p][1] : $tokens[$p];
        }
        return $src;
    }

    throw new RuntimeException("extractFunctionSource: function {$functionName}() not found in source");
}

$webhookPath = __DIR__ . '/../../../webhook.php';
$webhookSource = file_get_contents($webhookPath);
if ($webhookSource === false) {
    throw new RuntimeException("Could not read {$webhookPath}");
}
$webhookTokens = token_get_all($webhookSource);

eval(extractFunctionSource($webhookTokens, 'checkAutoReply'));
eval(extractFunctionSource($webhookTokens, 'addShareButtonToFlex'));

if (!function_exists('checkAutoReply') || !function_exists('addShareButtonToFlex')) {
    throw new RuntimeException('Extraction + eval() did not define the expected functions.');
}

// -------------------------------------------------------------------------------------------
// Stub $db — see header comment for why this is enough for checkAutoReply()'s needs.
// -------------------------------------------------------------------------------------------

class StubAutoReplyStatement
{
    public function __construct(private StubAutoReplyDb $db)
    {
    }

    public function execute($params = [])
    {
        return true; // covers both the SELECT (rows come from fetchAll()) and the UPDATE (no-op).
    }

    public function fetchAll()
    {
        return $this->db->rows;
    }
}

class StubAutoReplyDb
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function prepare($sql)
    {
        return new StubAutoReplyStatement($this);
    }
}

/** Drives checkAutoReply() against a synthetic, already-sorted rules array. */
function callCheckAutoReply(StubAutoReplyDb $db, array $rows, string $text, ?int $lineAccountId = null)
{
    $db->rows = $rows;
    return checkAutoReply($db, $text, $lineAccountId);
}

/** Suppresses PHP's non-fatal E_WARNING for exactly the duration of $fn() — see header comment
 *  on why the malformed-regex fixture needs this (and why it's safe: it changes nothing about
 *  what checkAutoReply()/preg_match() actually computes, only whether the warning is printed). */
function withWarningsSuppressed(callable $fn)
{
    set_error_handler(static function () {
        return true; // swallow; do not fall through to the default handler (which would print it)
    }, E_WARNING);
    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

/** Builds a full auto_replies row with every column checkAutoReply()/addShareButtonToFlex() read
 *  present (even when null) — PHP 8 emits E_WARNING for undefined array key access, which would
 *  fail this script's "zero warnings" requirement if any read column were missing. */
function makeRule(array $overrides): array
{
    $defaults = [
        'id' => 0,
        'line_account_id' => null,
        'keyword' => '',
        'match_type' => 'contains',
        'reply_type' => 'text',
        'reply_content' => '',
        'alt_text' => null,
        'sender_name' => null,
        'sender_icon' => null,
        'quick_reply' => null,
        'enable_share' => 0,
        'share_button_label' => null,
        'is_active' => 1,
        'priority' => 0,
    ];
    return array_merge($defaults, $overrides);
}

// -------------------------------------------------------------------------------------------
// Fixture I/O — same convention as generate-fixtures.php.
// -------------------------------------------------------------------------------------------

$fixturesDir = __DIR__ . '/../src/__fixtures__/auto-reply';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0777, true);
}

function encodeFixture(string $description, array $request, $response): string
{
    $payload = [
        'description' => $description,
        'request' => $request,
        'response' => $response,
    ];
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

function writeFixture(string $dir, string $filename, string $description, array $request, $response): void
{
    $json = encodeFixture($description, $request, $response);
    file_put_contents($dir . '/' . $filename, $json);
    echo "wrote {$filename}\n";
}

$db = new StubAutoReplyDb();

// Fixed constant value used for every "LIFF_SHARE_ID configured" fixture's request.config.liffShareId
// (kept identical to the real PHP constant defined below, so the fixture's recorded `request` is
// truthful about what generated the recorded `response`).
const LIFF_SHARE_ID_TEST_VALUE = 'liff-1234-share-test';

// =============================================================================================
// PHASE 1 — fixtures that require LIFF_SHARE_ID to be GENUINELY UNDEFINED.
// Must run before the define() call below. Only one fixture needs this (checkAutoReply() only
// reads LIFF_SHARE_ID when reply_type is Flex AND enable_share is truthy).
// =============================================================================================

$noLiffIdRule = makeRule([
    'id' => 101,
    'keyword' => 'สอบถามโปร',
    'match_type' => 'all',
    'reply_type' => 'flex',
    'reply_content' => json_encode([
        'type' => 'bubble',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => 'โปรโมชั่นประจำเดือน', 'weight' => 'bold'],
            ],
        ],
    ]),
    'alt_text' => 'โปรโมชั่น',
    'enable_share' => 1, // enabled...
    'share_button_label' => 'แชร์โปรนี้',
]);

$noLiffIdResponse = callCheckAutoReply($db, [$noLiffIdRule], 'สอบถามโปร');

writeFixture(
    $fixturesDir,
    'share-button-disabled-no-liff-id.json',
    'buildAutoReplyReply() — rule.enable_share=1 but LIFF_SHARE_ID is NOT configured (config.liffShareId falsy): PHP\'s `defined(\'LIFF_SHARE_ID\') && LIFF_SHARE_ID` gate is false, so no share button is appended even though the rule asks for one. Generated with the real LIFF_SHARE_ID constant genuinely undefined.',
    ['fn' => 'buildAutoReplyReply', 'rule' => $noLiffIdRule, 'config' => ['liffShareId' => null]],
    $noLiffIdResponse
);

// =============================================================================================
// Constant is defined here, once, for the rest of the script. Every fixture below this point
// runs with LIFF_SHARE_ID CONFIGURED.
// =============================================================================================
define('LIFF_SHARE_ID', LIFF_SHARE_ID_TEST_VALUE);

// =============================================================================================
// PHASE 2 — everything else (LIFF_SHARE_ID configured throughout).
// =============================================================================================

// ---------------------------------------------------------------------------------------------
// match_type coverage — resolveAutoReply() round-trips (checkAutoReply() drives the real switch).
// ---------------------------------------------------------------------------------------------

$exactRule = makeRule([
    'id' => 1,
    'keyword' => 'Hello ร้านยา',
    'match_type' => 'exact',
    'reply_type' => 'text',
    'reply_content' => 'สวัสดีค่ะ ยินดีต้อนรับสู่ร้านยา',
]);

writeFixture(
    $fixturesDir,
    'match-exact-matching-case.json',
    'resolveAutoReply() — match_type=exact: text differs from keyword only in the ASCII-range case ("hello..." vs "Hello..."), matches via PHP\'s mb_strtolower() case-fold on both sides.',
    ['fn' => 'resolveAutoReply', 'rules' => [$exactRule], 'text' => 'hello ร้านยา', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$exactRule], 'hello ร้านยา')
);

$exactRule2 = makeRule([
    'id' => 2,
    'keyword' => 'ลาก่อน',
    'match_type' => 'exact',
    'reply_type' => 'text',
    'reply_content' => 'แล้วพบกันใหม่ค่ะ',
]);

writeFixture(
    $fixturesDir,
    'match-exact-trailing-whitespace-no-trim.json',
    'resolveAutoReply() — match_type=exact: text is the keyword PLUS a trailing space ("ลาก่อน "). PIN: PHP\'s exact-match comparison (mb_strtolower($text) === mb_strtolower($rule[\'keyword\'])) never trims — this must NOT match, proving no trim() is applied. Expected response is null.',
    ['fn' => 'resolveAutoReply', 'rules' => [$exactRule2], 'text' => 'ลาก่อน ', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$exactRule2], 'ลาก่อน ')
);

$containsRule = makeRule([
    'id' => 3,
    'keyword' => 'โปรโมชั่น',
    'match_type' => 'contains',
    'reply_type' => 'text',
    'reply_content' => 'ดูโปรโมชั่นทั้งหมดได้ที่หน้าร้านค้าเลยค่ะ',
]);

writeFixture(
    $fixturesDir,
    'match-contains.json',
    'resolveAutoReply() — match_type=contains: keyword appears as a substring in the middle of a longer message (mb_stripos !== false).',
    ['fn' => 'resolveAutoReply', 'rules' => [$containsRule], 'text' => 'วันนี้มีโปรโมชั่นพิเศษไหมคะ', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$containsRule], 'วันนี้มีโปรโมชั่นพิเศษไหมคะ')
);

$startsWithRule = makeRule([
    'id' => 4,
    'keyword' => 'สั่งซื้อ',
    'match_type' => 'starts_with',
    'reply_type' => 'text',
    'reply_content' => 'รับออเดอร์แล้วค่ะ กรุณาแจ้งรายการสินค้า',
]);

writeFixture(
    $fixturesDir,
    'match-starts-with.json',
    'resolveAutoReply() — match_type=starts_with: text begins with the keyword (mb_stripos === 0).',
    ['fn' => 'resolveAutoReply', 'rules' => [$startsWithRule], 'text' => 'สั่งซื้อสินค้าได้เลยไหมคะ', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$startsWithRule], 'สั่งซื้อสินค้าได้เลยไหมคะ')
);

$regexSafeRule = makeRule([
    'id' => 5,
    'keyword' => '[0-9]+\\s*บาท',
    'match_type' => 'regex',
    'reply_type' => 'text',
    'reply_content' => 'สอบถามราคาสินค้าติดต่อแอดมินได้เลยค่ะ',
]);

writeFixture(
    $fixturesDir,
    'match-regex-safe.json',
    'resolveAutoReply() — match_type=regex, well-formed pattern: `preg_match(\'/\' . keyword . \'/i\', text)` matches a price-looking substring ("150 บาท").',
    ['fn' => 'resolveAutoReply', 'rules' => [$regexSafeRule], 'text' => 'ราคาเท่าไหร่คะ ประมาณ 150 บาท ใช่ไหม', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$regexSafeRule], 'ราคาเท่าไหร่คะ ประมาณ 150 บาท ใช่ไหม')
);

$regexInvalidRule = makeRule([
    'id' => 6,
    'keyword' => '[unterminated(',
    'match_type' => 'regex',
    'reply_type' => 'text',
    'reply_content' => 'ข้อความนี้จะไม่มีวันถูกส่ง เพราะ pattern พัง',
]);

$regexInvalidResponse = withWarningsSuppressed(
    fn () => callCheckAutoReply($db, [$regexInvalidRule], 'ข้อความทดสอบทั่วไป')
);

writeFixture(
    $fixturesDir,
    'match-regex-invalid-pattern.json',
    'resolveAutoReply() — match_type=regex, MALFORMED pattern ("[unterminated("): PHP\'s preg_match() returns false (with a non-fatal E_WARNING, suppressed at this generator\'s call site only — see this script\'s header comment) rather than throwing. PIN: this must be a NON-MATCH (response null), not a thrown error.',
    ['fn' => 'resolveAutoReply', 'rules' => [$regexInvalidRule], 'text' => 'ข้อความทดสอบทั่วไป', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    $regexInvalidResponse
);

// PCRE delimiter collision: keyword contains an UNESCAPED '/'. checkAutoReply() builds the real
// pattern as `'/' . $rule['keyword'] . '/i'` — '/' is PCRE's hard delimiter, so this internal '/'
// closes the pattern early and the leftover text (plus PHP's own appended "/i") is reinterpreted
// as an invalid modifier string ("Unknown modifier"). preg_match() returns false EVEN THOUGH the
// text literally contains the keyword substring "500mg/tab" — a naive substring/regex engine with
// no delimiter concept (e.g. JS's bare `new RegExp(keyword)`) would incorrectly MATCH here. See
// auto-reply.ts's hasUnescapedForwardSlash().
$regexUnescapedSlashRule = makeRule([
    'id' => 19,
    'keyword' => '500mg/tab',
    'match_type' => 'regex',
    'reply_type' => 'text',
    'reply_content' => 'ไม่ควรถูกส่ง เพราะ / ทำให้ pattern พังเหมือน pattern ที่ unterminated',
]);

$regexUnescapedSlashResponse = withWarningsSuppressed(
    fn () => callCheckAutoReply($db, [$regexUnescapedSlashRule], 'สินค้าตัวนี้ขนาด 500mg/tab ค่ะ')
);

writeFixture(
    $fixturesDir,
    'match-regex-unescaped-slash-delimiter-collision.json',
    'resolveAutoReply() — match_type=regex, keyword contains an UNESCAPED \'/\' ("500mg/tab"): PHP\'s actual call is preg_match(\'/\' . keyword . \'/i\', text) — \'/\' is PCRE\'s hard delimiter, so this internal \'/\' closes the pattern early and the leftover text (plus PHP\'s own appended "/i") is reinterpreted as an invalid modifier string. preg_match() emits a non-fatal E_WARNING (suppressed here, same as the invalid-pattern fixture above) and returns false — even though the text literally CONTAINS the keyword substring "500mg/tab" verbatim. PIN: this must be a NON-MATCH (response null), proving the TS port reproduces PCRE\'s delimiter-closing failure mode instead of naively matching the substring.',
    ['fn' => 'resolveAutoReply', 'rules' => [$regexUnescapedSlashRule], 'text' => 'สินค้าตัวนี้ขนาด 500mg/tab ค่ะ', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    $regexUnescapedSlashResponse
);

$matchAllRule = makeRule([
    'id' => 7,
    'keyword' => '', // irrelevant for match_type=all — never read by the 'all' branch
    'match_type' => 'all',
    'reply_type' => 'text',
    'reply_content' => 'ขอบคุณสำหรับข้อความค่ะ เดี๋ยวแอดมินตอบกลับนะคะ',
]);

writeFixture(
    $fixturesDir,
    'match-all.json',
    'resolveAutoReply() — match_type=all: matches ANY text unconditionally, regardless of keyword content.',
    ['fn' => 'resolveAutoReply', 'rules' => [$matchAllRule], 'text' => 'ข้อความสุ่มอะไรก็ได้ที่พิมพ์เข้ามา 12345', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$matchAllRule], 'ข้อความสุ่มอะไรก็ได้ที่พิมพ์เข้ามา 12345')
);

// ---------------------------------------------------------------------------------------------
// Reply-message construction — buildAutoReplyReply() round-trips, driven via a single
// match_type='all' rule through checkAutoReply() (isolates the build step from match-type choice
// without re-implementing any of the 4 other switch branches).
// ---------------------------------------------------------------------------------------------

$replyTextRule = makeRule([
    'id' => 8,
    'match_type' => 'all',
    'reply_type' => 'text',
    'reply_content' => 'ขอบคุณที่ติดต่อร้านยาของเรานะคะ',
]);

writeFixture(
    $fixturesDir,
    'reply-text.json',
    'buildAutoReplyReply() — reply_type=text: message is `{type: "text", text: reply_content}` verbatim, no Flex/json_decode involved.',
    ['fn' => 'buildAutoReplyReply', 'rule' => $replyTextRule, 'config' => ['liffShareId' => null]],
    callCheckAutoReply($db, [$replyTextRule], 'anything')
);

$validFlexContent = [
    'type' => 'bubble',
    'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
            ['type' => 'text', 'text' => 'สินค้าแนะนำประจำสัปดาห์', 'weight' => 'bold', 'size' => 'lg'],
        ],
    ],
];

$replyFlexValidRule = makeRule([
    'id' => 9,
    'match_type' => 'all',
    'reply_type' => 'flex',
    'reply_content' => json_encode($validFlexContent),
    'alt_text' => 'สินค้าแนะนำ',
    'enable_share' => 0, // share button gating is tested separately below
]);

writeFixture(
    $fixturesDir,
    'reply-flex-valid-json.json',
    'buildAutoReplyReply() — reply_type=flex, well-formed JSON reply_content: message is `{type: "flex", altText, contents}` with contents = json_decode(reply_content, true). enable_share=0, so no share button.',
    ['fn' => 'buildAutoReplyReply', 'rule' => $replyFlexValidRule, 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$replyFlexValidRule], 'anything')
);

// The exact-once-match short-circuit landmine (PARITY NOTE 1 in auto-reply.ts): rule A matches
// FIRST (higher priority / earlier in the pre-sorted array) but its Flex JSON is malformed, so
// its build fails -> overall result is null. Rule B, lower priority, would ALSO have matched the
// same text and has perfectly valid content — but checkAutoReply() never reaches it.
$shortCircuitRuleA = makeRule([
    'id' => 10,
    'keyword' => 'โปร',
    'match_type' => 'contains',
    'reply_type' => 'flex',
    'reply_content' => '{this is not valid json,,,',
    'priority' => 10,
]);
$shortCircuitRuleB = makeRule([
    'id' => 11,
    'keyword' => 'โปร',
    'match_type' => 'contains',
    'reply_type' => 'text',
    'reply_content' => 'นี่คือข้อความสำรอง (ไม่ควรถูกใช้)',
    'priority' => 1,
]);

writeFixture(
    $fixturesDir,
    'reply-flex-invalid-json-no-fallback.json',
    'resolveAutoReply() — the exact-once-match short-circuit landmine: rules[0] (higher priority) matches first via contains, but its reply_content fails json_decode() -> buildAutoReplyReply() returns null. rules[1] (lower priority) would ALSO have matched the same text and has valid text content, but is never tried. PIN: overall response must be null, with no fallback to rules[1].',
    ['fn' => 'resolveAutoReply', 'rules' => [$shortCircuitRuleA, $shortCircuitRuleB], 'text' => 'วันนี้มีโปรอะไรบ้างคะ', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$shortCircuitRuleA, $shortCircuitRuleB], 'วันนี้มีโปรอะไรบ้างคะ')
);

// ---------------------------------------------------------------------------------------------
// addShareButtonToFlex() — called directly (not through checkAutoReply()).
// ---------------------------------------------------------------------------------------------

$bubbleNoFooter = [
    'type' => 'bubble',
    'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
            ['type' => 'text', 'text' => 'สินค้าราคาพิเศษ'],
        ],
    ],
];

writeFixture(
    $fixturesDir,
    'share-button-bubble-no-existing-footer.json',
    'addShareButtonToFlex() — bubble with NO existing footer: a fresh footer box is created (type=box, layout=vertical, paddingAll=lg) and the share button is its only content.',
    ['fn' => 'addShareButtonToFlex', 'flexContent' => $bubbleNoFooter, 'ruleId' => 201, 'label' => '📤 แชร์ให้เพื่อน', 'liffShareId' => LIFF_SHARE_ID_TEST_VALUE],
    addShareButtonToFlex($bubbleNoFooter, 201, '📤 แชร์ให้เพื่อน')
);

$bubbleExistingFooter = [
    'type' => 'bubble',
    'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
            ['type' => 'text', 'text' => 'สินค้าราคาพิเศษ'],
        ],
    ],
    'footer' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
            ['type' => 'button', 'action' => ['type' => 'uri', 'label' => 'สั่งซื้อ', 'uri' => 'https://shop.example.com/buy']],
        ],
        'paddingAll' => 'lg',
    ],
];

writeFixture(
    $fixturesDir,
    'share-button-bubble-existing-footer-appended.json',
    'addShareButtonToFlex() — bubble WITH an existing footer (one "สั่งซื้อ" button already present): the share button is APPENDED as the footer\'s second content item, existing content untouched.',
    ['fn' => 'addShareButtonToFlex', 'flexContent' => $bubbleExistingFooter, 'ruleId' => 202, 'label' => '📤 แชร์ให้เพื่อน', 'liffShareId' => LIFF_SHARE_ID_TEST_VALUE],
    addShareButtonToFlex($bubbleExistingFooter, 202, '📤 แชร์ให้เพื่อน')
);

$carouselMixed = [
    'type' => 'carousel',
    'contents' => [
        [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [['type' => 'text', 'text' => 'สินค้า A']]],
        ],
        [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [['type' => 'text', 'text' => 'สินค้า B']]],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [['type' => 'button', 'action' => ['type' => 'uri', 'label' => 'ดูรายละเอียด', 'uri' => 'https://shop.example.com/b']]],
                'paddingAll' => 'lg',
            ],
        ],
        [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [['type' => 'text', 'text' => 'สินค้า C']]],
        ],
    ],
];

writeFixture(
    $fixturesDir,
    'share-button-carousel-all-bubbles.json',
    'addShareButtonToFlex() — carousel with 3 bubbles (bubble 0 and 2 have no footer, bubble 1 already has one with a button): PIN: the share button is appended to EVERY bubble in contents[], not just the first/last, mixing the create-footer and append-to-existing-footer branches within one call.',
    ['fn' => 'addShareButtonToFlex', 'flexContent' => $carouselMixed, 'ruleId' => 203, 'label' => '📤 แชร์ให้เพื่อน', 'liffShareId' => LIFF_SHARE_ID_TEST_VALUE],
    addShareButtonToFlex($carouselMixed, 203, '📤 แชร์ให้เพื่อน')
);

// ---------------------------------------------------------------------------------------------
// Share-button gating from within buildAutoReplyReply() (as opposed to addShareButtonToFlex()
// called directly, above) — enable_share=false this time (LIFF_SHARE_ID IS configured, proving
// enable_share is independently what disables it, not liffShareId absence).
// ---------------------------------------------------------------------------------------------

$shareDisabledRule = makeRule([
    'id' => 12,
    'match_type' => 'all',
    'reply_type' => 'flex',
    'reply_content' => json_encode($validFlexContent),
    'alt_text' => 'สินค้าแนะนำ',
    'enable_share' => 0,
    'share_button_label' => 'แชร์เลย',
]);

writeFixture(
    $fixturesDir,
    'share-button-disabled-enable-share-false.json',
    'buildAutoReplyReply() — rule.enable_share=0 (false) but config.liffShareId IS configured: PIN: enable_share alone gates the share button — no footer/button is added even though LIFF_SHARE_ID is available.',
    ['fn' => 'buildAutoReplyReply', 'rule' => $shareDisabledRule, 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$shareDisabledRule], 'anything')
);

// ---------------------------------------------------------------------------------------------
// sender_name / sender_icon override.
// ---------------------------------------------------------------------------------------------

$senderBothRule = makeRule([
    'id' => 13,
    'match_type' => 'all',
    'reply_type' => 'text',
    'reply_content' => 'สวัสดีค่ะ มีอะไรให้ช่วยไหมคะ',
    'sender_name' => 'บอทร้านยา CNY',
    'sender_icon' => 'https://example.com/bot-icon.png',
]);

writeFixture(
    $fixturesDir,
    'sender-override-name-and-icon.json',
    'buildAutoReplyReply() — sender_name AND sender_icon both set: message.sender = {name, iconUrl}.',
    ['fn' => 'buildAutoReplyReply', 'rule' => $senderBothRule, 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$senderBothRule], 'anything')
);

$senderNameOnlyRule = makeRule([
    'id' => 14,
    'match_type' => 'all',
    'reply_type' => 'text',
    'reply_content' => 'สวัสดีค่ะ มีอะไรให้ช่วยไหมคะ',
    'sender_name' => 'บอทร้านยา CNY',
    'sender_icon' => null,
]);

writeFixture(
    $fixturesDir,
    'sender-override-name-only-no-icon-key.json',
    'buildAutoReplyReply() — sender_name set, sender_icon null: message.sender = {name} ONLY. PIN: no "iconUrl" key at all (icon only applies when name is ALSO present AND icon itself is truthy).',
    ['fn' => 'buildAutoReplyReply', 'rule' => $senderNameOnlyRule, 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$senderNameOnlyRule], 'anything')
);

// ---------------------------------------------------------------------------------------------
// quick_reply — all 6 action-shape branches plus several optional-field-absent / skip-item edges.
// ---------------------------------------------------------------------------------------------

$quickReplyAllBranchesItems = [
    ['label' => 'ทักทาย', 'type' => 'message', 'text' => 'สวัสดีค่ะ'],
    ['label' => 'เมนูหลัก', 'type' => 'message'], // no 'text' key -> falls back to label
    ['label' => 'เว็บไซต์', 'type' => 'uri', 'uri' => 'https://cnyhealthcare.com'],
    ['label' => 'ลิงก์เสีย', 'type' => 'uri'], // uri type but NO uri -> item entirely skipped
    ['label' => 'ยืนยันคำสั่งซื้อ', 'type' => 'postback', 'data' => 'action=confirm', 'displayText' => 'ยืนยันแล้วค่ะ'],
    ['label' => 'ยกเลิก', 'type' => 'postback', 'data' => 'action=cancel'], // no displayText
    ['label' => 'เลือกวันนัดหมาย', 'type' => 'datetimepicker', 'data' => 'action=pick_date', 'mode' => 'date', 'initial' => '2026-08-13', 'min' => '2026-08-13', 'max' => '2026-12-31'],
    ['label' => 'เลือกเวลา', 'type' => 'datetimepicker', 'data' => 'action=pick_time', 'mode' => 'time'], // no initial/min/max
    ['label' => 'ถ่ายรูปสลิป', 'type' => 'camera'],
    ['label' => 'เลือกรูปจากคลัง', 'type' => 'cameraRoll'],
    ['label' => 'แชร์ตำแหน่งร้าน', 'type' => 'location'],
    ['label' => 'แชร์ให้เพื่อน', 'type' => 'share', 'shareText' => 'ลองดูร้านนี้สิ!'],
    ['label' => 'ปุ่มพิเศษ', 'type' => 'unknown_type_xyz'], // unrecognized type -> default: message-shape fallback
    ['label' => 'มีไอคอน', 'type' => 'message', 'text' => 'ข้อความมีไอคอน', 'imageUrl' => 'https://example.com/qr-icon.png'],
    ['type' => 'message', 'text' => 'ไม่มี label เลยจะถูกข้าม'], // no label -> item entirely skipped
];

$quickReplyAllBranchesRule = makeRule([
    'id' => 15,
    'match_type' => 'all',
    'reply_type' => 'text',
    'reply_content' => 'กรุณาเลือกตัวเลือกด้านล่างค่ะ',
    'quick_reply' => json_encode($quickReplyAllBranchesItems),
]);

writeFixture(
    $fixturesDir,
    'quick-reply-all-action-branches.json',
    'buildAutoReplyReply() — quick_reply JSON covering all 6 action-shape branches (message/uri/postback/datetimepicker/camera+cameraRoll+location/share) plus the unrecognized-type default fallback, an imageUrl item, and 2 skipped items (uri-without-uri, item-without-label).',
    ['fn' => 'buildAutoReplyReply', 'rule' => $quickReplyAllBranchesRule, 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$quickReplyAllBranchesRule], 'anything')
);

$quickReplyAbsentRule = makeRule([
    'id' => 16,
    'match_type' => 'all',
    'reply_type' => 'text',
    'reply_content' => 'ข้อความธรรมดาไม่มี quick reply',
    'quick_reply' => null,
]);

writeFixture(
    $fixturesDir,
    'quick-reply-absent-key-omitted.json',
    'buildAutoReplyReply() — rule.quick_reply is null: message has no "quickReply" key at all.',
    ['fn' => 'buildAutoReplyReply', 'rule' => $quickReplyAbsentRule, 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$quickReplyAbsentRule], 'anything')
);

// ---------------------------------------------------------------------------------------------
// Priority: tenant-scoped rule wins over a global rule matching the same text — rules pre-sorted
// exactly the way `ORDER BY line_account_id DESC, priority DESC` would sort them (MySQL DESC puts
// NULLs last, so a non-null line_account_id always sorts before a NULL one).
// ---------------------------------------------------------------------------------------------

$tenantScopedRule = makeRule([
    'id' => 17,
    'line_account_id' => 7,
    'keyword' => 'เวลาเปิด',
    'match_type' => 'contains',
    'reply_type' => 'text',
    'reply_content' => 'สาขานี้เปิดทุกวัน 08:00-22:00 น. ค่ะ',
    'priority' => 5,
]);
$globalRule = makeRule([
    'id' => 18,
    'line_account_id' => null,
    'keyword' => 'เวลาเปิด',
    'match_type' => 'contains',
    'reply_type' => 'text',
    'reply_content' => 'ร้านเปิดทุกวัน 08:00-20:00 น. ค่ะ (เวลาโดยทั่วไป)',
    'priority' => 5,
]);

writeFixture(
    $fixturesDir,
    'priority-tenant-scoped-wins-over-global.json',
    'resolveAutoReply() — rules pre-sorted [tenantScopedRule (line_account_id=7), globalRule (line_account_id=null)] per the real ORDER BY line_account_id DESC semantics, both matching the same text via contains: PIN: the tenant-scoped rule\'s reply wins (matchAutoReplyRule finds it first in array order).',
    ['fn' => 'resolveAutoReply', 'rules' => [$tenantScopedRule, $globalRule], 'text' => 'ร้านเวลาเปิดกี่โมงคะ', 'config' => ['liffShareId' => LIFF_SHARE_ID_TEST_VALUE]],
    callCheckAutoReply($db, [$tenantScopedRule, $globalRule], 'ร้านเวลาเปิดกี่โมงคะ', 7)
);

echo "\nDone — " . count(glob($fixturesDir . '/*.json')) . " fixture files in {$fixturesDir}\n";
