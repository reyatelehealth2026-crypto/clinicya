<?php
/**
 * Document PDF Renderer — printable A4 HTML.
 * ผู้สร้าง PDF/HTML สำหรับเอกสารบัญชี
 *
 * NOTE on PDF library: TCPDF / DOMPDF are not installed via composer in this
 * repo. This renderer returns a self-contained printable HTML document with
 * Thai web fonts and a print stylesheet — users can use the browser's
 * "Save as PDF" (Ctrl+P). When DOMPDF is later added to composer.json, swap
 * the trailing `return $html` for `$dompdf->loadHtml($html); ...`.
 *
 * Includes the diagonal "ยกเลิก" watermark when status=cancelled per spec.
 *
 * @package Documents
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../document-helpers.php';

/**
 * Render a printable HTML document.
 *
 * @param array $doc  Result of documents_fetch() — includes 'items' array
 * @param array $shop Result of SELECT * FROM shop_tax_info (may be empty)
 * @return string Complete HTML page
 */
function renderDocumentPrintable(array $doc, array $shop): string
{
    $docType   = (string)($doc['doc_type'] ?? '');
    $typeLabel = docTypeLabel($docType);
    $statusLbl = docStatusLabel((string)($doc['status'] ?? ''));
    $items     = $doc['items'] ?? [];
    $isCancelled = ($doc['status'] ?? '') === 'cancelled';

    $h = static function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    // Headers / shop block
    $shopName = $h($shop['business_name'] ?? '');
    $shopAddr = nl2br($h($shop['address'] ?? ''));
    $shopTax  = $h($shop['tax_id'] ?? '');
    $shopBranch = $h($shop['branch_code'] ?? '00000');
    $shopPhone = $h($shop['phone'] ?? '');
    $logo = $h($shop['logo_url'] ?? '');

    // Customer block
    $custName = $h($doc['customer_name'] ?? '-');
    $custTax  = $h($doc['customer_tax_id'] ?? '');
    $custBranch = $h($doc['customer_branch_code'] ?? '');
    $custAddr = nl2br($h($doc['customer_address'] ?? ''));
    $custPhone = $h($doc['customer_phone'] ?? '');

    // Items rows
    $rowsHtml = '';
    $lineNo = 0;
    foreach ($items as $it) {
        $lineNo++;
        $name = $h($it['product_name']);
        $sku  = $h($it['product_sku'] ?? '');
        $desc = trim((string)($it['description'] ?? '')) !== ''
            ? '<div class="muted">' . nl2br($h($it['description'])) . '</div>'
            : '';
        $rowsHtml .= '<tr>'
            . '<td class="num">' . $lineNo . '</td>'
            . '<td>' . ($sku !== '' ? '<div class="muted">' . $sku . '</div>' : '') . $name . $desc . '</td>'
            . '<td class="num">' . formatMoney((float)$it['quantity']) . '</td>'
            . '<td>' . $h($it['unit'] ?? '') . '</td>'
            . '<td class="num">' . formatMoney((float)$it['unit_price']) . '</td>'
            . '<td class="num">' . formatMoney((float)$it['discount_amount']) . '</td>'
            . '<td class="num">' . formatMoney((float)$it['line_total']) . '</td>'
            . '</tr>';
    }

    $issueDateThai = formatThaiDate((string)($doc['issue_date'] ?? ''), false);
    $dueDateThai   = $doc['due_date']    ? formatThaiDate((string)$doc['due_date'], false)    : '';
    $validUntilThai= $doc['valid_until'] ? formatThaiDate((string)$doc['valid_until'], false) : '';

    $subtotal = formatMoney((float)($doc['subtotal'] ?? 0));
    $disc     = formatMoney((float)($doc['discount_amount'] ?? 0));
    $vatRate  = number_format((float)($doc['vat_rate'] ?? 7.00), 2);
    $vatAmt   = formatMoney((float)($doc['vat_amount'] ?? 0));
    $total    = formatMoney((float)($doc['total_amount'] ?? 0));

    $signer = $h($shop['authorized_signer'] ?? '');
    $signerPos = $h($shop['signer_position'] ?? '');

    $note = $doc['note'] ? nl2br($h($doc['note'])) : '';
    $cancelReason = $isCancelled && !empty($doc['cancel_reason'])
        ? '<div class="cancel-note"><strong>เหตุผลที่ยกเลิก:</strong> ' . $h($doc['cancel_reason']) . '</div>'
        : '';

    $watermark = $isCancelled
        ? '<div class="watermark">ยกเลิก</div>'
        : '';

    $docNumber = $h($doc['doc_number']);

    $titleEsc = $h($typeLabel . ' ' . ($doc['doc_number'] ?? ''));

    // Logo block
    $logoBlock = $logo !== ''
        ? '<img src="' . $logo . '" alt="logo" style="max-height:80px;max-width:180px">'
        : '';

    $css = <<<CSS
* { box-sizing: border-box; }
body {
    font-family: "Sarabun", "Noto Sans Thai", "Tahoma", sans-serif;
    color: #1f2937;
    background: #f3f4f6;
    margin: 0;
    padding: 24px;
}
.sheet {
    background: #fff;
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 18mm 16mm;
    position: relative;
    box-shadow: 0 6px 24px rgba(0,0,0,.08);
    overflow: hidden;
}
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #0f172a;
    padding-bottom: 12px;
    margin-bottom: 18px;
}
.shop-block { max-width: 60%; }
.shop-name { font-size: 20px; font-weight: 700; margin: 0 0 4px; }
.muted { color: #6b7280; font-size: 11px; }
.doc-title {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    text-align: right;
    line-height: 1.1;
}
.doc-meta { text-align: right; font-size: 12px; margin-top: 6px; }
.parties { display: flex; gap: 16px; margin-bottom: 16px; }
.party {
    flex: 1;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px 12px;
}
.party-label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.party-name  { font-weight: 600; margin-top: 2px; }
table.items {
    width: 100%;
    border-collapse: collapse;
    margin: 8px 0 16px;
    font-size: 12px;
}
table.items th, table.items td {
    border-bottom: 1px solid #e5e7eb;
    padding: 8px 6px;
    text-align: left;
    vertical-align: top;
}
table.items th { background: #f8fafc; font-weight: 600; }
.num { text-align: right; }
.totals {
    margin-left: auto;
    width: 280px;
    border-top: 1px solid #cbd5e1;
    padding-top: 6px;
}
.totals .row { display: flex; justify-content: space-between; padding: 4px 0; }
.totals .grand { font-weight: 700; border-top: 1px solid #0f172a; margin-top: 4px; padding-top: 6px; font-size: 14px; }
.note-block {
    margin-top: 16px;
    padding: 8px 12px;
    background: #fafaf9;
    border-left: 4px solid #cbd5e1;
    font-size: 12px;
}
.cancel-note {
    margin-top: 8px;
    padding: 8px 12px;
    background: #fef2f2;
    color: #991b1b;
    border-left: 4px solid #dc2626;
    font-size: 12px;
}
.signatures {
    display: flex;
    justify-content: space-between;
    margin-top: 36px;
    padding-top: 24px;
    font-size: 12px;
}
.sig-box { text-align: center; width: 200px; }
.sig-line { border-top: 1px dotted #94a3b8; margin-bottom: 4px; padding-top: 28px; }
.watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 180px;
    font-weight: 800;
    color: rgba(220, 38, 38, .18);
    pointer-events: none;
    user-select: none;
    z-index: 1;
}
.print-bar {
    text-align: center;
    margin: 0 auto 16px;
    max-width: 210mm;
}
.print-bar button {
    padding: 10px 22px;
    border-radius: 999px;
    border: 0;
    background: #0f172a;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}
@media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .sheet { box-shadow: none; width: auto; min-height: auto; padding: 12mm; }
}
CSS;

    $html = '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
        . '<title>' . $titleEsc . '</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">'
        . '<style>' . $css . '</style>'
        . '</head><body>'
        . '<div class="print-bar"><button onclick="window.print()">🖨️ พิมพ์ / Save as PDF</button>'
        . ' <span style="color:#6b7280;font-size:12px;margin-left:8px">สถานะ: ' . $h($statusLbl) . '</span></div>'
        . '<div class="sheet">'
        . $watermark
        . '<div class="header">'
        .   '<div class="shop-block">'
        .     $logoBlock
        .     '<div class="shop-name">' . ($shopName !== '' ? $shopName : '(ยังไม่ได้ตั้งชื่อกิจการ)') . '</div>'
        .     '<div class="muted">' . $shopAddr . '</div>'
        .     '<div class="muted">โทร ' . $shopPhone . '</div>'
        .     ($shopTax !== '' ? '<div class="muted">เลขประจำตัวผู้เสียภาษี ' . $shopTax . ' สาขา ' . $shopBranch . '</div>' : '')
        .   '</div>'
        .   '<div>'
        .     '<div class="doc-title">' . $h($typeLabel) . '</div>'
        .     '<div class="doc-meta">'
        .       '<div>เลขที่: <strong>' . $docNumber . '</strong></div>'
        .       '<div>วันที่: ' . $h($issueDateThai) . '</div>'
        .       ($dueDateThai !== '' ? '<div>ครบกำหนด: ' . $h($dueDateThai) . '</div>' : '')
        .       ($validUntilThai !== '' ? '<div>ใช้ได้ถึง: ' . $h($validUntilThai) . '</div>' : '')
        .     '</div>'
        .   '</div>'
        . '</div>'

        . '<div class="parties">'
        .   '<div class="party">'
        .     '<div class="party-label">ลูกค้า / Bill To</div>'
        .     '<div class="party-name">' . $custName . '</div>'
        .     ($custAddr !== '' ? '<div class="muted">' . $custAddr . '</div>' : '')
        .     ($custPhone !== '' ? '<div class="muted">โทร ' . $custPhone . '</div>' : '')
        .     ($custTax !== '' ? '<div class="muted">TAX ID: ' . $custTax . ($custBranch !== '' ? ' (สาขา ' . $custBranch . ')' : '') . '</div>' : '')
        .   '</div>'
        . '</div>'

        . '<table class="items">'
        . '<thead><tr>'
        . '<th style="width:34px">#</th>'
        . '<th>รายการ</th>'
        . '<th style="width:60px" class="num">จำนวน</th>'
        . '<th style="width:60px">หน่วย</th>'
        . '<th style="width:84px" class="num">ราคา/หน่วย</th>'
        . '<th style="width:80px" class="num">ส่วนลด</th>'
        . '<th style="width:96px" class="num">รวม (บาท)</th>'
        . '</tr></thead>'
        . '<tbody>' . ($rowsHtml ?: '<tr><td colspan="7" class="muted" style="text-align:center;padding:24px">— ไม่มีรายการ —</td></tr>') . '</tbody>'
        . '</table>'

        . '<div class="totals">'
        .   '<div class="row"><span>รวมก่อนภาษี</span><span>' . $subtotal . '</span></div>'
        .   '<div class="row"><span>ส่วนลดรวม</span><span>' . $disc . '</span></div>'
        .   '<div class="row"><span>ภาษีมูลค่าเพิ่ม (' . $vatRate . '%)</span><span>' . $vatAmt . '</span></div>'
        .   '<div class="row grand"><span>ยอดรวมสุทธิ</span><span>' . $total . ' บาท</span></div>'
        . '</div>'

        . ($note !== '' ? '<div class="note-block"><strong>หมายเหตุ:</strong> ' . $note . '</div>' : '')
        . $cancelReason

        . '<div class="signatures">'
        .   '<div class="sig-box"><div class="sig-line"></div>ผู้รับสินค้า / Received By</div>'
        .   '<div class="sig-box"><div class="sig-line"></div>' . ($signer !== '' ? $signer : 'ผู้มีอำนาจลงนาม') . ($signerPos !== '' ? '<br><span class="muted">' . $signerPos . '</span>' : '') . '</div>'
        . '</div>'
        . '</div>' // /sheet
        . '</body></html>';
    return $html;
}
