'use client';

import Script from 'next/script';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { CatalogBuilderCategory, CatalogBuilderProduct } from '../_lib/catalog-queries';

/**
 * CatalogBuilderClient — the 'use client' interactive half of
 * includes/broadcast/catalog.php's (662 LOC) drag-and-drop bubble builder.
 * Server-fetched `products`/`categories` (../_components/CatalogTab.tsx,
 * already trimmed to catalog.php's own `$productsJson` shape) come in as
 * plain props; everything below ports catalog.php's `<script>` (lines
 * 224-661) to React state + two real global scripts loaded exactly the way
 * the PHP page loads them today:
 *
 *   - SortableJS, from the SAME jsdelivr CDN URL catalog.php's own
 *     `<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/
 *     Sortable.min.js">` uses (line 42) — never bundled/vendored.
 *   - assets/js/flex-preview.js (line 43), the SAME static asset path,
 *     loaded as a global script the same way — `FlexPreview.render()` stays
 *     an imperative `window.FlexPreview` call into a DOM container this
 *     component does NOT otherwise touch while data is non-empty (mirrors
 *     catalog.php's `updatePreview()` exactly: React owns the empty-state
 *     markup, `FlexPreview.render()` owns the populated markup — the two
 *     never fight over the same paint).
 *
 * MUTATIONS: `saveDraft()`/`openDraftPicker()`/`loadDraft()`/`deleteDraft()`/
 * `sendBroadcast()` below call `fetch('/api/broadcast_drafts.php?action=...')`
 * and `fetch('/api/broadcast.php', {method:'POST', body: JSON.stringify({
 * action:'send_flex', ...})})` — the SAME pre-existing PHP endpoints
 * catalog.php's own JS calls today, verbatim, at absolute paths (robust
 * against future route nesting; behaviorally identical to PHP's bare
 * relative `fetch('api/broadcast_drafts.php?...')`, which resolves the same
 * way against `/broadcast` as it did against `/broadcast.php` — see
 * infra/nginx/routes.json's `/` catch-all, unmodified by this batch, which
 * already routes any `/api/broadcast*.php` path to `php_backend`). NOT
 * reimplemented as Next Route Handlers or Server Actions — out of this
 * batch's scope entirely.
 *
 * CONFIRMED FINDING, FLAGGED — NOT REPRODUCED (catalog.php lines 259-271):
 * every bubble zone's `onAdd` handler unconditionally reads
 * `evt.item.dataset.id` to reconstruct the dropped product. That attribute
 * only exists on the LEFT-PANEL product-list rows (`data-id="..."`,
 * catalog.php line 80) — a `.bubble-product` element dragged from ANOTHER
 * bubble only carries `data-product-id` (line 214), never `data-id`. So in
 * real PHP, dragging a product from one already-built bubble into another
 * corrupts it: `createBubbleProduct({id: undefined, name: undefined, price:
 * undefined, image: undefined})` renders a permanently-stuck "undefined" /
 * "฿NaN" / broken-image row (`.textContent = undefined` stringifies to
 * `"undefined"`; `Number(undefined).toLocaleString()` is `"NaN"`), AND —
 * because `getBubblesData()` re-resolves each bubble-product's id via
 * `allProducts.find(pr => pr.id == id)`, and the corrupted node's
 * `data-product-id` is itself the string `"undefined"` (never matches any
 * real numeric product id) — that corrupted row is silently EXCLUDED from
 * every subsequent Preview/Save/Send, while the broken row itself lingers
 * on-screen forever (nothing ever removes it). This bug (a) requires real
 * browser pointer-drag events to trigger — unreachable in this batch's jsdom
 * test suite either way, and (b) reproducing its FULL effect (a
 * DOM node permanently orphaned from state) would fight this component's
 * "React state is the source of truth" architecture on purpose, for a
 * behavior with no positive value to preserve. This port instead treats a
 * bubble-to-bubble drag as a normal, correct move (see `resolveDraggedProduct()`
 * below, which resolves BOTH a source-list `data-id` and a bubble
 * `data-product-id` against the same product map) — a deliberate,
 * documented decision, not a silent divergence.
 */

// ---------------------------------------------------------------------------
// Pure data types + helpers (ported 1:1 from catalog.php's <script>)
// ---------------------------------------------------------------------------

export type CatalogLayoutKey = '2x2' | '2x3' | '3x3' | '3x4';

