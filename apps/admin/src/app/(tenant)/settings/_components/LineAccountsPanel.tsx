'use client';

import { createContext, useContext, useEffect, useState, useTransition, type CSSProperties, type FormEvent, type ReactNode } from 'react';
import { Modal } from '@/components/Modal';
import {
  createLineAccountAction,
  deleteLineAccountAction,
  updateLineAccountAction,
  testLineConnectionAction,
  type LineAccountFormInput,
  type LineTestConnectionResult,
} from '../_lib/line-actions';
import type { LineAccountRow, LineBotMode } from '../_lib/line-queries';

/**
 * LineAccountsPanel.tsx — the "client island" for ../_components/LineAccountsTab.tsx
 * (the Server Component that renders the account-card grid itself). This
 * file owns every piece of includes/settings/line.php that needs client-side
 * JS: `openLineModal()`/`editLineAccount()`/`closeLineModal()` (lines
 * 411-457), `showLineTab()` (404-409), `deleteLineAccount()` (459-466),
 * `copyWebhook()` (468-473), `testLineConnection()`/`closeLineTestModal()`
 * (475-510), and `showLineStats()` (512-514).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * Architecture — ONE client Provider wraps the Server-rendered grid as `children`
 * ═══════════════════════════════════════════════════════════════════════
 * PHP has exactly one `#lineModal` and one `#lineTestModal` in the whole
 * page, opened from whichever card's button was clicked. To keep the same
 * "one shared modal" behavior while still letting LineAccountsTab.tsx render
 * the card grid itself as a Server Component (per this batch's brief), this
 * file exports `LineAccountsPanel` — a Client Component that wraps the
 * server-rendered grid (`children`) and provides a Context so the small
 * per-card client buttons below (`LineAccountActionButtons`,
 * `AddLineAccountButton`) can open ONE shared modal instance, the standard
 * Next.js "Client Component wraps Server Component children" composition
 * pattern. `apps/admin/src/components/Modal.tsx` (the shared Modal
 * primitive already used by (tenant)/pharmacists/_components/PharmacistFormModal.tsx
 * and half a dozen other tabs) is reused rather than hand-rolling another
 * `fixed inset-0 bg-black/50 …` shell.
 */

interface LineAccountsContextValue {
  openCreate: () => void;
  openEdit: (account: LineAccountRow) => void;
  openTest: (account: { id: number; name: string }) => void;
}

const LineAccountsContext = createContext<LineAccountsContextValue | null>(null);

function useLineAccountsPanel(): LineAccountsContextValue {
  const ctx = useContext(LineAccountsContext);
  if (!ctx) {
    throw new Error('useLineAccountsPanel must be used within <LineAccountsPanel>');
  }
  return ctx;
}

type FormModalState = { mode: 'closed' } | { mode: 'create' } | { mode: 'edit'; account: LineAccountRow };

type TestModalState =
  | { status: 'closed' }
  | { status: 'open'; accountId: number; accountName: string; loading: boolean; result: LineTestConnectionResult | null; clientError: string | null };

export function LineAccountsPanel({ children }: { children: ReactNode }) {
  const [formModal, setFormModal] = useState<FormModalState>({ mode: 'closed' });
  const [testModal, setTestModal] = useState<TestModalState>({ status: 'closed' });
  const [, startTransition] = useTransition();

  const openCreate = () => setFormModal({ mode: 'create' });
  const openEdit = (account: LineAccountRow) => setFormModal({ mode: 'edit', account });
  const closeFormModal = () => setFormModal({ mode: 'closed' });

  const openTest = (account: { id: number; name: string }) => {
    setTestModal({ status: 'open', accountId: account.id, accountName: account.name, loading: true, result: null, clientError: null });
    startTransition(async () => {
      try {
        const result = await testLineConnectionAction(account.id);
        setTestModal((s) => (s.status === 'open' && s.accountId === account.id ? { ...s, loading: false, result } : s));
      } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        setTestModal((s) => (s.status === 'open' && s.accountId === account.id ? { ...s, loading: false, clientError: message } : s));
      }
    });
  };
  const closeTestModal = () => setTestModal({ status: 'closed' });

  return (
    <LineAccountsContext.Provider value={{ openCreate, openEdit, openTest }}>
      {children}

      <LineAccountFormModal state={formModal} onClose={closeFormModal} />

      <Modal open={testModal.status === 'open'} onClose={closeTestModal} title="ทดสอบการเชื่อมต่อ" size="sm">
        {testModal.status === 'open' ? <LineTestResult state={testModal} /> : null}
      </Modal>
    </LineAccountsContext.Provider>
  );
}

