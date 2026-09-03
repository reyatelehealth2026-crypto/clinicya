/**
 * quickActions.ts — literal port of `classes/ConsultationAnalyzerService.php`'s
 * `getQuickActions()` (lines 1419-1627), as driven by api/inbox-v2.php's
 * `case 'quick_actions': case 'quick-actions': case 'get_quick_actions':`
 * (lines ~1604-1642). Pure — no DB access (the PHP method itself never
 * touches `$this->db`).
 *
 * ```php
 * public function getQuickActions(string $stage, bool $hasUrgentSymptoms = false): array
 * {
 *     $actions = [];
 *     switch ($stage) {
 *         case self::STAGE_SYMPTOM: $actions = [ ...4 items... ]; break;
 *         case self::STAGE_RECOMMENDATION: $actions = [ ...4 items... ]; break;
 *         case self::STAGE_PURCHASE: $actions = [ ...4 items... ]; break;
 *         case self::STAGE_FOLLOWUP: $actions = [ ...4 items... ]; break;
 *         default: $actions = [ ...2 items... ];
 *     }
 *     if ($hasUrgentSymptoms) {
 *         array_unshift($actions, [
 *             'id' => 'recommend_hospital', 'label' => '🚨 แนะนำพบแพทย์ด่วน',
 *             'labelEn' => '🚨 Recommend Hospital Visit', 'icon' => '🏥', 'action' => 'recommend_hospital',
 *             'template' => '⚠️ จากอาการที่แจ้งมา แนะนำให้พบแพทย์โดยเร็วค่ะ เพื่อความปลอดภัย กรุณาไปโรงพยาบาลหรือคลินิกใกล้บ้านค่ะ',
 *             'isUrgent' => true, 'priority' => 100, 'highlight' => true
 *         ]);
 *     }
 *     usort($actions, fn($a, $b) => ($b['priority'] ?? 0) - ($a['priority'] ?? 0));
 *     return ['stage' => $stage, 'stageLabel' => $this->getStageLabelTh($stage), 'hasUrgentSymptoms' => $hasUrgentSymptoms, 'actions' => $actions];
 * }
 * ```
 *
 * Exact PHP action lists ported byte-for-byte below, including the Thai
 * strings, ids, icons, priorities, and the two branches (`STAGE_SYMPTOM`,
 * `STAGE_FOLLOWUP`) that carry a `template` key on one of their 4 items —
 * plus the `default` branch's own `template` key on its first item.
 *
 * `usort()`'s priority-descending comparator is stable in PHP 8.0+ (this
 * repo targets PHP 8.0+ per CLAUDE.md) — ties keep their pre-sort relative
 * order (urgent-unshift first, if present, then each stage's own list
 * order). JS `Array.prototype.sort` is required to be stable since ES2019,
 * so `.sort((a, b) => (b.priority ?? 0) - (a.priority ?? 0))` matches
 * exactly.
 *
 * `getStageLabelTh()` — the tiny 4-entry Thai stage-label lookup — is
 * duplicated here (not cross-imported from
 * `../../consultation-stage/_lib/consultationStage.ts`) per this batch's
 * brief: the ONE specified cross-import is `detectStage` itself (used in
 * `route.ts`, not here). This file stays a pure, dependency-free port of
 * `getQuickActions()` alone.
 */

export interface QuickAction {
  id: string;
  label: string;
  labelEn: string;
  icon: string;
  action: string;
  template?: string;
  priority: number;
  isUrgent?: true;
  highlight?: true;
}

export interface QuickActionsResult {
  stage: string;
  stageLabel: string;
  hasUrgentSymptoms: boolean;
  actions: QuickAction[];
}

/** PHP `ConsultationAnalyzerService::getStageLabelTh()` (lines 408-417) — duplicated, see module doc. */
function getStageLabelTh(stage: string): string {
  const labels: Record<string, string> = {
    symptom_assessment: 'ประเมินอาการ',
    drug_recommendation: 'แนะนำยา',
    purchase: 'ตัดสินใจซื้อ',
    follow_up: 'ติดตามผล',
  };
  return labels[stage] ?? 'ไม่ระบุ';
}

const SYMPTOM_ACTIONS: QuickAction[] = [
  {
    id: 'ask_followup',
    label: 'ถามอาการเพิ่มเติม',
    labelEn: 'Ask Follow-up',
    icon: '❓',
    action: 'ask_followup',
    template: 'อาการเป็นมานานแค่ไหนแล้วคะ? มีอาการอื่นร่วมด้วยไหมคะ?',
    priority: 10,
  },
  { id: 'check_history', label: 'ดูประวัติ', labelEn: 'Check History', icon: '📋', action: 'check_history', priority: 8 },
  { id: 'suggest_otc', label: 'แนะนำยา OTC', labelEn: 'Suggest OTC', icon: '💊', action: 'suggest_otc', priority: 9 },
  { id: 'analyze_image', label: 'วิเคราะห์รูป', labelEn: 'Analyze Image', icon: '📷', action: 'analyze_image', priority: 7 },
];

