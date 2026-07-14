'use client';

import { useMemo, useState, type ReactNode } from 'react';
import { saveWelcomeSettingsAction } from '../_lib/welcome-actions';
import type { WelcomeSettings } from '../_lib/welcome-queries';

/**
 * WelcomeMessageForm — the small Client Component "island" for
 * includes/settings/welcome.php's `<form id="welcomeForm">` (lines 49-115)
 * plus its inline `<script>` (lines 117-271). WelcomeTab.tsx (the Server
 * Component wrapper) fetches `WelcomeSettings` server-side and renders the
 * header + enable-toggle checkbox around this component; this component
 * owns only the interactive parts PHP's own `<script>` drove client-side:
 *
 *   - toggleMessageType(type)  -> radio-driven show/hide of the text vs
 *     flex sections (React state instead of `classList.toggle('hidden')`)
 *   - updateFlexPreview()      -> live JSON.parse + bubble preview render,
 *     re-run on every keystroke (`oninput`) via a `useMemo` on `flexContent`
 *     state instead of `document.getElementById(...).innerHTML = ...`
 *   - loadFlexTemplate(type)   -> the two canned templates ("welcome"/
 *     "promo"), verbatim JSON, loaded into the textarea + preview
 *   - validateFlexJson()       -> `JSON.parse` + `alert(...)`, same ✅/❌
 *     copy as PHP
 *
 * Imports `saveWelcomeSettingsAction` directly (same convention
 * (tenant)/line-groups/_components/LineGroupRow.tsx already established:
 * a 'use client' component importing a 'use server' action straight from
 * its actions module, rather than the parent Server Component prop-drilling
 * a function reference down) — `<form id="welcomeForm" action={...}>`.
 *
 * The enable-toggle checkbox intentionally lives OUTSIDE this component (in
 * WelcomeTab.tsx), referencing this form by `form="welcomeForm"` (a plain
 * HTML5 cross-element form association) — mirrors the PHP markup exactly,
 * where the toggle sits in a header row ABOVE `<form id="welcomeForm">` but
 * still submits with it via the same `form="welcomeForm"` attribute
 * (welcome.php line 43).
 */

interface FlexTextNode {
  type: 'text';
  text?: string;
  size?: string;
  weight?: string;
  color?: string;
}

interface FlexButtonNode {
  type: 'button';
  style?: string;
  action?: { label?: string };
}

interface FlexBoxNode {
  type: 'box';
  layout?: string;
  contents?: FlexNode[];
}

interface FlexImageNode {
  type: 'image';
  url?: string;
}

type FlexNode = FlexTextNode | FlexButtonNode | FlexBoxNode | FlexImageNode | { type?: string; [key: string]: unknown };

interface FlexBubble {
  type?: string;
  header?: { contents?: FlexNode[] };
  hero?: { url?: string };
  body?: { contents?: FlexNode[] };
  footer?: { contents?: FlexNode[] };
}

const WELCOME_TEMPLATE: FlexBubble = {
  type: 'bubble',
  header: {
    contents: [{ type: 'text', text: '🎉 ยินดีต้อนรับ!', weight: 'bold', size: 'xl', color: '#ffffff' }],
  },
  body: {
    contents: [
      { type: 'text', text: 'สวัสดีค่ะ {name}', weight: 'bold', size: 'lg' },
      { type: 'text', text: 'ขอบคุณที่ติดตามร้านของเรา', size: 'sm', color: '#666666' },
      { type: 'text', text: "พิมพ์ 'เมนู' เพื่อดูบริการของเรา", size: 'sm', color: '#888888' },
    ],
  },
  footer: {
    contents: [{ type: 'button', style: 'primary', action: { label: 'ดูสินค้า' } }],
  },
};

const PROMO_TEMPLATE: FlexBubble = {
  type: 'bubble',
  header: {
    contents: [{ type: 'text', text: '🎁 โปรโมชั่นพิเศษ!', weight: 'bold', size: 'xl', color: '#ffffff' }],
  },
  body: {
    contents: [
      { type: 'text', text: 'ลด 20% สำหรับสมาชิกใหม่', weight: 'bold', size: 'lg' },
      { type: 'text', text: 'ใช้โค้ด: WELCOME20', size: 'md', color: '#FF5722' },
      { type: 'text', text: 'หมดเขต 31 ม.ค. 2026', size: 'sm', color: '#888888' },
    ],
  },
  footer: {
    contents: [{ type: 'button', style: 'primary', action: { label: 'ช้อปเลย!' } }],
  },
};

function textSizeClass(size: string | undefined): string {
  if (size === 'xl') return 'text-xl';
  if (size === 'lg') return 'text-lg';
  if (size === 'sm') return 'text-sm';
  return 'text-base';
}

function renderFlexContents(contents: FlexNode[] | undefined): ReactNode[] {
  return (contents ?? []).map((item, i) => {
    if (item.type === 'text') {
      const node = item as FlexTextNode;
      return (
        <p key={i} className={`${textSizeClass(node.size)} ${node.weight === 'bold' ? 'font-bold' : ''}`} style={node.color ? { color: node.color } : undefined}>
          {node.text ?? ''}
        </p>
      );
    }
    if (item.type === 'button') {
      const node = item as FlexButtonNode;
      const styleClass = node.style === 'primary' ? 'bg-green-500 text-white' : 'border border-green-500 text-green-600';
      return (
        <button key={i} type="button" disabled className={`w-full py-2 rounded-lg ${styleClass} text-sm mt-2`}>
          {node.action?.label ?? 'Button'}
        </button>
      );
    }
    if (item.type === 'box') {
      const node = item as FlexBoxNode;
      return (
        <div key={i} className={node.layout === 'horizontal' ? 'flex justify-between' : ''}>
          {renderFlexContents(node.contents)}
        </div>
      );
    }
    if (item.type === 'image') {
      const node = item as FlexImageNode;
      return node.url ? <img key={i} src={node.url} alt="" className="w-full rounded-lg" /> : null;
    }
    return null;
  });
}

