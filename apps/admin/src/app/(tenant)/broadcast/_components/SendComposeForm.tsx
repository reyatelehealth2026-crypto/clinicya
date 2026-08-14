'use client';

import { useMemo, useRef, useState } from 'react';
import { sendBroadcastAction } from '../_lib/send-actions';
import type {
  BroadcastGroupOption,
  BroadcastSegmentOption,
  BroadcastTagOption,
  BroadcastTemplateOption,
} from '../_lib/send-queries';

/**
 * SendComposeForm.tsx — 'use client' island for includes/broadcast/send.php's
 * `<form id="broadcastForm">` (lines 236-460) plus the WS-2 type-to-confirm modal
 * (`#broadcastConfirmModal`, lines 461-528) and every piece of inline `<script>` state that
 * form/modal pair depends on (lines 622-805). `SendTab.tsx` (server) fetches and passes down
 * templates/groups/segments/tags/totalUsers; this component owns everything interactive:
 * message-type radios, target-type radios + their per-type option panels, the tag checklist,
 * send-mode (now/schedule) toggle, template quick-load, and the confirm modal gate.
 *
 * FORM SUBMISSION: a real `<form action={sendBroadcastAction}>` (native Next.js Server Action
 * binding) — NOT a manual `fetch()`/`useActionState` orchestration. The "ส่ง Broadcast" button
 * is `type="button"` (matches send.php's own `id="submitBtn" onclick="openBroadcastConfirmModal()"`
 * — it does NOT submit the form directly); it opens the confirm modal instead. The modal's
 * "ยืนยันส่ง" button, once the case-sensitive `SEND` gate passes, calls
 * `formRef.current.requestSubmit()` — the DOM equivalent of send.php's own
 * `document.getElementById('broadcastForm').submit()` (line 805), which is why the modal
 * itself is rendered OUTSIDE the `<form>` element below (byte-for-byte the same DOM structure
 * send.php uses: `#broadcastConfirmModal` is a sibling of `#broadcastForm`, not nested inside
 * it — nesting would make its own buttons implicit submit triggers).
 *
 * RECIPIENT COUNT PREVIEW (send.php lines 300-311, `#recipientCount` / `updateRecipientCount()`
 * / `_fetchRecipientCount()`): real PHP's `_fetchRecipientCount()` calls `fetch('api/
 * count_recipients.php?...')` — a PHP endpoint that DOES NOT EXIST ANYWHERE IN THIS REPO
 * (verified: no file, no git history) — so on real production this fetch 404s, its `.catch()`
 * handler runs, and `recipientCount`'s text is left exactly as PHP server-rendered it
 * (`<?= number_format($totalUsers) ?>`) FOREVER, regardless of which target_type the admin
 * later selects. In other words: the "live" count is a dead, silently-broken no-op in real
 * PHP today — it always just shows the static database-user total. This port reproduces that
 * exact end-state faithfully: `recipientCount` below is a plain `totalUsers` prop, rendered
 * once, NEVER re-fetched or recomputed when `targetType`/`tagIds`/`segmentId`/`targetGroupId`
 * change — porting a WORKING live-count here would be new functionality beyond parity, not a
 * bug fix (see this batch's brief). The WS-2 modal's own count field reads this exact same
 * frozen `totalUsers` value for the identical reason (send.php line 613:
 * `document.getElementById('recipientCount').textContent`, itself always frozen at
 * `totalUsers` for the same broken-fetch reason).
 */

export interface SendComposeFormProps {
  templates: BroadcastTemplateOption[];
  groups: BroadcastGroupOption[];
  segments: BroadcastSegmentOption[];
  tags: BroadcastTagOption[];
  totalUsers: number;
}

type MessageType = 'text' | 'image' | 'flex';
type TargetType = 'database' | 'all' | 'segment' | 'tag' | 'group';
type SendMode = 'now' | 'schedule';

/** send.php lines 601-608 (`BC_TYPE_NAMES`). */
const TARGET_TYPE_LABELS: Record<TargetType, string> = {
  database: 'ผู้ใช้ในฐานข้อมูลทั้งหมด',
  all: 'เพื่อนทั้งหมดของ LINE OA',
  segment: 'สมาชิกใน Segment ที่เลือก',
  tag: 'ผู้ใช้ที่มี Tag ที่เลือก',
  group: 'สมาชิกในกลุ่มที่เลือก',
};

const inputClass =
  'w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500';
const radioLabelClass =
  'flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500';
const targetRadioLabelClass =
  'flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500';

