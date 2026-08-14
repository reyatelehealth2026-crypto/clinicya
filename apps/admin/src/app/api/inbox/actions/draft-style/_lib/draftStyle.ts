/**
 * draftStyle.ts — pure, DB-free literal port of
 * `classes/CustomerHealthEngineService.php::getDraftStyle()` (lines 932-1010):
 *
 * ```php
 * public function getDraftStyle(string $type): array
 * {
 *     switch ($type) {
 *         case self::TYPE_DIRECT: // Type A - Direct
 *             return [
 *                 'type' => 'A', 'typeName' => 'Direct', 'typeNameTh' => 'ตรงประเด็น',
 *                 'maxWords' => 50, 'useEmoji' => false, 'includeDetails' => false, 'includePrice' => true,
 *                 'tone' => 'professional', 'toneTh' => 'มืออาชีพ', 'responseStyle' => 'concise',
 *                 'tips' => [
 *                     'ตอบสั้น กระชับ ตรงประเด็น', 'บอกชื่อยา ราคา วิธีใช้ ชัดเจน',
 *                     'ไม่ต้องอธิบายรายละเอียดมาก', 'เสนอทางเลือกไม่เกิน 2-3 ตัว'
 *                 ],
 *                 'sampleOpening' => 'แนะนำ', 'sampleClosing' => 'สนใจตัวไหนแจ้งได้เลยค่ะ'
 *             ];
 *         case self::TYPE_CONCERNED: // Type B - Concerned
 *             return [
 *                 'type' => 'B', 'typeName' => 'Concerned', 'typeNameTh' => 'ห่วงใย',
 *                 'maxWords' => 150, 'useEmoji' => true, 'includeDetails' => true, 'includePrice' => false,
 *                 'tone' => 'empathetic', 'toneTh' => 'เห็นอกเห็นใจ', 'responseStyle' => 'reassuring',
 *                 'tips' => [
 *                     'แสดงความเข้าใจและห่วงใย', 'อธิบายความปลอดภัยของยา',
 *                     'ให้ความมั่นใจว่าอาการจะดีขึ้น', 'เปิดโอกาสให้ถามเพิ่มเติม'
 *                 ],
 *                 'sampleOpening' => 'เข้าใจความกังวลค่ะ 🙏', 'sampleClosing' => 'มีอะไรสงสัยถามได้เลยนะคะ ยินดีช่วยเหลือค่ะ 😊'
 *             ];
 *         case self::TYPE_DETAILED: // Type C - Detail-oriented
 *             return [
 *                 'type' => 'C', 'typeName' => 'Detail-oriented', 'typeNameTh' => 'ใส่ใจรายละเอียด',
 *                 'maxWords' => 300, 'useEmoji' => false, 'includeDetails' => true, 'includePrice' => true,
 *                 'includeComparison' => true, 'includeScientific' => true,
 *                 'tone' => 'informative', 'toneTh' => 'ให้ข้อมูล', 'responseStyle' => 'detailed',
 *                 'tips' => [
 *                     'ให้ข้อมูลครบถ้วน ละเอียด', 'เปรียบเทียบยาหลายตัว',
 *                     'อธิบายกลไกการออกฤทธิ์', 'แนบข้อมูลทางวิทยาศาสตร์'
 *                 ],
 *                 'sampleOpening' => 'ขอให้ข้อมูลเปรียบเทียบดังนี้ค่ะ', 'sampleClosing' => 'หากต้องการข้อมูลเพิ่มเติมยินดีค่ะ'
 *             ];
 *         default:
 *             return $this->getDraftStyle(self::TYPE_DIRECT);
 *     }
 * }
 * ```
 *
 * `self::TYPE_DIRECT`/`TYPE_CONCERNED`/`TYPE_DETAILED` are the string
 * constants `'A'`/`'B'`/`'C'` — the switch is effectively `switch ($type) {
 * case 'A': ...; case 'B': ...; case 'C': ...; default: ...}`, a
 * case-sensitive strict-string match (no coercion), replicated below with a
 * plain `switch` on the raw `type: string` parameter.
 *
 * SINGLE-OWNER CANONICAL IMPLEMENTATION: imported by
 * `../../customer-health/_lib/customerHealth.ts` (via `getHealthProfile()` ->
 * `getDraftStyle()`) and `../../classify-customer/_lib/classifyCustomer.ts`
 * (via `getDefaultTips()` -> `getDraftStyle()['tips']`) — matching PHP's own
 * `CustomerHealthEngineService::getOrCreateProfile()`/`getDefaultTips()` both
 * ultimately calling this same method on `$this`. Both cross-route imports
 * are the deliberate, documented exceptions to this batch's "one owner per
 * directory" convention (see this batch's brief).
 *
 * NOT to be confused with `../../ghost-draft/_lib/ghostDraft.ts`'s
 * `getDraftStyleForType()` — a structurally similar but NOT byte-identical
 * PHP method (`PharmacyGhostDraftService::getDraftStyleForType()`, fewer
 * keys, no `tips`/`includePrice`/`includeComparison`/`includeScientific`/
 * `responseStyle`/`sampleOpening`/`sampleClosing`). Both are ported
 * literally and independently — see that module's own doc.
 */