/** Ported from testLineConnection()'s `.then()`/`.catch()` result rendering (line.php lines 486-504). */
function LineTestResult({ state }: { state: Extract<TestModalState, { status: 'open' }> }) {
  if (state.loading) {
    return (
      <div className="text-center py-8">
        <i className="fas fa-spinner fa-spin text-4xl text-gray-400" aria-hidden="true" />
        <p className="mt-3 text-gray-500">กำลังทดสอบ...</p>
      </div>
    );
  }

  // Mirrors the `.catch(err => ...)` branch — a client-side transport
  // failure calling the Server Action itself, distinct from a resolved
  // `{success:false}` JSON body (see ../_lib/line-actions.ts's module doc).
  if (state.clientError !== null) {
    return (
      <div className="text-center py-8">
        <i className="fas fa-exclamation-triangle text-6xl text-yellow-500 mb-4" aria-hidden="true" />
        <p className="text-gray-600">{state.clientError}</p>
      </div>
    );
  }

  const result = state.result;
  if (result?.success) {
    const data = (result.data ?? {}) as { displayName?: unknown; pictureUrl?: unknown };
    const displayName = typeof data.displayName === 'string' ? data.displayName : '';
    const pictureUrl = typeof data.pictureUrl === 'string' ? data.pictureUrl : '';
    return (
      <div className="text-center py-8">
        <i className="fas fa-check-circle text-6xl text-green-500 mb-4" aria-hidden="true" />
        <h3 className="text-xl font-bold text-green-600">เชื่อมต่อสำเร็จ!</h3>
        {displayName ? <p className="text-gray-600 mt-2 text-lg">{displayName}</p> : null}
        {pictureUrl ? <img src={pictureUrl} alt="" className="w-20 h-20 rounded-full mx-auto mt-4 border-4 border-green-200" /> : null}
      </div>
    );
  }

  // PHP's own client JS only ever reads `data.message` here (never
  // `data.error`) — replicated: no `.error` fallback in this branch.
  return (
    <div className="text-center py-8">
      <i className="fas fa-times-circle text-6xl text-red-500 mb-4" aria-hidden="true" />
      <h3 className="text-xl font-bold text-red-600">เชื่อมต่อไม่สำเร็จ</h3>
      <p className="text-gray-600 mt-2">{result?.message || 'กรุณาตรวจสอบ credentials'}</p>
    </div>
  );
}

/** Test / Edit(cog) / Stats — the first 3 of the card's 4 action buttons (line.php lines 133-142). Set-default (the 4th) stays a plain server-rendered `<form>` in LineAccountsTab.tsx — see that file's module doc. */
export function LineAccountActionButtons({ account }: { account: LineAccountRow }) {
  const { openEdit, openTest } = useLineAccountsPanel();

  return (
    <>
      <button
        type="button"
        onClick={() => openTest({ id: account.id, name: account.name })}
        className="p-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 text-center"
        title="ทดสอบ"
      >
        <i className="fas fa-plug" aria-hidden="true" />
      </button>
      <button
        type="button"
        onClick={() => openEdit(account)}
        className="p-2 bg-gray-50 text-gray-600 rounded-lg hover:bg-gray-100 text-center"
        title="แก้ไข"
      >
        <i className="fas fa-cog" aria-hidden="true" />
      </button>
      <button
        type="button"
        onClick={() => window.alert(`ดูสถิติบัญชี ID: ${account.id}`)}
        className="p-2 bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-100 text-center"
        title="สถิติ"
      >
        <i className="fas fa-chart-bar" aria-hidden="true" />
      </button>
    </>
  );
}