const RECOMMENDATION_ACTIONS: QuickAction[] = [
  { id: 'send_drug_info', label: 'ส่งข้อมูลยา', labelEn: 'Send Drug Info', icon: '💊', action: 'send_drug_info', priority: 10 },
  { id: 'check_interactions', label: 'ตรวจยาตีกัน', labelEn: 'Check Interactions', icon: '⚠️', action: 'check_interactions', priority: 9 },
  { id: 'apply_discount', label: 'ให้ส่วนลด', labelEn: 'Apply Discount', icon: '💰', action: 'apply_discount', priority: 7 },
  { id: 'compare_drugs', label: 'เปรียบเทียบยา', labelEn: 'Compare Drugs', icon: '📊', action: 'compare_drugs', priority: 8 },
];

const PURCHASE_ACTIONS: QuickAction[] = [
  { id: 'create_order', label: 'สร้างออเดอร์', labelEn: 'Create Order', icon: '🛒', action: 'create_order', priority: 10 },
  { id: 'send_payment_link', label: 'ส่งลิงก์ชำระเงิน', labelEn: 'Send Payment Link', icon: '💳', action: 'send_payment_link', priority: 9 },
  { id: 'schedule_delivery', label: 'นัดส่งสินค้า', labelEn: 'Schedule Delivery', icon: '🚚', action: 'schedule_delivery', priority: 8 },
  { id: 'apply_points', label: 'ใช้แต้มสะสม', labelEn: 'Apply Points', icon: '⭐', action: 'apply_points', priority: 7 },
];

const FOLLOWUP_ACTIONS: QuickAction[] = [
  {
    id: 'check_progress',
    label: 'ถามความคืบหน้า',
    labelEn: 'Check Progress',
    icon: '📈',
    action: 'check_progress',
    template: 'อาการเป็นอย่างไรบ้างคะ? ดีขึ้นไหมคะ?',
    priority: 10,
  },
  { id: 'suggest_refill', label: 'แนะนำเติมยา', labelEn: 'Suggest Refill', icon: '🔄', action: 'suggest_refill', priority: 9 },
  { id: 'schedule_followup', label: 'นัดติดตาม', labelEn: 'Schedule Follow-up', icon: '📅', action: 'schedule_followup', priority: 8 },
  { id: 'refer_doctor', label: 'แนะนำพบแพทย์', labelEn: 'Refer to Doctor', icon: '🏥', action: 'refer_doctor', priority: 7 },
];

const DEFAULT_ACTIONS: QuickAction[] = [
  {
    id: 'ask_symptoms',
    label: 'ถามอาการ',
    labelEn: 'Ask Symptoms',
    icon: '❓',
    action: 'ask_symptoms',
    template: 'สวัสดีค่ะ มีอาการอะไรให้ช่วยเหลือคะ?',
    priority: 10,
  },
  { id: 'check_history', label: 'ดูประวัติ', labelEn: 'Check History', icon: '📋', action: 'check_history', priority: 8 },
];

const URGENT_ACTION: QuickAction = {
  id: 'recommend_hospital',
  label: '🚨 แนะนำพบแพทย์ด่วน',
  labelEn: '🚨 Recommend Hospital Visit',
  icon: '🏥',
  action: 'recommend_hospital',
  template: '⚠️ จากอาการที่แจ้งมา แนะนำให้พบแพทย์โดยเร็วค่ะ เพื่อความปลอดภัย กรุณาไปโรงพยาบาลหรือคลินิกใกล้บ้านค่ะ',
  isUrgent: true,
  priority: 100,
  highlight: true,
};

/** PHP `getQuickActions()` (lines 1419-1627). */
export function getQuickActions(stage: string, hasUrgentSymptoms = false): QuickActionsResult {
  let actions: QuickAction[];

  switch (stage) {
    case 'symptom_assessment':
      actions = [...SYMPTOM_ACTIONS];
      break;
    case 'drug_recommendation':
      actions = [...RECOMMENDATION_ACTIONS];
      break;
    case 'purchase':
      actions = [...PURCHASE_ACTIONS];
      break;
    case 'follow_up':
      actions = [...FOLLOWUP_ACTIONS];
      break;
    default:
      actions = [...DEFAULT_ACTIONS];
  }

  // Requirements 9.4: Add urgent action if symptoms are severe
  if (hasUrgentSymptoms) {
    actions.unshift(URGENT_ACTION);
  }

  // Sort by priority (descending, stable — see module doc)
  actions.sort((a, b) => (b.priority ?? 0) - (a.priority ?? 0));

  return {
    stage,
    stageLabel: getStageLabelTh(stage),
    hasUrgentSymptoms,
    actions,
  };
}
