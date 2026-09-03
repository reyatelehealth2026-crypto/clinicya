<?php
/**
 * Golden-fixture generator for packages/line/src/flex.ts.
 *
 * Requires the REAL classes/FlexTemplates.php (never modified — read-only source of truth) and
 * calls medicineLabel() / medicineLabelsCarousel() / toMessage() with a fixed set of inputs,
 * then writes each {description, request, response} pair to
 * packages/line/src/__fixtures__/*.json — the same convention packages/contracts/fixtures uses.
 *
 * Run from anywhere:
 *   php packages/line/scripts/generate-fixtures.php
 *
 * This is the ONLY supported way to (re)produce those fixture files. Do not hand-edit the JSON
 * under src/__fixtures__/ — regenerate it here instead, so "the fixtures are real PHP captures"
 * stays true by construction.
 *
 * One field is deliberately NOT reproducible: medicineLabel()'s "วันที่จ่ายยา" value comes from
 * PHP's bare `date('d/m/Y')` (classes/FlexTemplates.php:1633) — server-local, no override hook.
 * Every fixture containing a medicineLabel() bubble will therefore have a different date each
 * time this script runs. packages/line/tests/flex.test.ts normalizes that one field out (on both
 * the fixture's recorded response AND the TS port's freshly computed output) before comparing —
 * see this script's `normalizeDates()` for the mirrored PHP-side helper, kept here only for
 * documentation purposes (the test file owns the actual comparison-time normalization).
 */

require __DIR__ . '/../../../classes/FlexTemplates.php';

$fixturesDir = __DIR__ . '/../src/__fixtures__';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0777, true);
}