export interface DraftStyle {
  type: 'A' | 'B' | 'C';
  typeName: string;
  typeNameTh: string;
  maxWords: number;
  useEmoji: boolean;
  includeDetails: boolean;
  includePrice: boolean;
  /** Type C only. */
  includeComparison?: boolean;
  /** Type C only. */
  includeScientific?: boolean;
  tone: string;
  toneTh: string;
  responseStyle: string;
  tips: string[];
  sampleOpening: string;
  sampleClosing: string;
}

export function getDraftStyle(type: string): DraftStyle {
  switch (type) {
    case 'A': // Type A - Direct — Requirements 2.2: concise responses with clear drug recommendations
      return {
        type: 'A',
        typeName: 'Direct',
        typeNameTh: 'ตรงประเด็น',
        maxWords: 50,
        useEmoji: false,
        includeDetails: false,
        includePrice: true,
        tone: 'professional',
        toneTh: 'มืออาชีพ',
        responseStyle: 'concise',
        tips: [
          'ตอบสั้น กระชับ ตรงประเด็น',
          'บอกชื่อยา ราคา วิธีใช้ ชัดเจน',
          'ไม่ต้องอธิบายรายละเอียดมาก',
          'เสนอทางเลือกไม่เกิน 2-3 ตัว',
        ],
        sampleOpening: 'แนะนำ',
        sampleClosing: 'สนใจตัวไหนแจ้งได้เลยค่ะ',
      };

    case 'B': // Type B - Concerned — Requirements 2.3: empathetic responses with reassurance
      return {
        type: 'B',
        typeName: 'Concerned',
        typeNameTh: 'ห่วงใย',
        maxWords: 150,
        useEmoji: true,
        includeDetails: true,
        includePrice: false,
        tone: 'empathetic',
        toneTh: 'เห็นอกเห็นใจ',
        responseStyle: 'reassuring',
        tips: [
          'แสดงความเข้าใจและห่วงใย',
          'อธิบายความปลอดภัยของยา',
          'ให้ความมั่นใจว่าอาการจะดีขึ้น',
          'เปิดโอกาสให้ถามเพิ่มเติม',
        ],
        sampleOpening: 'เข้าใจความกังวลค่ะ 🙏',
        sampleClosing: 'มีอะไรสงสัยถามได้เลยนะคะ ยินดีช่วยเหลือค่ะ 😊',
      };

    case 'C': // Type C - Detail-oriented — Requirements 2.4: comparison tables, dosage charts, scientific info
      return {
        type: 'C',
        typeName: 'Detail-oriented',
        typeNameTh: 'ใส่ใจรายละเอียด',
        maxWords: 300,
        useEmoji: false,
        includeDetails: true,
        includePrice: true,
        includeComparison: true,
        includeScientific: true,
        tone: 'informative',
        toneTh: 'ให้ข้อมูล',
        responseStyle: 'detailed',
        tips: [
          'ให้ข้อมูลครบถ้วน ละเอียด',
          'เปรียบเทียบยาหลายตัว',
          'อธิบายกลไกการออกฤทธิ์',
          'แนบข้อมูลทางวิทยาศาสตร์',
        ],
        sampleOpening: 'ขอให้ข้อมูลเปรียบเทียบดังนี้ค่ะ',
        sampleClosing: 'หากต้องการข้อมูลเพิ่มเติมยินดีค่ะ',
      };

    default:
      // PHP: `return $this->getDraftStyle(self::TYPE_DIRECT);`
      return getDraftStyle('A');
  }
}