/** Ported from copyWebhook() (line.php lines 468-473). `navigator.clipboard` in place of the deprecated `document.execCommand('copy')` PHP uses — same observable outcome (webhook URL on the clipboard), same alert(). */
export function CopyWebhookButton({ webhookUrl }: { webhookUrl: string }) {
  async function handleCopy() {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(webhookUrl);
      }
    } catch {
      // Best-effort, matches PHP's own lack of error handling around execCommand('copy').
    }
    window.alert('คัดลอก Webhook URL แล้ว!');
  }

  return (
    <button type="button" onClick={handleCopy} className="px-3 bg-gray-200 hover:bg-gray-300 rounded-r text-gray-600" title="คัดลอก">
      <i className="fas fa-copy" aria-hidden="true" />
    </button>
  );
}

/** Ported from openLineModal()'s trigger button (line.php lines 49-51) and the empty-state's "เพิ่มบัญชีแรก" button (lines 168-170) — same `openCreate()` call, different label/styling per call site. */
export function AddLineAccountButton({ children, className }: { children: ReactNode; className?: string }) {
  const { openCreate } = useLineAccountsPanel();
  return (
    <button
      type="button"
      onClick={openCreate}
      className={className ?? 'px-5 py-2.5 bg-green-500 text-white rounded-lg hover:bg-green-600 shadow-lg hover:shadow-xl transition'}
    >
      {children}
    </button>
  );
}

// ---------------------------------------------------------------------------
// Create/Edit form modal
// ---------------------------------------------------------------------------

interface LineFormState {
  name: string;
  basicId: string;
  channelId: string;
  channelSecret: string;
  channelAccessToken: string;
  botMode: LineBotMode;
  welcomeMessage: string;
  autoReplyEnabled: boolean;
  shopEnabled: boolean;
  receiptPointsEnabled: boolean;
  isActive: boolean;
  isDefault: boolean;
  liffId: string;
}

/** Matches openLineModal()'s reset defaults (line.php lines 417-421): is_active/auto_reply_enabled/shop_enabled/receipt_points_enabled all default CHECKED, bot_mode defaults to the "shop" radio's `checked` attribute (line 279). */
const CREATE_DEFAULTS: LineFormState = {
  name: '',
  basicId: '',
  channelId: '',
  channelSecret: '',
  channelAccessToken: '',
  botMode: 'shop',
  welcomeMessage: '',
  autoReplyEnabled: true,
  shopEnabled: true,
  receiptPointsEnabled: true,
  isActive: true,
  isDefault: false,
  liffId: '',
};

/** Mirrors editLineAccount()'s own `account.X != 0` loose-JS-equality check (not PHP's `!empty()`): true unless the value is exactly 0/"0"/false — null/undefined (a missing column) count as CHECKED, matching JS's `undefined != 0 === true`. */
function isCheckedUnlessZero(value: number | string | boolean | null | undefined): boolean {
  if (value === false || value === 0 || value === '0') return false;
  return true;
}

/** Mirrors editLineAccount()'s `account.X == 1` loose-JS-equality check: only 1/"1"/true are checked. */
function isCheckedEqualsOne(value: number | string | boolean | null | undefined): boolean {
  return value === 1 || value === '1' || value === true;
}

function formFromAccount(account: LineAccountRow): LineFormState {
  return {
    name: account.name || '',
    basicId: account.basic_id || '',
    channelId: account.channel_id || '',
    channelSecret: account.channel_secret || '',
    channelAccessToken: account.channel_access_token || '',
    botMode: (account.bot_mode || 'shop') as LineBotMode,
    welcomeMessage: account.welcome_message || '',
    isActive: isCheckedEqualsOne(account.is_active),
    isDefault: isCheckedEqualsOne(account.is_default),
    autoReplyEnabled: isCheckedUnlessZero(account.auto_reply_enabled),
    shopEnabled: isCheckedUnlessZero(account.shop_enabled),
    receiptPointsEnabled: isCheckedUnlessZero(account.receipt_points_enabled),
    liffId: account.liff_id || '',
  };
}