/** Encodes exactly like the fixtures under packages/contracts/fixtures: pretty, unescaped unicode/slashes. */
function encode_fixture(string $description, array $request, $response): string
{
    $payload = [
        'description' => $description,
        'request' => $request,
        'response' => $response,
    ];
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

function write_fixture(string $dir, string $filename, string $description, array $request, $response): void
{
    $json = encode_fixture($description, $request, $response);
    $path = $dir . '/' . $filename;
    file_put_contents($path, $json);
    echo "wrote {$filename}\n";
}

// ---------------------------------------------------------------------------------------------
// medicineLabel() fixtures
// ---------------------------------------------------------------------------------------------

$fullShopInfo = [
    'name' => 'ร้านยาสุขภาพดี',
    'address' => '123 ถนนสุขุมวิท กรุงเทพฯ', // dead-read inside medicineLabel() — included anyway to prove it has no effect
    'phone' => '02-123-4567',
    'logo' => 'https://example.com/logo.png', // also a dead-read inside medicineLabel()
    'open_hours' => '08:00-22:00 น.',
    'pharmacist' => 'ภญ. สมหญิง ใจดี',
];

$fullItem = [
    'isMedicine' => true,
    'usageType' => 'internal',
    'image' => 'https://example.com/paracetamol.jpg',
    'timeOfDay' => ['morning', 'evening'], // dead field inside medicineLabel() — see build report
    'mealTiming' => 'after', // dead field inside medicineLabel()
    'specialInstructions' => ['drowsiness'],
    'notes' => 'แจ้งเภสัชกรหากมีอาการผิดปกติ',
    'generic_name' => 'Paracetamol',
    'strength' => '500 mg',
    'name' => 'พาราเซตามอล 500 มก.',
    'manufacturer' => 'บริษัท ยาไทย จำกัด',
    'indication' => 'บรรเทาอาการปวด ลดไข้',
    'usage_text' => 'รับประทานครั้งละ 1-2 เม็ด ทุก 4-6 ชั่วโมง เมื่อมีอาการ',
    'dosage' => 2,
    'dosageUnit' => 'เม็ด',
    'qty' => 20,
    'unit' => 'เม็ด',
    'price' => 3.5,
];

write_fixture(
    $fixturesDir,
    'medicine-label-full.json',
    'medicineLabel() — every field populated (internal medicine, checkout URL, full shop info).',
    ['fn' => 'medicineLabel', 'item' => $fullItem, 'shopInfo' => $fullShopInfo, 'patientName' => 'คุณสมชาย ใจดี', 'checkoutUrl' => 'https://shop.example.com/checkout/abc123'],
    FlexTemplates::medicineLabel($fullItem, $fullShopInfo, 'คุณสมชาย ใจดี', 'https://shop.example.com/checkout/abc123')
);

$minimalItem = ['name' => 'ยาสามัญประจำบ้าน'];

write_fixture(
    $fixturesDir,
    'medicine-label-minimal.json',
    'medicineLabel() — minimal item (name only), default shopInfo/patientName/checkoutUrl — exercises every "!empty(...) ? x : default" fallback branch.',
    ['fn' => 'medicineLabel', 'item' => $minimalItem, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => null],
    FlexTemplates::medicineLabel($minimalItem, [], '', null)
);

$notMedicineItem = [
    'isMedicine' => false,
    'usageType' => 'external',
    'name' => 'แอลกอฮอล์เช็ดแผล',
    'price' => 45,
    'qty' => 1,
    'unit' => 'ขวด',
    // Ticked on purpose even though isMedicine=false: proves the warnings box does NOT render
    // when isMedicine is false, regardless of specialInstructions content.
    'specialInstructions' => ['drowsiness', 'no_alcohol'],
];

write_fixture(
    $fixturesDir,
    'medicine-label-not-medicine-external.json',
    'medicineLabel() — isMedicine=false + usageType=external (external usage is dead-read; isMedicine=false additionally suppresses the default usage-text branch AND the warnings box even though specialInstructions is ticked).',
    ['fn' => 'medicineLabel', 'item' => $notMedicineItem, 'shopInfo' => [], 'patientName' => 'ลูกค้า', 'checkoutUrl' => null],
    FlexTemplates::medicineLabel($notMedicineItem, [], 'ลูกค้า', null)
);

$timeOfDayAllItem = array_merge($minimalItem, ['timeOfDay' => ['morning', 'noon', 'evening', 'bedtime']]);
$timeOfDayNoneItem = array_merge($minimalItem, ['timeOfDay' => []]);

write_fixture(
    $fixturesDir,
    'medicine-label-time-of-day-all.json',
    'medicineLabel() — all 4 timeOfDay slots ticked. PIN: timeOfDay is dead code in the PHP source (never rendered) — this fixture\'s body must be byte-identical to medicine-label-time-of-day-none.json\'s body.',
    ['fn' => 'medicineLabel', 'item' => $timeOfDayAllItem, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => null],
    FlexTemplates::medicineLabel($timeOfDayAllItem, [], '', null)
);

write_fixture(
    $fixturesDir,
    'medicine-label-time-of-day-none.json',
    'medicineLabel() — no timeOfDay slots ticked. PIN: timeOfDay is dead code in the PHP source (never rendered) — this fixture\'s body must be byte-identical to medicine-label-time-of-day-all.json\'s body.',
    ['fn' => 'medicineLabel', 'item' => $timeOfDayNoneItem, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => null],
    FlexTemplates::medicineLabel($timeOfDayNoneItem, [], '', null)
);

$warningsItem = [
    'isMedicine' => true,
    'name' => 'ยาแก้แพ้ชนิดง่วง',
    'qty' => 10,
    'unit' => 'เม็ด',
    'specialInstructions' => ['drowsiness', 'no_alcohol'],
    'notes' => 'ห้ามขับรถหรือทำงานกับเครื่องจักรหลังรับประทานยา',
];

write_fixture(
    $fixturesDir,
    'medicine-label-warnings-and-notes.json',
    'medicineLabel() — both special-instruction warnings (drowsiness + no_alcohol) ticked plus a custom note: exercises the combined warnings box (which also embeds a 📝 notes line) AND the separate unconditional "หมายเหตุ:" row.',
    ['fn' => 'medicineLabel', 'item' => $warningsItem, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => null],
    FlexTemplates::medicineLabel($warningsItem, [], '', null)
);

$checkoutItem = [
    'isMedicine' => true,
    'name' => 'วิตามินซี 500 มก.',
    'qty' => 30,
    'unit' => 'เม็ด',
    'price' => 2.25,
];

write_fixture(
    $fixturesDir,
    'medicine-label-checkout-url-present.json',
    'medicineLabel() — checkoutUrl present: footer renders a "ชำระเงิน" button + compact opening-hours line.',
    ['fn' => 'medicineLabel', 'item' => $checkoutItem, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => 'https://shop.example.com/pay/xyz'],
    FlexTemplates::medicineLabel($checkoutItem, [], '', 'https://shop.example.com/pay/xyz')
);

write_fixture(
    $fixturesDir,
    'medicine-label-checkout-url-null.json',
    'medicineLabel() — checkoutUrl null (same item as medicine-label-checkout-url-present.json): footer renders only the bold opening-hours line, no button.',
    ['fn' => 'medicineLabel', 'item' => $checkoutItem, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => null],
    FlexTemplates::medicineLabel($checkoutItem, [], '', null)
);

// ---------------------------------------------------------------------------------------------
// medicineLabelsCarousel() fixtures
// ---------------------------------------------------------------------------------------------

$carouselTwoItems = [
    ['isMedicine' => true, 'name' => 'ยาลดกรด', 'qty' => 1, 'unit' => 'ขวด', 'price' => 55],
    ['isMedicine' => true, 'name' => 'ผงเกลือแร่', 'qty' => 3, 'unit' => 'ซอง', 'price' => 12],
];

write_fixture(
    $fixturesDir,
    'medicine-labels-carousel-two-items-no-checkout.json',
    'medicineLabelsCarousel() — 2 items, no checkoutUrl: no summary bubble appended, carousel.contents.length === 2.',
    ['fn' => 'medicineLabelsCarousel', 'items' => $carouselTwoItems, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => null],
    FlexTemplates::medicineLabelsCarousel($carouselTwoItems, [], '', null)
);

// price*qty: 15.5*2=31, 8.25*3=24.75, 1178.75*1=1178.75 -> total 1234.5 -> number_format(1234.5, 2)
// = "1,234.50" (the exact case called out in the porting brief's acceptance criteria). Also
// exercises comma-grouping AND half-up rounding in the 0-decimal itemsList rows: 31 -> "31",
// 24.75 -> "25", 1178.75 -> "1,179".
$carouselThreeItems = [
    ['isMedicine' => true, 'name' => 'ยาแก้ไอ', 'qty' => 2, 'unit' => 'ขวด', 'price' => 15.5],
    ['isMedicine' => true, 'name' => 'ยาธาตุน้ำแดง', 'qty' => 3, 'unit' => 'ขวด', 'price' => 8.25],
    ['isMedicine' => true, 'name' => 'ครีมทาแผล', 'qty' => 1, 'unit' => 'หลอด', 'price' => 1178.75],
];

write_fixture(
    $fixturesDir,
    'medicine-labels-carousel-three-items-with-checkout.json',
    'medicineLabelsCarousel() — 3 items + checkoutUrl: summary bubble appended (carousel.contents.length === 4), total = Σ(price×qty) = 1234.5, formatted via number_format(total, 2) = "1,234.50" (comma-grouped, the acceptance-criteria example value).',
    ['fn' => 'medicineLabelsCarousel', 'items' => $carouselThreeItems, 'shopInfo' => [], 'patientName' => '', 'checkoutUrl' => 'https://shop.example.com/checkout/all'],
    FlexTemplates::medicineLabelsCarousel($carouselThreeItems, [], '', 'https://shop.example.com/checkout/all')
);

// ---------------------------------------------------------------------------------------------
// toMessage() fixtures
// ---------------------------------------------------------------------------------------------

$sampleBubble = FlexTemplates::medicineLabel($minimalItem, [], '', null);

write_fixture(
    $fixturesDir,
    'to-message-sender-preset-string.json',
    'toMessage() — sender given as a preset key string ("shop"), no quickReply.',
    ['fn' => 'toMessage', 'contents' => $sampleBubble, 'altText' => 'ฉลากยาของคุณ', 'sender' => 'shop', 'quickReply' => null],
    FlexTemplates::toMessage($sampleBubble, 'ฉลากยาของคุณ', 'shop', null)
);

$customSender = ['name' => '🏥 ร้านยาสุขภาพดี', 'iconUrl' => 'https://example.com/custom-icon.png'];

write_fixture(
    $fixturesDir,
    'to-message-sender-custom-object.json',
    'toMessage() — sender given as a custom {name, iconUrl} object (not a preset key), no quickReply.',
    ['fn' => 'toMessage', 'contents' => $sampleBubble, 'altText' => 'ฉลากยาของคุณ', 'sender' => $customSender, 'quickReply' => null],
    FlexTemplates::toMessage($sampleBubble, 'ฉลากยาของคุณ', $customSender, null)
);

write_fixture(
    $fixturesDir,
    'to-message-quickreply-preset-string.json',
    'toMessage() — quickReply given as a preset key string ("main"), no sender.',
    ['fn' => 'toMessage', 'contents' => $sampleBubble, 'altText' => 'เมนู', 'sender' => null, 'quickReply' => 'main'],
    FlexTemplates::toMessage($sampleBubble, 'เมนู', null, 'main')
);

// PHP (classes/FlexTemplates.php:136) assigns $message['quickReply'] unconditionally in the
// is_string($quickReply) branch, even when buildQuickReply() resolves to null (unrecognized
// preset name, not one of 'main'/'shop'/'order'/'support') — pins that the "quickReply" key is
// STILL PRESENT with a null value, not omitted.
write_fixture(
    $fixturesDir,
    'to-message-quickreply-unrecognized-preset-string.json',
    'toMessage() — quickReply given as a string that is NOT a recognized preset key: the "quickReply" key is still present in the output, with a null value (PHP assigns unconditionally).',
    ['fn' => 'toMessage', 'contents' => $sampleBubble, 'altText' => 'เมนู', 'sender' => null, 'quickReply' => 'nonexistent-preset'],
    FlexTemplates::toMessage($sampleBubble, 'เมนู', null, 'nonexistent-preset')
);

// Covers all 6 buildQuickReply() action-shape branches in one array, in this exact order:
// camera, cameraRoll, location, uri, postback (isset($item['data'])), message (default/fallback).
$customQuickReplyItems = [
    ['type' => 'camera', 'label' => '📷 ถ่ายรูปสลิป'],
    ['type' => 'cameraRoll'], // no label -> exercises the '📷'-style default-label fallback (cameraRoll's own default)
    ['type' => 'location', 'label' => '📍 แชร์ตำแหน่งร้าน'],
    ['label' => 'เว็บไซต์ร้าน', 'uri' => 'https://shop.example.com'],
    ['label' => 'ยืนยันคำสั่งซื้อ', 'data' => 'action=confirm_order&id=123', 'displayText' => 'ยืนยันแล้ว'],
    ['label' => 'ดูเมนู', 'text' => 'menu'],
];

write_fixture(
    $fixturesDir,
    'to-message-quickreply-custom-array-all-branches.json',
    'toMessage() — quickReply given as a custom array covering all 6 buildQuickReply() action branches (camera, cameraRoll, location, uri, postback/data, message-default), no sender.',
    ['fn' => 'toMessage', 'contents' => $sampleBubble, 'altText' => 'ตัวเลือก', 'sender' => null, 'quickReply' => $customQuickReplyItems],
    FlexTemplates::toMessage($sampleBubble, 'ตัวเลือก', null, $customQuickReplyItems)
);

write_fixture(
    $fixturesDir,
    'to-message-no-sender-no-quickreply.json',
    'toMessage() — neither sender nor quickReply given (both null): message object has no "sender"/"quickReply" keys at all.',
    ['fn' => 'toMessage', 'contents' => $sampleBubble, 'altText' => 'ข้อความทั่วไป', 'sender' => null, 'quickReply' => null],
    FlexTemplates::toMessage($sampleBubble, 'ข้อความทั่วไป', null, null)
);

echo "\nDone — " . count(glob($fixturesDir . '/*.json')) . " fixture files in {$fixturesDir}\n";