const LAYOUT_CONFIG: Record<CatalogLayoutKey, { cols: number; rows: number; max: number }> = {
  '2x2': { cols: 2, rows: 2, max: 4 },
  '2x3': { cols: 2, rows: 3, max: 6 },
  '3x3': { cols: 3, rows: 3, max: 9 },
  '3x4': { cols: 3, rows: 4, max: 12 },
};

const DEFAULT_LAYOUT: CatalogLayoutKey = '3x3';
const DEFAULT_THEME = '#06C755';
const DEFAULT_BUBBLE_TITLE = 'สินค้าแนะนำ';
const PREVIEW_CONTAINER_ID = 'broadcastCatalogPreviewBox';

/** A product entry sitting inside a bubble — always well-formed here (see module doc's "CONFIRMED FINDING"). */
export interface BubbleProductEntry {
  id: number;
  name: string;
  price: number;
  image: string;
}

export interface BubbleState {
  id: number;
  title: string;
  products: BubbleProductEntry[];
}

export interface FlexBubbleData {
  title: string;
  products: BubbleProductEntry[];
  layout: CatalogLayoutKey;
  theme: string;
}

/** catalog.php lines 328-334 `adjustColor()`. */
function adjustColor(color: string, amount: number): string {
  const hex = color.replace('#', '');
  const clamp = (n: number) => Math.max(0, Math.min(255, n));
  const r = clamp(parseInt(hex.substring(0, 2), 16) + amount);
  const g = clamp(parseInt(hex.substring(2, 4), 16) + amount);
  const b = clamp(parseInt(hex.substring(4, 6), 16) + amount);
  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
}

/** catalog.php lines 350-365 `getBubblesData()` — only bubbles with >=1 product are included. */
export function getBubblesData(bubbles: BubbleState[], layout: CatalogLayoutKey, theme: string): FlexBubbleData[] {
  const data: FlexBubbleData[] = [];
  for (const bubble of bubbles) {
    if (bubble.products.length > 0) {
      data.push({ title: bubble.title || DEFAULT_BUBBLE_TITLE, products: bubble.products, layout, theme });
    }
  }
  return data;
}

/** catalog.php lines 385-463 `buildFlexFromData()`, ported 1:1 (field names, fallback image, truncation). */
export function buildFlexFromData(bubblesData: FlexBubbleData[], layout: CatalogLayoutKey): unknown {
  const cfg = LAYOUT_CONFIG[layout];
  const flexBubbles = bubblesData.map((bubble) => {
    const products = bubble.products.slice(0, cfg.max);
    const rows: unknown[] = [];
    for (let i = 0; i < products.length; i += cfg.cols) {
      const rowItems = products.slice(i, i + cfg.cols);
      const rowContents: unknown[] = rowItems.map((p) => {
        let imageUrl = p.image || 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/01_1_cafe.png';
        if (imageUrl.includes('via.placeholder.com')) {
          imageUrl = 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/01_1_cafe.png';
        }
        const name = p.name;
        return {
          type: 'box',
          layout: 'vertical',
          flex: 1,
          spacing: 'xs',
          paddingAll: 'xs',
          contents: [
            { type: 'image', url: imageUrl, size: 'full', aspectRatio: '1:1', aspectMode: 'cover' },
            { type: 'text', text: name.length > 10 ? name.slice(0, 10) + '..' : name, size: 'xxs', color: '#333333', wrap: false },
            { type: 'text', text: '฿' + Number(p.price).toLocaleString(), size: 'xs', color: bubble.theme, weight: 'bold' },
          ],
        };
      });
      while (rowContents.length < cfg.cols) {
        rowContents.push({ type: 'box', layout: 'vertical', contents: [], flex: 1 });
      }
      rows.push({ type: 'box', layout: 'horizontal', contents: rowContents, spacing: 'sm' });
    }
    return {
      type: 'bubble',
      header: {
        type: 'box',
        layout: 'horizontal',
        paddingAll: 'lg',
        backgroundColor: bubble.theme + '15',
        contents: [
          { type: 'text', text: bubble.title, weight: 'bold', size: 'md', color: bubble.theme, flex: 1 },
          { type: 'text', text: products.length + ' รายการ', size: 'xs', color: '#888888', align: 'end' },
        ],
      },
      body: { type: 'box', layout: 'vertical', contents: rows, spacing: 'sm', paddingAll: 'md' },
      footer: {
        type: 'box',
        layout: 'horizontal',
        paddingAll: 'md',
        contents: [
          {
            type: 'button',
            action: { type: 'message', label: '🛒 ดูทั้งหมด', text: 'shop' },
            style: 'primary',
            color: bubble.theme,
            height: 'sm',
          },
        ],
      },
    };
  });
  return flexBubbles.length === 1 ? flexBubbles[0] : { type: 'carousel', contents: flexBubbles };
}