function renderFlexPreview(json: string): ReactNode {
  if (!json.trim()) {
    return <p className="text-gray-400 text-center py-8">ใส่ JSON เพื่อดูตัวอย่าง</p>;
  }

  let data: FlexBubble;
  try {
    data = JSON.parse(json) as FlexBubble;
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    return (
      <p className="text-red-500 text-center py-8">
        <i className="fas fa-exclamation-triangle mr-2" aria-hidden="true" />
        JSON ไม่ถูกต้อง: {message}
      </p>
    );
  }

  return (
    <div className="bg-white rounded-xl shadow border overflow-hidden max-w-xs mx-auto">
      {data.type === 'bubble' ? (
        <>
          {data.header ? <div className="bg-gradient-to-r from-green-500 to-green-600 text-white p-4">{renderFlexContents(data.header.contents)}</div> : null}
          {data.hero?.url ? <img src={data.hero.url} alt="" className="w-full h-40 object-cover" /> : null}
          {data.body ? <div className="p-4">{renderFlexContents(data.body.contents)}</div> : null}
          {data.footer ? <div className="p-3 border-t bg-gray-50">{renderFlexContents(data.footer.contents)}</div> : null}
        </>
      ) : (
        <div className="p-4 text-gray-500">รองรับเฉพาะ type: bubble</div>
      )}
    </div>
  );
}

export interface WelcomeMessageFormProps {
  settings: WelcomeSettings;
}

export function WelcomeMessageForm({ settings }: WelcomeMessageFormProps) {
  const [messageType, setMessageType] = useState<'text' | 'flex'>(settings.messageType);
  const [flexContent, setFlexContent] = useState(settings.flexContent);

  const preview = useMemo(() => renderFlexPreview(flexContent), [flexContent]);

  function loadFlexTemplate(kind: 'welcome' | 'promo') {
    const template = kind === 'welcome' ? WELCOME_TEMPLATE : PROMO_TEMPLATE;
    setFlexContent(JSON.stringify(template, null, 2));
  }

  function validateFlexJson() {
    try {
      JSON.parse(flexContent);
      window.alert('✅ JSON ถูกต้อง!');
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      window.alert(`❌ JSON ไม่ถูกต้อง: ${message}`);
    }
  }

  return (
    <form id="welcomeForm" action={saveWelcomeSettingsAction}>
      <input type="hidden" name="tab" value="welcome" />

      <div className="mb-6">
        <label className="block text-sm font-medium text-gray-700 mb-2">ประเภทข้อความ</label>
        <div className="flex gap-4">
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="radio"
              name="message_type"
              value="text"
              checked={messageType === 'text'}
              onChange={() => setMessageType('text')}
              className="w-4 h-4 text-green-600"
            />
            <span>ข้อความธรรมดา</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="radio"
              name="message_type"
              value="flex"
              checked={messageType === 'flex'}
              onChange={() => setMessageType('flex')}
              className="w-4 h-4 text-green-600"
            />
            <span>Flex Message</span>
          </label>
        </div>
      </div>

      <div className={`mb-6 ${messageType === 'flex' ? 'hidden' : ''}`}>
        <label className="block text-sm font-medium text-gray-700 mb-2">ข้อความต้อนรับ</label>
        <textarea
          name="text_content"
          rows={4}
          defaultValue={settings.textContent}
          className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
          placeholder="สวัสดีค่ะ ยินดีต้อนรับสู่ร้านของเรา..."
        />
        <p className="text-xs text-gray-500 mt-1">รองรับ Emoji และขึ้นบรรทัดใหม่ได้</p>
      </div>

      <div className={`mb-6 ${messageType === 'text' ? 'hidden' : ''}`}>
        <label className="block text-sm font-medium text-gray-700 mb-2">Flex Message JSON</label>
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <textarea
              name="flex_content"
              value={flexContent}
              onChange={(e) => setFlexContent(e.target.value)}
              rows={15}
              className="w-full px-4 py-3 border border-gray-300 rounded-lg font-mono text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
              placeholder='{"type": "bubble", "body": {...}}'
            />
            <div className="flex gap-2 mt-2">
              <button type="button" onClick={() => loadFlexTemplate('welcome')} className="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-sm hover:bg-blue-200">
                <i className="fas fa-magic mr-1" aria-hidden="true" />
                Template ต้อนรับ
              </button>
              <button type="button" onClick={() => loadFlexTemplate('promo')} className="px-3 py-1.5 bg-purple-100 text-purple-700 rounded-lg text-sm hover:bg-purple-200">
                <i className="fas fa-gift mr-1" aria-hidden="true" />
                Template โปรโมชั่น
              </button>
              <button type="button" onClick={validateFlexJson} className="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">
                <i className="fas fa-check mr-1" aria-hidden="true" />
                ตรวจสอบ JSON
              </button>
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">ตัวอย่าง Preview</label>
            <div className="border rounded-lg p-4 bg-gray-50 min-h-[300px] overflow-auto">{preview}</div>
          </div>
        </div>
      </div>

      <div className="flex justify-end">
        <button type="submit" className="px-6 py-2.5 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium">
          <i className="fas fa-save mr-2" aria-hidden="true" />
          บันทึกการตั้งค่า
        </button>
      </div>
    </form>
  );
}