export function SendComposeForm({ templates, groups, segments, tags, totalUsers }: SendComposeFormProps) {
  const formRef = useRef<HTMLFormElement>(null);

  const [title, setTitle] = useState('');
  const [messageType, setMessageType] = useState<MessageType>('text');
  const [content, setContent] = useState('');
  const [imageUrl, setImageUrl] = useState('');
  const [flexContent, setFlexContent] = useState('');
  const [targetType, setTargetType] = useState<TargetType>('database');
  const [segmentId, setSegmentId] = useState('');
  const [targetGroupId, setTargetGroupId] = useState('');
  const [tagIds, setTagIds] = useState<number[]>([]);
  const [sendMode, setSendMode] = useState<SendMode>('now');
  const [scheduledAt, setScheduledAt] = useState('');

  const [modalOpen, setModalOpen] = useState(false);
  const [confirmInput, setConfirmInput] = useState('');
  const [modalError, setModalError] = useState<string | null>(null);

  // send.php lines 606: minimum `<input type="datetime-local" min="...">` value — "now" in
  // the browser's local time, matching PHP's server-side `date('Y-m-d\TH:i')`.
  const minScheduleAt = useMemo(() => new Date().toISOString().slice(0, 16), []);

  function toggleTag(tagId: number) {
    setTagIds((prev) => (prev.includes(tagId) ? prev.filter((id) => id !== tagId) : [...prev, tagId]));
  }

  function loadTemplate(tpl: BroadcastTemplateOption) {
    setMessageType(tpl.messageType === 'flex' ? 'flex' : tpl.messageType === 'image' ? 'image' : 'text');
    if (tpl.messageType === 'text') setContent(tpl.content);
    else if (tpl.messageType === 'flex') setFlexContent(tpl.content);
    else if (tpl.messageType === 'image') setImageUrl(tpl.content);
  }

  function openConfirmModal() {
    setModalError(null);
    // send.php lines 561-570: pre-flight validation, preserved verbatim.
    if (targetType === 'tag' && tagIds.length === 0) {
      setModalError('กรุณาเลือก Tag อย่างน้อย 1 รายการ');
      return;
    }
    if (sendMode === 'schedule' && scheduledAt === '') {
      setModalError('กรุณาเลือกวันและเวลาที่ต้องการส่ง');
      return;
    }
    if (title.trim() === '') {
      setModalError('กรุณากรอกหัวข้อ Broadcast');
      return;
    }
    setConfirmInput('');
    setModalOpen(true);
  }

  function submitBroadcastForm() {
    // Case-sensitive: must equal "SEND" exactly (send.php line 796-800).
    if (confirmInput !== 'SEND') return;
    setModalOpen(false);
    formRef.current?.requestSubmit();
  }

  const showCostBox = totalUsers > 500;
  const estimatedCost = Math.round(totalUsers * 0.3);

  return (
    <div className="lg:col-span-2 space-y-6">
      {/* Templates quick-select — send.php lines 214-234 */}
      <div className="bg-white rounded-xl shadow p-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold">Templates</h3>
          <a href="/templates" className="text-sm text-green-600 hover:underline">
            จัดการ Templates →
          </a>
        </div>
        <div className="flex flex-wrap gap-2 max-h-32 overflow-y-auto">
          {templates.map((tpl) => (
            <button
              key={`${tpl.messageType}-${tpl.id}`}
              type="button"
              onClick={() => loadTemplate(tpl)}
              title={tpl.category ?? ''}
              className="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm transition"
            >
              {tpl.name}
            </button>
          ))}
          {templates.length === 0 ? <p className="text-gray-500 text-sm">ยังไม่มี Template</p> : null}
        </div>
      </div>

      {/* Main form — send.php lines 236-460 */}
      <div className="bg-white rounded-xl shadow p-6">
        <h3 className="text-lg font-semibold mb-4 flex items-center">
          <i className="fas fa-envelope text-green-500 mr-2" aria-hidden="true" />
          สร้างข้อความใหม่
        </h3>

        <form ref={formRef} action={sendBroadcastAction}>
          <input type="hidden" name="action" value="send" />

          <div className="mb-4">
            <label className="block text-sm font-medium mb-1" htmlFor="bc-title">
              หัวข้อ (สำหรับบันทึก)
            </label>
            <input
              id="bc-title"
              type="text"
              name="title"
              required
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className={inputClass}
              placeholder="เช่น โปรโมชั่นเดือนธันวาคม"
            />
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium mb-2">ประเภทข้อความ</label>
            <div className="flex flex-wrap gap-2">
              {(
                [
                  ['text', 'fa-font', 'ข้อความ'],
                  ['image', 'fa-image', 'รูปภาพ'],
                  ['flex', 'fa-code', 'Flex Message'],
                ] as const
              ).map(([value, icon, label]) => (
                <label key={value} className={radioLabelClass}>
                  <input
                    type="radio"
                    name="message_type"
                    value={value}
                    checked={messageType === value}
                    onChange={() => setMessageType(value)}
                    className="mr-2"
                  />
                  <i className={`fas ${icon} mr-2 text-gray-500`} aria-hidden="true" />
                  <span>{label}</span>
                </label>
              ))}
            </div>
          </div>

          {messageType === 'text' ? (
            <div className="mb-4">
              <label className="block text-sm font-medium mb-1" htmlFor="bc-content">
                ข้อความ
              </label>
              <textarea
                id="bc-content"
                name="content"
                rows={5}
                value={content}
                onChange={(e) => setContent(e.target.value)}
                className={inputClass}
                placeholder="พิมพ์ข้อความที่ต้องการส่ง..."
              />
              <p className="text-xs text-gray-500 mt-1">
                <i className="fas fa-info-circle mr-1" aria-hidden="true" />
                รองรับ Emoji และข้อความยาวสูงสุด 5,000 ตัวอักษร
              </p>
            </div>
          ) : null}

          {messageType === 'image' ? (
            <div className="mb-4">
              <label className="block text-sm font-medium mb-1" htmlFor="bc-image-url">
                URL รูปภาพ
              </label>
              <input
                id="bc-image-url"
                type="url"
                name="image_url"
                value={imageUrl}
                onChange={(e) => setImageUrl(e.target.value)}
                className={inputClass}
                placeholder="https://example.com/image.jpg"
              />
              <p className="text-xs text-gray-500 mt-1">
                <i className="fas fa-info-circle mr-1" aria-hidden="true" />
                รองรับ JPEG, PNG ขนาดไม่เกิน 10MB
              </p>
            </div>
          ) : null}

          {messageType === 'flex' ? (
            <div className="mb-4">
              <div className="flex justify-between items-center mb-2">
                <label className="block text-sm font-medium" htmlFor="bc-flex-json">
                  Flex Message JSON
                </label>
                <a
                  href="/flex-builder.php"
                  target="_blank"
                  rel="noreferrer"
                  className="px-3 py-1.5 bg-gradient-to-r from-green-500 to-emerald-600 text-white text-sm rounded-lg hover:opacity-90"
                >
                  🎨 Flex Builder
                </a>
              </div>
              <textarea
                id="bc-flex-json"
                name="flex_content"
                rows={8}
                value={flexContent}
                onChange={(e) => setFlexContent(e.target.value)}
                className={`${inputClass} font-mono text-sm`}
                placeholder='{"type": "bubble", "body": {...}}'
              />
            </div>
          ) : null}

          <div className="mb-4">
            <label className="block text-sm font-medium mb-2">ส่งถึง</label>
            <div className="flex flex-wrap gap-2">
              {(
                [
                  ['database', 'fa-database', 'text-blue-500', 'ในฐานข้อมูล'],
                  ['all', 'fa-users', 'text-blue-500', 'เพื่อนทั้งหมด'],
                  ['segment', 'fa-layer-group', 'text-purple-500', 'Segment'],
                  ['tag', 'fa-tag', 'text-orange-500', 'Tag'],
                  ['group', 'fa-users', 'text-blue-500', 'กลุ่ม'],
                ] as const
              ).map(([value, icon, color, label]) => (
                <label key={value} className={targetRadioLabelClass}>
                  <input
                    type="radio"
                    name="target_type"
                    value={value}
                    checked={targetType === value}
                    onChange={() => setTargetType(value)}
                    className="mr-2"
                  />
                  <i className={`fas ${icon} mr-2 ${color}`} aria-hidden="true" />
                  <span>{label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="mb-4">
            {targetType === 'database' ? (
              <div className="p-4 bg-blue-50 rounded-lg">
                <p className="text-blue-700">
                  <i className="fas fa-database mr-2" aria-hidden="true" />
                  จะส่งข้อความถึงผู้ใช้ในฐานข้อมูล <strong>{totalUsers.toLocaleString('en-US')}</strong> คน
                </p>
              </div>
            ) : null}

            {targetType === 'all' ? (
              <div className="p-4 bg-blue-50 rounded-lg">
                <p className="text-blue-700">
                  <i className="fas fa-users mr-2" aria-hidden="true" />
                  จะส่งข้อความถึงเพื่อนทั้งหมดของ LINE OA
                </p>
              </div>
            ) : null}

            {targetType === 'segment' ? (
              <div>
                <label className="block text-sm font-medium mb-1" htmlFor="bc-segment">
                  เลือก Customer Segment
                </label>
                <select
                  id="bc-segment"
                  name="segment_id"
                  value={segmentId}
                  onChange={(e) => setSegmentId(e.target.value)}
                  className={`${inputClass} focus:ring-purple-500`}
                >
                  <option value="">-- เลือก Segment --</option>
                  {segments.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name} ({s.userCount.toLocaleString('en-US')} คน)
                    </option>
                  ))}
                </select>
              </div>
            ) : null}

            {targetType === 'tag' ? (
              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="block text-sm font-medium">
                    เลือก Tag <span className="text-orange-500">(เลือกได้หลายรายการ)</span>
                  </label>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => setTagIds(tags.map((t) => t.id))}
                      className="text-xs text-orange-600 hover:underline"
                    >
                      เลือกทั้งหมด
                    </button>
                    <span className="text-gray-300">|</span>
                    <button
                      type="button"
                      onClick={() => setTagIds([])}
                      className="text-xs text-gray-500 hover:underline"
                    >
                      ล้าง
                    </button>
                  </div>
                </div>
                <div className="border rounded-lg max-h-52 overflow-y-auto divide-y">
                  {tags.length === 0 ? (
                    <p className="text-gray-500 text-sm p-3">ยังไม่มี Tag</p>
                  ) : (
                    tags.map((tag) => (
                      <label key={tag.id} className="flex items-center gap-3 px-3 py-2 hover:bg-orange-50 cursor-pointer">
                        <input
                          type="checkbox"
                          name="tag_ids[]"
                          value={tag.id}
                          checked={tagIds.includes(tag.id)}
                          onChange={() => toggleTag(tag.id)}
                          className="accent-orange-500 w-4 h-4"
                        />
                        <span className="flex-1 text-sm">{tag.name}</span>
                        <span className="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">
                          {tag.userCount.toLocaleString('en-US')} คน
                        </span>
                      </label>
                    ))
                  )}
                </div>
                <p className="text-xs text-orange-600 mt-1">
                  {tagIds.length === 0 ? 'ยังไม่ได้เลือก Tag' : `เลือกแล้ว ${tagIds.length} Tag`}
                </p>
              </div>
            ) : null}

            {targetType === 'group' ? (
              <div>
                <label className="block text-sm font-medium mb-1" htmlFor="bc-group">
                  เลือกกลุ่ม
                </label>
                <select
                  id="bc-group"
                  name="target_group_id"
                  value={targetGroupId}
                  onChange={(e) => setTargetGroupId(e.target.value)}
                  className={`${inputClass} focus:ring-green-500`}
                >
                  <option value="">-- เลือกกลุ่ม --</option>
                  {groups.map((g) => (
                    <option key={g.id} value={g.id}>
                      {g.name} ({g.memberCount} คน)
                    </option>
                  ))}
                </select>
              </div>
            ) : null}
          </div>

          {/* Recipient count preview — static totalUsers, live-update wiring intentionally a
              no-op (see module doc). */}
          <div className="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
            <div className="w-9 h-9 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i className="fas fa-users text-green-600" aria-hidden="true" />
            </div>
            <div className="flex-1">
              <div className="text-xs text-gray-500">จำนวนผู้รับโดยประมาณ</div>
              <div className="flex items-center gap-2">
                <span className="text-xl font-bold text-green-700">{totalUsers.toLocaleString('en-US')}</span>
                <span className="text-sm text-gray-500">คน</span>
              </div>
            </div>
          </div>

          <div className="mb-4 p-4 bg-gray-50 rounded-lg border">
            <label className="block text-sm font-medium mb-2">
              <i className="fas fa-clock mr-1 text-gray-500" aria-hidden="true" />
              เวลาส่ง
            </label>
            <div className="flex flex-wrap gap-3 mb-3">
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="radio"
                  name="send_mode"
                  value="now"
                  checked={sendMode === 'now'}
                  onChange={() => setSendMode('now')}
                  className="accent-green-500"
                />
                <span className="text-sm font-medium text-green-700">ส่งทันที</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="radio"
                  name="send_mode"
                  value="schedule"
                  checked={sendMode === 'schedule'}
                  onChange={() => setSendMode('schedule')}
                  className="accent-blue-500"
                />
                <span className="text-sm font-medium text-blue-700">ตั้งเวลาส่ง</span>
              </label>
            </div>
            {sendMode === 'schedule' ? (
              <div>
                <label className="block text-xs text-gray-500 mb-1" htmlFor="bc-scheduled-at">
                  เลือกวันและเวลา
                </label>
                <input
                  id="bc-scheduled-at"
                  type="datetime-local"
                  name="scheduled_at"
                  value={scheduledAt}
                  onChange={(e) => setScheduledAt(e.target.value)}
                  min={minScheduleAt}
                  className={`${inputClass} focus:ring-blue-400 text-sm`}
                />
                <span className="inline-flex items-center gap-1 mt-1 px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded-full">
                  🕐 เวลาประเทศไทย (GMT+7)
                </span>
                <p className="text-xs text-blue-600 mt-1">
                  <i className="fas fa-info-circle mr-1" aria-hidden="true" />
                  ข้อความจะถูกส่งเมื่อถึงเวลาที่กำหนด (ระบบตรวจสอบเมื่อมีการเปิดหน้านี้)
                </p>
              </div>
            ) : null}
          </div>

          {modalError && !modalOpen ? (
            <p role="alert" className="mb-3 text-sm text-red-600">
              {modalError}
            </p>
          ) : null}

          <button
            type="button"
            onClick={openConfirmModal}
            className={
              sendMode === 'schedule'
                ? 'w-full py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium transition'
                : 'w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium transition'
            }
          >
            <i className={sendMode === 'schedule' ? 'fas fa-clock mr-2' : 'fas fa-paper-plane mr-2'} aria-hidden="true" />
            {sendMode === 'schedule' ? 'ตั้งเวลาส่ง Broadcast' : 'ส่ง Broadcast'}
          </button>
        </form>
      </div>

      {/* WS-2: Type-to-Confirm Broadcast Modal — send.php lines 461-528 */}
      {modalOpen ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="bcModalTitle"
        >
          <div className="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
            <div className="px-6 py-4 border-b bg-gradient-to-r from-red-50 to-orange-50">
              <h3 id="bcModalTitle" className="text-lg font-bold text-gray-800 flex items-center gap-2">
                <i className="fas fa-exclamation-triangle text-red-500" aria-hidden="true" />
                ยืนยันการส่ง Broadcast
              </h3>
            </div>
            <div className="px-6 py-5 space-y-4">
              <div className="p-4 bg-gray-50 rounded-lg border border-gray-200">
                <div className="text-xs text-gray-500 mb-1">จะส่งไปยัง</div>
                <div className="text-sm font-medium text-gray-800">{TARGET_TYPE_LABELS[targetType]}</div>
                <div className="mt-3 flex items-end gap-2">
                  <span className="text-3xl font-bold text-green-600">{totalUsers.toLocaleString('en-US')}</span>
                  <span className="text-sm text-gray-500 mb-1">คน</span>
                </div>
              </div>

              {showCostBox ? (
                <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                  <div className="flex items-center gap-2 text-amber-800 text-sm font-medium">
                    <i className="fas fa-coins" aria-hidden="true" />
                    <span>ประมาณ ฿{estimatedCost.toLocaleString('en-US')}</span>
                  </div>
                  <p className="text-xs text-amber-700 mt-1">ต้นทุน LINE Push หลังโควต้าฟรี (≈ 0.30 ฿/ข้อความ)</p>
                </div>
              ) : null}

              {sendMode === 'schedule' ? (
                <div className="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
                  <i className="fas fa-clock mr-1" aria-hidden="true" />
                  ตั้งเวลาส่ง: <span className="font-medium">{scheduledAt.replace('T', ' ')}</span>
                  <span className="ml-1 text-xs">(GMT+7)</span>
                </div>
              ) : null}

              <div>
                <label htmlFor="bcModalConfirmInput" className="block text-sm font-medium text-gray-700 mb-1">
                  พิมพ์ <span className="font-mono font-bold text-red-600">SEND</span> เพื่อยืนยัน
                </label>
                <input
                  id="bcModalConfirmInput"
                  type="text"
                  autoComplete="off"
                  spellCheck={false}
                  value={confirmInput}
                  onChange={(e) => setConfirmInput(e.target.value)}
                  className="w-full px-4 py-2 border-2 rounded-lg font-mono uppercase tracking-wider focus:outline-none focus:ring-2 focus:ring-red-400 focus:border-red-400"
                  placeholder="SEND"
                />
                <p className="text-xs text-gray-500 mt-1">ตัวพิมพ์ใหญ่ทั้งหมด</p>
              </div>
            </div>
            <div className="px-6 py-4 bg-gray-50 border-t flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setModalOpen(false)}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
              >
                ยกเลิก
              </button>
              <button
                type="button"
                disabled={confirmInput !== 'SEND'}
                onClick={submitBroadcastForm}
                className="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-lg hover:bg-red-600 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <i className="fas fa-paper-plane mr-1" aria-hidden="true" />
                ยืนยันส่ง
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