// ---------------------------------------------------------------------------
// Minimal local typings for the two globals loaded via next/script
// ---------------------------------------------------------------------------

interface SortableEventLike {
  item: HTMLElement;
  newIndex?: number;
  oldIndex?: number;
}
interface SortableInstanceLike {
  destroy(): void;
}
interface SortableGroupOptionsLike {
  name: string;
  pull?: boolean | 'clone';
  put?: boolean;
}
interface SortableOptionsLike {
  group?: string | SortableGroupOptionsLike;
  sort?: boolean;
  animation?: number;
  onAdd?: (evt: SortableEventLike) => void;
  onUpdate?: (evt: SortableEventLike) => void;
  onRemove?: (evt: SortableEventLike) => void;
}
type SortableConstructorLike = new (el: HTMLElement, options: SortableOptionsLike) => SortableInstanceLike;

interface FlexPreviewLike {
  render(containerId: string, flex: unknown): void;
}

function getSortableCtor(): SortableConstructorLike | undefined {
  return (window as unknown as { Sortable?: SortableConstructorLike }).Sortable;
}

function getFlexPreviewGlobal(): FlexPreviewLike | undefined {
  return (window as unknown as { FlexPreview?: FlexPreviewLike }).FlexPreview;
}

// ---------------------------------------------------------------------------
// Draft API response shapes (api/broadcast_drafts.php — out of this batch's
// scope, consumed as-is; shapes below are the minimal subset this component
// reads, not a full contract of that endpoint).
// ---------------------------------------------------------------------------

interface DraftPayload {
  bubbles?: Array<{ title?: string; products?: BubbleProductEntry[] }>;
  layout?: string;
  theme?: string;
}

interface DraftListEntry {
  id: number;
  name: string;
  updated_at?: string | null;
  created_at?: string | null;
}

interface DraftDetail {
  id: number;
  name: string;
  payload?: DraftPayload;
}

let bubbleIdSeed = 0;
function nextBubbleId(): number {
  bubbleIdSeed += 1;
  return bubbleIdSeed;
}
function makeEmptyBubble(): BubbleState {
  return { id: nextBubbleId(), title: DEFAULT_BUBBLE_TITLE, products: [] };
}

export interface CatalogBuilderClientProps {
  products: CatalogBuilderProduct[];
  categories: CatalogBuilderCategory[];
}