const BOT_MODE_OPTIONS: { value: LineBotMode; icon: string; label: string; desc: string; ring: string }[] = [
  { value: 'shop', icon: '🛒', label: 'โหมดร้านค้า', desc: 'ระบบร้านค้าเต็มรูปแบบ: สินค้า, ตะกร้า, สั่งซื้อ, Auto Reply, Broadcast, CRM', ring: 'green' },
  { value: 'general', icon: '💬', label: 'โหมดทั่วไป', desc: 'ไม่มีระบบร้านค้า: Auto Reply, Broadcast, CRM เท่านั้น', ring: 'blue' },
  { value: 'auto_reply_only', icon: '🤖', label: 'Auto Reply เท่านั้น', desc: 'ตอบกลับอัตโนมัติตาม keyword เท่านั้น', ring: 'orange' },
];

const FIELD_LABEL = 'block text-sm font-medium mb-1';
const FIELD_INPUT = 'w-full px-4 py-2.5 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500';

function LineAccountFormModal({ state, onClose }: { state: FormModalState; onClose: () => void }) {
  const isOpen = state.mode !== 'closed';
  const isEdit = state.mode === 'edit';
  const account = state.mode === 'edit' ? state.account : null;

  const [form, setForm] = useState<LineFormState>(CREATE_DEFAULTS);
  const [activeSubTab, setActiveSubTab] = useState<'basic' | 'settings' | 'advanced'>('basic');
  const [submitting, setSubmitting] = useState(false);
  const [showSecret, setShowSecret] = useState(false);
  const [showToken, setShowToken] = useState(false);
  const [, startTransition] = useTransition();

  // Mirrors openLineModal()'s `form.reset()` + fixed checkbox defaults, and
  // editLineAccount()'s field-by-field prefill — re-run every time the
  // target (create vs. a specific account) changes.
  useEffect(() => {
    if (state.mode === 'closed') return;
    setForm(state.mode === 'edit' ? formFromAccount(state.account) : CREATE_DEFAULTS);
    setActiveSubTab('basic');
    setShowSecret(false);
    setShowToken(false);
  }, [state]);

  if (!isOpen) {
    return null;
  }

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const input: LineAccountFormInput = {
      name: form.name,
      channel_id: form.channelId,
      channel_secret: form.channelSecret,
      channel_access_token: form.channelAccessToken,
      basic_id: form.basicId,
      // Raw string, NOT `|| null` — PHP's `$_POST['liff_id'] ?? null` only ever
      // substitutes null when the key is entirely ABSENT from the request; a
      // real (always-present) blank text input submits '', which PHP's `??`
      // leaves as '' (persisted as an empty string, not SQL NULL).
      liff_id: form.liffId,
      is_default: form.isDefault,
      bot_mode: form.botMode,
      welcome_message: form.welcomeMessage,
      auto_reply_enabled: form.autoReplyEnabled,
      shop_enabled: form.shopEnabled,
      receipt_points_enabled: form.receiptPointsEnabled,
    };
    setSubmitting(true);
    startTransition(() => {
      const promise = isEdit && account ? updateLineAccountAction(account.id, { ...input, is_active: form.isActive }) : createLineAccountAction(input);
      promise.finally(() => setSubmitting(false));
    });
  }

  function handleDelete() {
    if (!account) return;
    // Ported from deleteLineAccount() (line.php lines 459-466) — same confirm() gate + Thai copy.
    if (!window.confirm('ต้องการลบบัญชีนี้? ข้อมูลทั้งหมดจะถูกลบ')) return;
    const fd = new FormData();
    fd.set('id', String(account.id));
    startTransition(() => {
      deleteLineAccountAction(fd);
    });
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={isEdit ? `ตั้งค่าบัญชี: ${account?.name ?? ''}` : 'เพิ่มบัญชี LINE'}
      size="md"
      footer={
        <>
          {isEdit ? (
            <button type="button" onClick={handleDelete} className="mr-auto px-4 py-2 text-red-500 hover:bg-red-50 rounded-lg">
              <i className="fas fa-trash mr-1" aria-hidden="true" />
              ลบบัญชี
            </button>
          ) : null}
          <button type="button" onClick={onClose} className="px-5 py-2.5 border rounded-lg hover:bg-gray-100">
            ยกเลิก
          </button>
          <button
            type="submit"
            form="lineAccountForm"
            disabled={submitting}
            className="px-5 py-2.5 bg-green-500 text-white rounded-lg hover:bg-green-600 shadow disabled:opacity-50"
          >
            <i className="fas fa-save mr-1" aria-hidden="true" />
            {submitting ? 'กำลังบันทึก...' : 'บันทึก'}
          </button>
        </>
      }
    >
      <form id="lineAccountForm" onSubmit={handleSubmit}>
        <div role="tablist" className="flex border-b bg-gray-50 -mx-5 -mt-5 mb-4">
          {(
            [
              { key: 'basic', icon: 'fa-info-circle', label: 'ข้อมูลพื้นฐาน' },
              { key: 'settings', icon: 'fa-cog', label: 'ตั้งค่า' },
              { key: 'advanced', icon: 'fa-sliders-h', label: 'ขั้นสูง' },
            ] as const
          ).map((tab) => (
            <button
              key={tab.key}
              type="button"
              role="tab"
              aria-selected={activeSubTab === tab.key}
              onClick={() => setActiveSubTab(tab.key)}
              className={`flex-1 py-3 text-sm font-medium ${
                activeSubTab === tab.key ? 'border-b-2 border-green-500 text-green-600' : 'text-gray-500'
              }`}
            >
              <i className={`fas ${tab.icon} mr-1`} aria-hidden="true" />
              {tab.label}
            </button>
          ))}
        </div>

        {/* Basic — line.php lines 206-271 */}
        <div className={activeSubTab === 'basic' ? 'space-y-4' : 'hidden'}>
          <div className="grid grid-cols-2 gap-4">
            <div className="col-span-2 md:col-span-1">
              <label className={FIELD_LABEL} htmlFor="line_name">
                ชื่อบัญชี <span className="text-red-500">*</span>
              </label>
              <input
                id="line_name"
                type="text"
                required
                className={FIELD_INPUT}
                placeholder="เช่น ร้านค้า A"
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              />
            </div>
            <div className="col-span-2 md:col-span-1">
              <label className={FIELD_LABEL} htmlFor="line_basic_id">
                LINE Basic ID
              </label>
              <input
                id="line_basic_id"
                type="text"
                className={FIELD_INPUT}
                placeholder="@yourshop"
                value={form.basicId}
                onChange={(e) => setForm((f) => ({ ...f, basicId: e.target.value }))}
              />
            </div>
          </div>

          <div>
            <label className={FIELD_LABEL} htmlFor="line_channel_id">
              Channel ID
            </label>
            <input
              id="line_channel_id"
              type="text"
              className={`${FIELD_INPUT} font-mono`}
              placeholder="1234567890"
              value={form.channelId}
              onChange={(e) => setForm((f) => ({ ...f, channelId: e.target.value }))}
            />
          </div>

          <div>
            <label className={FIELD_LABEL} htmlFor="line_channel_secret">
              Channel Secret <span className="text-red-500">*</span>
            </label>
            <div className="relative">
              <input
                id="line_channel_secret"
                type={showSecret ? 'text' : 'password'}
                required
                autoComplete="off"
                className={`${FIELD_INPUT} pr-12 font-mono text-sm`}
                value={form.channelSecret}
                onChange={(e) => setForm((f) => ({ ...f, channelSecret: e.target.value }))}
              />
              <button
                type="button"
                onClick={() => setShowSecret((s) => !s)}
                className="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-gray-500 hover:text-gray-700"
                aria-label="แสดง/ซ่อน Channel Secret"
              >
                <i className={showSecret ? 'fas fa-eye-slash' : 'fas fa-eye'} aria-hidden="true" />
              </button>
            </div>
          </div>

          <div>
            <label className={FIELD_LABEL} htmlFor="line_channel_access_token">
              Channel Access Token <span className="text-red-500">*</span>
            </label>
            <div className="relative">
              <textarea
                id="line_channel_access_token"
                required
                rows={3}
                autoComplete="off"
                className={`${FIELD_INPUT} pr-12 font-mono text-xs`}
                style={{ WebkitTextSecurity: showToken ? 'none' : 'disc' } as unknown as CSSProperties}
                value={form.channelAccessToken}
                onChange={(e) => setForm((f) => ({ ...f, channelAccessToken: e.target.value }))}
              />
              <button
                type="button"
                onClick={() => setShowToken((s) => !s)}
                className="absolute right-2 top-2 px-2 py-1 text-gray-500 hover:text-gray-700"
                aria-label="แสดง/ซ่อน Channel Access Token"
              >
                <i className={showToken ? 'fas fa-eye-slash' : 'fas fa-eye'} aria-hidden="true" />
              </button>
            </div>
          </div>

          <div className="bg-blue-50 p-4 rounded-xl">
            <p className="font-medium text-blue-700 mb-2">
              <i className="fas fa-info-circle mr-1" aria-hidden="true" />
              วิธีรับ Credentials
            </p>
            <ol className="list-decimal list-inside text-blue-600 text-sm space-y-1">
              <li>
                ไปที่{' '}
                <a href="https://developers.line.biz/console/" target="_blank" rel="noreferrer" className="underline font-medium">
                  LINE Developers Console
                </a>
              </li>
              <li>เลือก Provider → Channel (Messaging API)</li>
              <li>คัดลอก Channel ID, Channel Secret</li>
              <li>ไปที่ Messaging API → Issue Channel Access Token</li>
            </ol>
          </div>
        </div>

        {/* Settings — line.php lines 274-342 */}
        <div className={activeSubTab === 'settings' ? 'space-y-4' : 'hidden'}>
          <div>
            <label className="block text-sm font-medium mb-2">
              โหมดบอท <span className="text-red-500">*</span>
            </label>
            <div className="space-y-2">
              {BOT_MODE_OPTIONS.map((opt) => (
                <label
                  key={opt.value}
                  className={`flex items-start p-4 border-2 rounded-xl cursor-pointer transition ${
                    form.botMode === opt.value ? `border-${opt.ring}-500 bg-${opt.ring}-50` : 'hover:border-gray-300'
                  }`}
                >
                  <input
                    type="radio"
                    name="bot_mode"
                    value={opt.value}
                    checked={form.botMode === opt.value}
                    onChange={() => setForm((f) => ({ ...f, botMode: opt.value }))}
                    className="mt-1 mr-3"
                  />
                  <div>
                    <span className="font-semibold text-gray-800">
                      {opt.icon} {opt.label}
                    </span>
                    <p className="text-xs text-gray-500 mt-1">{opt.desc}</p>
                  </div>
                </label>
              ))}
            </div>
          </div>

          <div>
            <label className={FIELD_LABEL} htmlFor="line_welcome_message">
              ข้อความต้อนรับ
            </label>
            <textarea
              id="line_welcome_message"
              rows={3}
              className={FIELD_INPUT}
              placeholder="ข้อความที่จะส่งเมื่อมีคนเพิ่มเพื่อน..."
              value={form.welcomeMessage}
              onChange={(e) => setForm((f) => ({ ...f, welcomeMessage: e.target.value }))}
            />
            <p className="text-xs text-gray-500 mt-1">ใช้ {'{name}'} แทนชื่อผู้ใช้</p>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <label className="flex items-center p-4 border rounded-xl cursor-pointer hover:bg-gray-50">
              <input
                type="checkbox"
                checked={form.autoReplyEnabled}
                onChange={(e) => setForm((f) => ({ ...f, autoReplyEnabled: e.target.checked }))}
                className="mr-3 w-5 h-5 text-green-500 rounded"
              />
              <div>
                <span className="font-medium">🤖 Auto Reply</span>
                <p className="text-xs text-gray-500">เปิดระบบตอบกลับอัตโนมัติ</p>
              </div>
            </label>
            <label className="flex items-center p-4 border rounded-xl cursor-pointer hover:bg-gray-50">
              <input
                type="checkbox"
                checked={form.shopEnabled}
                onChange={(e) => setForm((f) => ({ ...f, shopEnabled: e.target.checked }))}
                className="mr-3 w-5 h-5 text-green-500 rounded"
              />
              <div>
                <span className="font-medium">🛒 ร้านค้า</span>
                <p className="text-xs text-gray-500">เปิดระบบร้านค้า</p>
              </div>
            </label>
            <label className="flex items-center p-4 border rounded-xl cursor-pointer hover:bg-gray-50">
              <input
                type="checkbox"
                checked={form.receiptPointsEnabled}
                onChange={(e) => setForm((f) => ({ ...f, receiptPointsEnabled: e.target.checked }))}
                className="mr-3 w-5 h-5 text-green-500 rounded"
              />
              <div>
                <span className="font-medium">🧾 สะสมแต้มใบเสร็จ</span>
                <p className="text-xs text-gray-500">ลูกค้าส่งรูปใบเสร็จแล้วได้แต้มอัตโนมัติ</p>
              </div>
            </label>
          </div>

          <div className="flex gap-4">
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={form.isActive}
                onChange={(e) => setForm((f) => ({ ...f, isActive: e.target.checked }))}
                className="mr-2 w-5 h-5 text-green-500 rounded"
              />
              <span>เปิดใช้งาน</span>
            </label>
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={form.isDefault}
                onChange={(e) => setForm((f) => ({ ...f, isDefault: e.target.checked }))}
                className="mr-2 w-5 h-5 text-green-500 rounded"
              />
              <span>ตั้งเป็นบัญชีหลัก</span>
            </label>
          </div>
        </div>

        {/* Advanced — line.php lines 344-374 */}
        <div className={activeSubTab === 'advanced' ? 'space-y-4' : 'hidden'}>
          <div className="bg-green-50 p-4 rounded-xl mb-4">
            <p className="font-medium text-green-700 mb-2">
              <i className="fas fa-magic mr-1" aria-hidden="true" />
              Unified LIFF (แนะนำ)
            </p>
            <p className="text-green-600 text-sm">ใช้ LIFF ID เดียวสำหรับทุกฟังก์ชัน - สมัครสมาชิก, ซื้อสินค้า, แลกแต้ม, นัดหมาย ฯลฯ</p>
          </div>

          <div className="p-5 border-2 border-green-300 rounded-xl bg-gradient-to-br from-green-50 to-emerald-50">
            <label className="block text-sm font-medium mb-2 text-green-700" htmlFor="line_liff_id">
              <i className="fas fa-mobile-alt mr-1" aria-hidden="true" />
              LIFF ID (Unified)
            </label>
            <input
              id="line_liff_id"
              type="text"
              className="w-full px-4 py-3 border-2 border-green-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 font-mono text-lg"
              placeholder="2006xxxxxx-xxxxxxxx"
              value={form.liffId}
              onChange={(e) => setForm((f) => ({ ...f, liffId: e.target.value }))}
            />
          </div>

          <div className="bg-yellow-50 p-4 rounded-xl">
            <p className="font-medium text-yellow-700 mb-2">
              <i className="fas fa-lightbulb mr-1" aria-hidden="true" />
              วิธีสร้าง LIFF App
            </p>
            <ol className="text-yellow-600 text-sm list-decimal list-inside space-y-1">
              <li>
                ไปที่{' '}
                <a href="https://developers.line.biz/console/" target="_blank" rel="noreferrer" className="underline font-medium">
                  LINE Developers Console
                </a>
              </li>
              <li>เลือก Provider → Channel (LINE Login)</li>
              <li>ไปที่ LIFF → Add</li>
              <li>Scopes: openid, profile</li>
              <li>คัดลอก LIFF ID มาใส่ในช่องด้านบน</li>
            </ol>
          </div>
        </div>
      </form>
    </Modal>
  );
}