export function CatalogBuilderClient({ products, categories }: CatalogBuilderClientProps) {
  // ---- product panel: search + category filter (catalog.php filterProducts()) ----
  const [search, setSearch] = useState('');
  const [catFilter, setCatFilter] = useState('');

  const filteredProducts = useMemo(() => {
    const q = search.toLowerCase();
    return products.filter((p) => {
      const nameMatches = p.name.toLowerCase().includes(q);
      const catMatches = !catFilter || String(p.categoryId ?? '') === catFilter;
      return nameMatches && catMatches;
    });
  }, [products, search, catFilter]);

  const productsByIdRef = useRef(new Map<number, CatalogBuilderProduct>());
  useEffect(() => {
    productsByIdRef.current = new Map(products.map((p) => [p.id, p]));
  }, [products]);

  // ---- bubble builder state ----
  const [bubbles, setBubbles] = useState<BubbleState[]>(() => [makeEmptyBubble()]);
  const [layout, setLayoutState] = useState<CatalogLayoutKey>(DEFAULT_LAYOUT);
  const [theme, setThemeState] = useState(DEFAULT_THEME);

  function addBubble() {
    setBubbles((prev) => [...prev, makeEmptyBubble()]);
  }
  function removeBubble(bubbleId: number) {
    setBubbles((prev) => prev.filter((b) => b.id !== bubbleId));
  }
  function removeProduct(bubbleId: number, index: number) {
    setBubbles((prev) =>
      prev.map((b) => (b.id === bubbleId ? { ...b, products: b.products.filter((_, i) => i !== index) } : b))
    );
  }
  function renameBubble(bubbleId: number, title: string) {
    setBubbles((prev) => prev.map((b) => (b.id === bubbleId ? { ...b, title } : b)));
  }
  function setLayout(next: CatalogLayoutKey) {
    setLayoutState(next);
  }
  function setTheme(next: string) {
    setThemeState(next);
  }

  const bubblesData = useMemo(() => getBubblesData(bubbles, layout, theme), [bubbles, layout, theme]);
  const totalProducts = bubblesData.reduce((sum, b) => sum + b.products.length, 0);

  // ---- SortableJS wiring ----
  const [sortableLoaded, setSortableLoaded] = useState(false);
  const [flexPreviewLoaded, setFlexPreviewLoaded] = useState(false);

  const productListElRef = useRef<HTMLDivElement | null>(null);
  const productListSortableRef = useRef<SortableInstanceLike | null>(null);
  const zoneElsRef = useRef(new Map<number, HTMLDivElement>());
  const zoneSortablesRef = useRef(new Map<number, SortableInstanceLike>());

  function zoneRef(bubbleId: number) {
    return (el: HTMLDivElement | null) => {
      if (el) zoneElsRef.current.set(bubbleId, el);
      else zoneElsRef.current.delete(bubbleId);
    };
  }

  /** Resolves a dragged item's product data — see module doc's "CONFIRMED FINDING" for why this checks BOTH attribute names. */
  function resolveDraggedProduct(item: HTMLElement): CatalogBuilderProduct | null {
    const rawId = item.dataset['id'] ?? item.dataset['productId'];
    if (rawId === undefined) return null;
    const id = Number(rawId);
    return productsByIdRef.current.get(id) ?? null;
  }

  // Product source list — catalog.php lines 237-242: `{group: {name:'products', pull:'clone', put:false}, sort:false}`.
  useEffect(() => {
    if (!sortableLoaded || !productListElRef.current) return undefined;
    const SortableCtor = getSortableCtor();
    if (!SortableCtor) return undefined;
    const instance = new SortableCtor(productListElRef.current, {
      group: { name: 'products', pull: 'clone', put: false },
      sort: false,
      animation: 150,
    });
    productListSortableRef.current = instance;
    return () => {
      instance.destroy();
      productListSortableRef.current = null;
    };
  }, [sortableLoaded]);

  // Bubble zones — reconciled on every bubbles change so a newly-added bubble's
  // zone (mounted this render) gets a Sortable instance promptly.
  useEffect(() => {
    if (!sortableLoaded) return;
    const SortableCtor = getSortableCtor();
    if (!SortableCtor) return;

    const currentIds = new Set(bubbles.map((b) => b.id));
    for (const [id, instance] of zoneSortablesRef.current) {
      if (!currentIds.has(id)) {
        instance.destroy();
        zoneSortablesRef.current.delete(id);
      }
    }
    for (const id of currentIds) {
      if (zoneSortablesRef.current.has(id)) continue;
      const el = zoneElsRef.current.get(id);
      if (!el) continue;
      const instance = new SortableCtor(el, {
        group: 'products',
        animation: 150,
        onAdd: (evt) => {
          const product = resolveDraggedProduct(evt.item);
          evt.item.remove();
          if (!product) return;
          const index = evt.newIndex ?? Number.MAX_SAFE_INTEGER;
          setBubbles((prev) =>
            prev.map((b) => {
              if (b.id !== id) return b;
              const entry: BubbleProductEntry = { id: product.id, name: product.name, price: product.price, image: product.image };
              const nextProducts = [...b.products];
              nextProducts.splice(Math.min(index, nextProducts.length), 0, entry);
              return { ...b, products: nextProducts };
            })
          );
        },
        onUpdate: (evt) => {
          const from = evt.oldIndex;
          const to = evt.newIndex;
          if (from === undefined || to === undefined || from === to) return;
          setBubbles((prev) =>
            prev.map((b) => {
              if (b.id !== id) return b;
              const nextProducts = [...b.products];
              const [moved] = nextProducts.splice(from, 1);
              if (moved) nextProducts.splice(to, 0, moved);
              return { ...b, products: nextProducts };
            })
          );
        },
        onRemove: (evt) => {
          const from = evt.oldIndex;
          if (from === undefined) return;
          setBubbles((prev) =>
            prev.map((b) => (b.id === id ? { ...b, products: b.products.filter((_, i) => i !== from) } : b))
          );
        },
      });
      zoneSortablesRef.current.set(id, instance);
    }
  }, [sortableLoaded, bubbles]);

  useEffect(() => {
    return () => {
      for (const instance of zoneSortablesRef.current.values()) instance.destroy();
      zoneSortablesRef.current.clear();
    };
  }, []);

  // ---- Flex preview (catalog.php updatePreview(), lines 367-383) ----
  useEffect(() => {
    if (!flexPreviewLoaded || bubblesData.length === 0) return;
    const FlexPreviewGlobal = getFlexPreviewGlobal();
    if (!FlexPreviewGlobal) return;
    const flex = buildFlexFromData(bubblesData, layout);
    FlexPreviewGlobal.render(PREVIEW_CONTAINER_ID, flex);
  }, [flexPreviewLoaded, bubblesData, layout]);

  // ---- Draft save/load/delete (catalog.php lines 465-627) ----
  const [draftName, setDraftName] = useState('');
  const [draftId, setDraftId] = useState(0);
  const [draftStatus, setDraftStatus] = useState<{ text: string; isError: boolean } | null>(null);
  const [draftPickerOpen, setDraftPickerOpen] = useState(false);
  const [draftList, setDraftList] = useState<DraftListEntry[] | null>(null);
  const [draftListError, setDraftListError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  useEffect(() => {
    if (!draftStatus || draftStatus.isError || draftStatus.text === '') return;
    const timer = setTimeout(() => setDraftStatus(null), 3000);
    return () => clearTimeout(timer);
  }, [draftStatus]);

  useEffect(() => {
    if (!draftPickerOpen) return;
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') setDraftPickerOpen(false);
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [draftPickerOpen]);

  function collectDraftPayload() {
    return { bubbles: bubblesData, layout, theme, savedAt: new Date().toISOString() };
  }

  async function saveDraft() {
    const name = draftName.trim();
    if (!name) {
      window.alert('กรุณาตั้งชื่อ Draft ก่อน');
      return;
    }
    const payload = collectDraftPayload();
    if (payload.bubbles.length === 0) {
      if (!window.confirm('ยังไม่มีสินค้าใน Bubble — บันทึกเป็น Draft ว่างหรือไม่?')) return;
    }

    setDraftStatus({ text: 'กำลังบันทึก…', isError: false });
    try {
      const res = await fetch('/api/broadcast_drafts.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', id: draftId, name, payload, source: 'catalog' }),
      });
      const data = (await res.json()) as { success: boolean; id?: number; error?: string };
      if (!data.success) throw new Error(data.error || 'Save failed');
      setDraftId(data.id ?? 0);
      setDraftStatus({ text: 'บันทึกแล้ว ✓', isError: false });
    } catch (e) {
      setDraftStatus({ text: 'บันทึกไม่สำเร็จ: ' + (e instanceof Error ? e.message : String(e)), isError: true });
    }
  }

  async function refreshDraftList() {
    setDraftList(null);
    setDraftListError(null);
    try {
      const res = await fetch('/api/broadcast_drafts.php?action=list');
      const data = (await res.json()) as { success: boolean; drafts?: DraftListEntry[]; error?: string };
      if (!data.success) throw new Error(data.error || 'Failed to list');
      setDraftList(data.drafts ?? []);
    } catch (e) {
      setDraftList([]);
      setDraftListError(e instanceof Error ? e.message : String(e));
    }
  }

  function openDraftPicker() {
    setDraftPickerOpen(true);
    void refreshDraftList();
  }
  function closeDraftPicker() {
    setDraftPickerOpen(false);
  }

  async function loadDraft(id: number) {
    try {
      const res = await fetch('/api/broadcast_drafts.php?action=load&id=' + encodeURIComponent(String(id)));
      const data = (await res.json()) as { success: boolean; draft?: DraftDetail; error?: string };
      if (!data.success || !data.draft) throw new Error(data.error || 'Load failed');
      applyDraftPayload(data.draft);
      closeDraftPicker();
      setDraftStatus({ text: 'โหลด Draft แล้ว ✓', isError: false });
    } catch (e) {
      window.alert('โหลดไม่สำเร็จ: ' + (e instanceof Error ? e.message : String(e)));
    }
  }

  async function deleteDraft(id: number) {
    if (!window.confirm('ลบ Draft นี้?')) return;
    try {
      const res = await fetch('/api/broadcast_drafts.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id }),
      });
      const data = (await res.json()) as { success: boolean; error?: string };
      if (!data.success) throw new Error(data.error || 'Delete failed');
      await refreshDraftList();
    } catch (e) {
      window.alert('ลบไม่สำเร็จ: ' + (e instanceof Error ? e.message : String(e)));
    }
  }

  function applyDraftPayload(draft: DraftDetail) {
    setDraftId(draft.id);
    setDraftName(draft.name || '');

    const p = draft.payload ?? {};
    if (p.layout && p.layout in LAYOUT_CONFIG) setLayout(p.layout as CatalogLayoutKey);
    if (p.theme) setTheme(p.theme);

    const incoming = Array.isArray(p.bubbles) ? p.bubbles : [];
    if (incoming.length === 0) {
      setBubbles([makeEmptyBubble()]);
      return;
    }
    setBubbles(
      incoming.map((b) => ({
        id: nextBubbleId(),
        title: b.title || DEFAULT_BUBBLE_TITLE,
        products: Array.isArray(b.products) ? b.products : [],
      }))
    );
  }

  async function sendBroadcast() {
    const data = getBubblesData(bubbles, layout, theme);
    if (data.length === 0) {
      window.alert('กรุณาเพิ่มสินค้าใน Bubble');
      return;
    }
    if (!window.confirm(`ส่ง ${data.length} bubbles?`)) return;

    setSending(true);
    try {
      const flex = buildFlexFromData(data, layout);
      const response = await fetch('/api/broadcast.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'send_flex', flex, altText: data[0]!.title }),
      });
      const result = (await response.json()) as { success: boolean; sent?: number; error?: string };
      if (result.success) {
        window.alert(result.sent ? `✅ ส่งสำเร็จ! (${result.sent} คน)` : '✅ ส่ง Broadcast สำเร็จ!');
      } else {
        window.alert('❌ Error: ' + (result.error || 'Unknown error'));
      }
    } catch (e) {
      window.alert('❌ Error: ' + (e instanceof Error ? e.message : String(e)));
    }
    setSending(false);
  }

  return (
    <div className="grid grid-cols-1 xl:grid-cols-12 gap-4">
      <Script
        src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"
        strategy="afterInteractive"
        onLoad={() => setSortableLoaded(true)}
      />
      <Script src="/assets/js/flex-preview.js" strategy="afterInteractive" onLoad={() => setFlexPreviewLoaded(true)} />

      {/* Left: Products Panel */}
      <div className="xl:col-span-3 bg-white rounded-xl shadow">
        <div className="p-3 border-b">
          <div className="flex items-center justify-between mb-2">
            <span className="font-semibold">
              <i className="fas fa-box text-green-500 mr-1" aria-hidden="true" />
              สินค้า
            </span>
            <span className="text-xs text-gray-500">{filteredProducts.length} รายการ</span>
          </div>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="ค้นหาสินค้า..."
            className="w-full px-3 py-2 border rounded-lg text-sm"
          />
          <select
            value={catFilter}
            onChange={(e) => setCatFilter(e.target.value)}
            className="w-full px-3 py-2 border rounded-lg text-sm mt-2"
          >
            <option value="">ทุกหมวดหมู่</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </div>

        <div className="p-2 max-h-[65vh] overflow-y-auto" ref={productListElRef}>
          {filteredProducts.map((p) => (
            <div
              key={p.id}
              className="product-item flex items-center p-2 mb-1 bg-gray-50 rounded-lg hover:bg-green-50"
              data-id={p.id}
              data-name={p.name}
              data-price={p.price}
              data-image={p.image}
              data-cat={p.categoryId ?? ''}
            >
              <img src={p.image} className="w-10 h-10 object-cover rounded" alt="" />
              <div className="ml-2 flex-1 min-w-0">
                <div className="text-sm truncate">{p.name}</div>
                <div className="text-xs text-green-600 font-bold">฿{p.price.toLocaleString('en-US')}</div>
              </div>
              <i className="fas fa-grip-vertical text-gray-300" aria-hidden="true" />
            </div>
          ))}
        </div>
      </div>

      {/* Center: Bubble Builder */}
      <div className="xl:col-span-5 space-y-4">
        <div className="bg-white rounded-xl shadow p-3 flex items-center gap-2 flex-wrap">
          <input
            type="text"
            value={draftName}
            onChange={(e) => setDraftName(e.target.value)}
            placeholder="ชื่อ Draft (เช่น โปรเดือนพ.ค.)"
            className="flex-1 min-w-[160px] px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-300"
          />
          <button type="button" onClick={() => void saveDraft()} className="px-3 py-2 bg-purple-500 text-white rounded-lg text-sm hover:bg-purple-600">
            <i className="fas fa-save mr-1" aria-hidden="true" />
            บันทึก Draft
          </button>
          <button type="button" onClick={openDraftPicker} className="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">
            <i className="fas fa-folder-open mr-1" aria-hidden="true" />
            เปิด Draft
          </button>
          {draftStatus ? (
            <span className={`text-xs ${draftStatus.isError ? 'text-red-500' : 'text-green-600'}`}>{draftStatus.text}</span>
          ) : null}
        </div>

        <div className="bg-white rounded-xl shadow p-4">
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-semibold">
              <i className="fas fa-layer-group text-purple-500 mr-2" aria-hidden="true" />
              Bubble Builder
            </h2>
            <button type="button" onClick={addBubble} className="px-3 py-1 bg-green-500 text-white rounded-lg text-sm hover:bg-green-600">
              <i className="fas fa-plus mr-1" aria-hidden="true" />
              เพิ่ม Bubble
            </button>
          </div>
          <div className="space-y-4">
            {bubbles.map((bubble) => {
              const cfg = LAYOUT_CONFIG[layout];
              return (
                <div key={bubble.id} className="bubble-card bg-white rounded-xl shadow overflow-hidden">
                  <div
                    className="bubble-header text-white px-4 py-3 flex items-center justify-between"
                    style={{ background: `linear-gradient(135deg, ${theme}, ${adjustColor(theme, -20)})` }}
                  >
                    <div className="flex items-center gap-2">
                      <i className="fas fa-grip-vertical cursor-move" aria-hidden="true" />
                      <input
                        type="text"
                        value={bubble.title}
                        onChange={(e) => renameBubble(bubble.id, e.target.value)}
                        placeholder="หัวข้อ Bubble"
                        className="bg-transparent border-none text-white placeholder-white/70 text-sm font-medium w-32"
                      />
                    </div>
                    <button type="button" onClick={() => removeBubble(bubble.id)} className="text-white/80 hover:text-white text-sm">
                      <i className="fas fa-trash" aria-hidden="true" />
                    </button>
                  </div>
                  <div
                    ref={zoneRef(bubble.id)}
                    className="bubble-zone p-2 min-h-[120px] border-2 border-dashed border-gray-200 rounded-b-lg"
                  >
                    {bubble.products.length === 0 ? (
                      <div className="flex items-center justify-center h-[100px] text-gray-400 text-sm">ลากสินค้ามาวางที่นี่</div>
                    ) : (
                      bubble.products.map((product, index) => (
                        <div
                          key={`${product.id}-${index}`}
                          data-product-id={product.id}
                          className="bubble-product flex items-center p-2 bg-gray-50 rounded-md m-1"
                        >
                          <img src={product.image} className="w-10 h-10 object-cover rounded" alt="" />
                          <div className="ml-2 flex-1 min-w-0">
                            <div className="text-xs truncate">{product.name}</div>
                            <div className="text-xs text-green-600 font-bold">฿{Number(product.price).toLocaleString('en-US')}</div>
                          </div>
                          <button type="button" onClick={() => removeProduct(bubble.id, index)} className="text-red-400 hover:text-red-600 px-2">
                            <i className="fas fa-times" aria-hidden="true" />
                          </button>
                        </div>
                      ))
                    )}
                  </div>
                  <div className="p-2 border-t bg-gray-50 text-xs text-gray-500 flex justify-between">
                    <span>
                      {bubble.products.length}/{cfg.max} สินค้า
                    </span>
                    {/* Static "Layout: 3x3" — catalog.php's own <template> hardcodes this text and never
                        updates it after setLayout() (dead UI, confirmed by reading the full source: no
                        `.layout-info` selector exists anywhere in the script). Reproduced verbatim. */}
                    <span>Layout: 3x3</span>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Draft Picker Modal */}
      {draftPickerOpen ? (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-full max-w-lg mx-4 shadow-xl">
            <div className="p-4 border-b flex items-center justify-between">
              <h3 className="font-semibold">📂 Drafts ที่บันทึกไว้</h3>
              <button type="button" onClick={closeDraftPicker} className="text-gray-400 hover:text-gray-600">
                <i className="fas fa-times" aria-hidden="true" />
              </button>
            </div>
            <div className="p-4 max-h-96 overflow-y-auto divide-y">
              {draftList === null ? (
                <p className="text-gray-400 text-center py-4">กำลังโหลด…</p>
              ) : draftListError ? (
                <p className="text-red-500 text-center py-4">{draftListError}</p>
              ) : draftList.length === 0 ? (
                <p className="text-gray-400 text-center py-6">ยังไม่มี Draft ที่บันทึกไว้</p>
              ) : (
                draftList.map((d) => (
                  <div key={d.id} className="flex items-center justify-between py-2">
                    <div className="flex-1 min-w-0">
                      <div className="font-medium text-sm truncate">{d.name}</div>
                      <div className="text-xs text-gray-400">{d.updated_at || d.created_at || ''}</div>
                    </div>
                    <div className="flex gap-2 shrink-0">
                      <button
                        type="button"
                        onClick={() => void loadDraft(d.id)}
                        className="px-3 py-1 bg-purple-500 text-white text-xs rounded hover:bg-purple-600"
                      >
                        <i className="fas fa-folder-open mr-1" aria-hidden="true" />
                        โหลด
                      </button>
                      <button
                        type="button"
                        onClick={() => void deleteDraft(d.id)}
                        className="px-3 py-1 border border-red-300 text-red-500 text-xs rounded hover:bg-red-50"
                      >
                        <i className="fas fa-trash" aria-hidden="true" />
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      ) : null}

      {/* Right: Preview & Settings */}
      <div className="xl:col-span-4 space-y-4">
        <div className="bg-white rounded-xl shadow p-4">
          <h3 className="font-semibold mb-3">
            <i className="fas fa-cog text-gray-500 mr-2" aria-hidden="true" />
            ตั้งค่า
          </h3>
          <div className="space-y-3">
            <div>
              <label className="text-xs font-medium text-gray-600">Layout แต่ละ Bubble</label>
              <div className="grid grid-cols-4 gap-2 mt-1">
                {(Object.keys(LAYOUT_CONFIG) as CatalogLayoutKey[]).map((key) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setLayout(key)}
                    className={`px-2 py-2 border rounded text-xs hover:bg-gray-50 ${layout === key ? 'bg-green-500 text-white' : ''}`}
                  >
                    {key}
                  </button>
                ))}
              </div>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">สีธีม</label>
              <div className="flex gap-2 mt-1">
                {['#06C755', '#FF6B6B', '#4ECDC4'].map((color) => (
                  <button
                    key={color}
                    type="button"
                    onClick={() => setTheme(color)}
                    style={{ backgroundColor: color }}
                    className="w-8 h-8 rounded-full border-2 border-white shadow"
                  />
                ))}
                <input
                  type="color"
                  value={theme}
                  onChange={(e) => setTheme(e.target.value)}
                  className="w-8 h-8 rounded cursor-pointer"
                />
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white rounded-xl shadow">
          <div className="p-3 border-b flex items-center justify-between">
            <span className="font-semibold">
              <i className="fas fa-mobile-alt text-purple-500 mr-2" aria-hidden="true" />
              Preview
            </span>
            <span className="text-xs text-gray-500">{bubblesData.length} bubbles</span>
          </div>
          <div id={PREVIEW_CONTAINER_ID} className="p-3 bg-gray-100 max-h-[50vh] overflow-y-auto">
            {bubblesData.length === 0 ? (
              <div className="text-center text-gray-400 py-8">
                <i className="fas fa-hand-pointer text-4xl mb-2" aria-hidden="true" />
                <p>ลากสินค้าเข้า Bubble เพื่อดู Preview</p>
              </div>
            ) : null}
          </div>
        </div>

        <div className="bg-white rounded-xl shadow p-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-sm font-medium">พร้อมส่ง</div>
              <div className="text-xs text-gray-500">
                {totalProducts} สินค้า, {bubblesData.length} bubbles
              </div>
            </div>
            <button
              type="button"
              onClick={() => void sendBroadcast()}
              disabled={totalProducts === 0 || sending}
              className="px-6 py-3 bg-green-500 text-white rounded-lg font-medium hover:bg-green-600 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <i className={`fas ${sending ? 'fa-spinner fa-spin' : 'fa-paper-plane'} mr-2`} aria-hidden="true" />
              {sending ? 'กำลังส่ง...' : 'ส่ง Broadcast'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
