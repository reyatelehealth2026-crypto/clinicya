import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { CatalogBuilderClient } from './CatalogBuilderClient';
import { buildFlexFromData, getBubblesData, type BubbleState } from './CatalogBuilderClient';
import type { CatalogBuilderCategory, CatalogBuilderProduct } from '../_lib/catalog-queries';

// next/script would otherwise try to actually insert/track a <script> tag via
// Next's client script-loading runtime, which isn't present under plain
// jsdom + RTL. Stub it so onLoad fires once mounted (harmless: every real
// effect that depends on it defensively no-ops when `window.Sortable` /
// `window.FlexPreview` are undefined, which they always are in this suite —
// see CatalogBuilderClient.tsx's own `getSortableCtor()`/
// `getFlexPreviewGlobal()` guards). This batch's brief requires ZERO real
// network/LINE calls in tests — this only ever mounts a `null`-rendering
// stub, no CDN request is made.
jest.mock('next/script', () => ({
  __esModule: true,
  default: ({ onLoad }: { onLoad?: () => void }) => {
    React.useEffect(() => {
      onLoad?.();
    }, [onLoad]);
    return null;
  },
}));

const PRODUCTS: CatalogBuilderProduct[] = [
  { id: 1, name: 'พาราเซตามอล 500mg', price: 35, image: 'https://cdn.example.com/para.jpg', categoryId: 3 },
  { id: 2, name: 'วิตามินซี 1000mg', price: 120, image: 'https://cdn.example.com/vitc.jpg', categoryId: 4 },
];
const CATEGORIES: CatalogBuilderCategory[] = [
  { id: '3', name: 'ยาสามัญ' },
  { id: '4', name: 'วิตามิน' },
];

function setup(products = PRODUCTS, categories = CATEGORIES) {
  const user = userEvent.setup();
  render(<CatalogBuilderClient products={products} categories={categories} />);
  return { user };
}

function mockFetchJson(handler: (url: string, init?: RequestInit) => unknown) {
  return jest.fn(async (url: string, init?: RequestInit) => {
    const body = handler(url, init);
    return { json: async () => body } as Response;
  });
}

describe('CatalogBuilderClient — product panel', () => {
  it('renders every product with name + formatted price', () => {
    setup();
    expect(screen.getByText('พาราเซตามอล 500mg')).toBeInTheDocument();
    expect(screen.getByText('฿35')).toBeInTheDocument();
    expect(screen.getByText('วิตามินซี 1000mg')).toBeInTheDocument();
    expect(screen.getByText('฿120')).toBeInTheDocument();
  });

  it('filters by search text', async () => {
    const { user } = setup();
    await user.type(screen.getByPlaceholderText('ค้นหาสินค้า...'), 'วิตามิน');
    expect(screen.queryByText('พาราเซตามอล 500mg')).not.toBeInTheDocument();
    expect(screen.getByText('วิตามินซี 1000mg')).toBeInTheDocument();
    expect(screen.getByText('1 รายการ')).toBeInTheDocument();
  });

  it('filters by category', async () => {
    const { user } = setup();
    await user.selectOptions(screen.getByRole('combobox'), '4');
    expect(screen.queryByText('พาราเซตามอล 500mg')).not.toBeInTheDocument();
    expect(screen.getByText('วิตามินซี 1000mg')).toBeInTheDocument();
  });
});

describe('CatalogBuilderClient — bubble builder', () => {
  it('starts with exactly one empty bubble showing the drop placeholder and 0/9 (default 3x3 layout)', () => {
    setup();
    expect(screen.getByText('ลากสินค้ามาวางที่นี่')).toBeInTheDocument();
    expect(screen.getByText('0/9 สินค้า')).toBeInTheDocument();
  });

  it('adds a bubble on "เพิ่ม Bubble" click', async () => {
    const { user } = setup();
    await user.click(screen.getByRole('button', { name: /เพิ่ม Bubble/ }));
    expect(screen.getAllByText('ลากสินค้ามาวางที่นี่')).toHaveLength(2);
  });

  it('removes a bubble via its trash button, leaving the other intact', async () => {
    const { user } = setup();
    await user.click(screen.getByRole('button', { name: /เพิ่ม Bubble/ }));
    const trashButtons = document.querySelectorAll('.bubble-card .fa-trash');
    expect(trashButtons).toHaveLength(2);
    fireEvent.click(trashButtons[0]!.closest('button') as HTMLButtonElement);
    await waitFor(() => expect(screen.getAllByText('ลากสินค้ามาวางที่นี่')).toHaveLength(1));
  });

  it('changes the max-count denominator when a different layout is selected, but the static "Layout: 3x3" badge never changes (confirmed PHP quirk)', async () => {
    const { user } = setup();
    expect(screen.getByText('Layout: 3x3')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: '2x2' }));
    expect(screen.getByText('0/4 สินค้า')).toBeInTheDocument();
    // Still hardcoded "Layout: 3x3", even though the active layout is now 2x2.
    expect(screen.getByText('Layout: 3x3')).toBeInTheDocument();
  });

  it('syncs the custom color input when a theme swatch is clicked', async () => {
    const { user } = setup();
    const colorInput = document.querySelector('input[type="color"]') as HTMLInputElement;
    expect(colorInput.value).toBe('#06c755');

    const swatches = document.querySelectorAll('button[style*="background-color"]');
    expect(swatches).toHaveLength(3);
    await user.click(swatches[1] as HTMLButtonElement); // #FF6B6B

    expect(colorInput.value).toBe('#ff6b6b');
  });

  it('disables the send button while there are no products in any bubble', () => {
    setup();
    expect(screen.getByRole('button', { name: /ส่ง Broadcast/ })).toBeDisabled();
  });
});

describe('CatalogBuilderClient — draft save', () => {
  it('alerts and does not call fetch when saving without a draft name', async () => {
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});
    global.fetch = jest.fn();
    const { user } = setup();

    await user.click(screen.getByRole('button', { name: /บันทึก Draft/ }));

    expect(alertSpy).toHaveBeenCalledWith('กรุณาตั้งชื่อ Draft ก่อน');
    expect(global.fetch).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });

  it('asks for confirmation before saving an empty-bubbles draft, and skips the save when declined', async () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    global.fetch = jest.fn();
    const { user } = setup();

    await user.type(screen.getByPlaceholderText('ชื่อ Draft (เช่น โปรเดือนพ.ค.)'), 'โปรว่าง');
    await user.click(screen.getByRole('button', { name: /บันทึก Draft/ }));

    expect(confirmSpy).toHaveBeenCalledWith('ยังไม่มีสินค้าใน Bubble — บันทึกเป็น Draft ว่างหรือไม่?');
    expect(global.fetch).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('POSTs to the absolute /api/broadcast_drafts.php path and shows a success status on save', async () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    const fetchMock = mockFetchJson(() => ({ success: true, id: 42 }));
    global.fetch = fetchMock as unknown as typeof fetch;
    const { user } = setup();

    await user.type(screen.getByPlaceholderText('ชื่อ Draft (เช่น โปรเดือนพ.ค.)'), 'โปรเดือนนี้');
    await user.click(screen.getByRole('button', { name: /บันทึก Draft/ }));

    await waitFor(() => expect(screen.getByText('บันทึกแล้ว ✓')).toBeInTheDocument());
    expect(fetchMock).toHaveBeenCalledWith('/api/broadcast_drafts.php?action=save', expect.objectContaining({ method: 'POST' }));
    const [, init] = fetchMock.mock.calls[0]!;
    const sentBody = JSON.parse((init as RequestInit).body as string);
    expect(sentBody).toMatchObject({ action: 'save', id: 0, name: 'โปรเดือนนี้', source: 'catalog' });
  });

  it('shows a failure status when the save response has success:false', async () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    global.fetch = mockFetchJson(() => ({ success: false, error: 'DB down' })) as unknown as typeof fetch;
    const { user } = setup();

    await user.type(screen.getByPlaceholderText('ชื่อ Draft (เช่น โปรเดือนพ.ค.)'), 'โปรเดือนนี้');
    await user.click(screen.getByRole('button', { name: /บันทึก Draft/ }));

    await waitFor(() => expect(screen.getByText('บันทึกไม่สำเร็จ: DB down')).toBeInTheDocument());
  });
});

describe('CatalogBuilderClient — draft picker + load + delete', () => {
  it('fetches the draft list on open and renders each entry', async () => {
    const fetchMock = mockFetchJson((url) => {
      if (url.includes('action=list')) {
        return { success: true, drafts: [{ id: 1, name: 'Draft A', updated_at: '2026-08-01 10:00:00' }] };
      }
      return { success: false };
    });
    global.fetch = fetchMock as unknown as typeof fetch;
    const { user } = setup();

    await user.click(screen.getByRole('button', { name: /เปิด Draft/ }));

    await waitFor(() => expect(screen.getByText('Draft A')).toBeInTheDocument());
    expect(fetchMock).toHaveBeenCalledWith('/api/broadcast_drafts.php?action=list');
  });

  it('loads a draft into the bubble builder and closes the modal', async () => {
    const fetchMock = mockFetchJson((url) => {
      if (url.includes('action=list')) {
        return { success: true, drafts: [{ id: 1, name: 'Draft A' }] };
      }
      if (url.includes('action=load')) {
        return {
          success: true,
          draft: {
            id: 1,
            name: 'Draft A',
            payload: {
              layout: '2x2',
              theme: '#FF6B6B',
              bubbles: [{ title: 'สินค้าฮิต', products: [{ id: 1, name: 'พาราเซตามอล 500mg', price: 35, image: 'https://cdn.example.com/para.jpg' }] }],
            },
          },
        };
      }
      return { success: false };
    });
    global.fetch = fetchMock as unknown as typeof fetch;
    const { user } = setup();

    await user.click(screen.getByRole('button', { name: /เปิด Draft/ }));
    await waitFor(() => expect(screen.getByText('Draft A')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /โหลด/ }));

    await waitFor(() => expect(screen.getByText('โหลด Draft แล้ว ✓')).toBeInTheDocument());
    expect(screen.queryByText('📂 Drafts ที่บันทึกไว้')).not.toBeInTheDocument();
    expect(screen.getByDisplayValue('สินค้าฮิต')).toBeInTheDocument();
    expect(screen.getByText('1/4 สินค้า')).toBeInTheDocument(); // 1 product loaded, layout applied -> 2x2 -> max 4
    expect(screen.getByText('1 bubbles')).toBeInTheDocument(); // preview info reflects the loaded product
  });

  it('removes a product from a loaded bubble via its remove button', async () => {
    const fetchMock = mockFetchJson((url) => {
      if (url.includes('action=list')) return { success: true, drafts: [{ id: 1, name: 'Draft A' }] };
      if (url.includes('action=load')) {
        return {
          success: true,
          draft: {
            id: 1,
            name: 'Draft A',
            payload: { bubbles: [{ title: 'สินค้าฮิต', products: [{ id: 1, name: 'พาราเซตามอล 500mg', price: 35, image: 'https://x/p.jpg' }] }] },
          },
        };
      }
      return { success: false };
    });
    global.fetch = fetchMock as unknown as typeof fetch;
    const { user } = setup();

    await user.click(screen.getByRole('button', { name: /เปิด Draft/ }));
    await waitFor(() => expect(screen.getByText('Draft A')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /โหลด/ }));
    await waitFor(() => expect(screen.getByText('1 bubbles')).toBeInTheDocument());

    const removeBtn = document.querySelector('.bubble-product .fa-times')!.closest('button') as HTMLButtonElement;
    fireEvent.click(removeBtn);

    await waitFor(() => expect(screen.getByText('0 bubbles')).toBeInTheDocument());
  });

  it('deletes a draft after confirmation and refreshes the list', async () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    let listCallCount = 0;
    const fetchMock = mockFetchJson((url) => {
      if (url.includes('action=list')) {
        listCallCount += 1;
        return { success: true, drafts: listCallCount === 1 ? [{ id: 1, name: 'Draft A' }] : [] };
      }
      if (url.includes('action=delete')) return { success: true };
      return { success: false };
    });
    global.fetch = fetchMock as unknown as typeof fetch;
    const { user } = setup();

    await user.click(screen.getByRole('button', { name: /เปิด Draft/ }));
    await waitFor(() => expect(screen.getByText('Draft A')).toBeInTheDocument());

    // "Draft A" -> .font-medium div -> .flex-1 div -> the row div holding both the text and the button group.
    const row = screen.getByText('Draft A').parentElement!.parentElement!;
    const deleteBtn = within(row).getAllByRole('button')[1] as HTMLButtonElement;
    fireEvent.click(deleteBtn);

    await waitFor(() => expect(screen.getByText('ยังไม่มี Draft ที่บันทึกไว้')).toBeInTheDocument());
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/broadcast_drafts.php?action=delete',
      expect.objectContaining({ method: 'POST' })
    );
  });

  it('closes the picker on Escape', async () => {
    global.fetch = mockFetchJson(() => ({ success: true, drafts: [] })) as unknown as typeof fetch;
    const { user } = setup();
    await user.click(screen.getByRole('button', { name: /เปิด Draft/ }));
    expect(screen.getByText('📂 Drafts ที่บันทึกไว้')).toBeInTheDocument();

    await user.keyboard('{Escape}');
    expect(screen.queryByText('📂 Drafts ที่บันทึกไว้')).not.toBeInTheDocument();
  });
});

describe('CatalogBuilderClient — send broadcast', () => {
  async function loadOneProductDraft(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole('button', { name: /เปิด Draft/ }));
    await waitFor(() => expect(screen.getByText('Draft A')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /โหลด/ }));
    await waitFor(() => expect(screen.getByText('1 bubbles')).toBeInTheDocument());
  }

  it('POSTs action=send_flex to the absolute /api/broadcast.php path and alerts on success', async () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});
    const fetchMock = mockFetchJson((url) => {
      if (url.includes('action=list')) return { success: true, drafts: [{ id: 1, name: 'Draft A' }] };
      if (url.includes('action=load')) {
        return {
          success: true,
          draft: { id: 1, name: 'Draft A', payload: { bubbles: [{ title: 'สินค้าฮิต', products: [{ id: 1, name: 'พารา', price: 35, image: 'https://x/p.jpg' }] }] } },
        };
      }
      if (url === '/api/broadcast.php') return { success: true, sent: 500 };
      return { success: false };
    });
    global.fetch = fetchMock as unknown as typeof fetch;
    const { user } = setup();

    await loadOneProductDraft(user);
    await user.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));

    await waitFor(() => expect(alertSpy).toHaveBeenCalledWith('✅ ส่งสำเร็จ! (500 คน)'));
    const sendCall = fetchMock.mock.calls.find(([url]) => url === '/api/broadcast.php')!;
    expect(sendCall[1]).toMatchObject({ method: 'POST' });
    const sentBody = JSON.parse((sendCall[1] as RequestInit).body as string);
    expect(sentBody.action).toBe('send_flex');
    expect(sentBody.altText).toBe('สินค้าฮิต');
    alertSpy.mockRestore();
  });

  it('alerts an error when the send response has success:false', async () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});
    const fetchMock = mockFetchJson((url) => {
      if (url.includes('action=list')) return { success: true, drafts: [{ id: 1, name: 'Draft A' }] };
      if (url.includes('action=load')) {
        return {
          success: true,
          draft: { id: 1, name: 'Draft A', payload: { bubbles: [{ title: 'สินค้าฮิต', products: [{ id: 1, name: 'พารา', price: 35, image: 'https://x/p.jpg' }] }] } },
        };
      }
      if (url === '/api/broadcast.php') return { success: false, error: 'LINE API error' };
      return { success: false };
    });
    global.fetch = fetchMock as unknown as typeof fetch;
    const { user } = setup();

    await loadOneProductDraft(user);
    await user.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));

    await waitFor(() => expect(alertSpy).toHaveBeenCalledWith('❌ Error: LINE API error'));
    alertSpy.mockRestore();
  });
});

describe('buildFlexFromData / getBubblesData (pure helpers)', () => {
  it('getBubblesData excludes empty bubbles and defaults an empty title', () => {
    const bubbles: BubbleState[] = [
      { id: 1, title: '', products: [{ id: 1, name: 'A', price: 10, image: 'x' }] },
      { id: 2, title: 'ว่าง', products: [] },
    ];
    const data = getBubblesData(bubbles, '3x3', '#06C755');
    expect(data).toHaveLength(1);
    expect(data[0]).toMatchObject({ title: 'สินค้าแนะนำ' });
  });

  it('buildFlexFromData returns a single bubble (not a carousel) for exactly one bubble', () => {
    const flex = buildFlexFromData(
      [{ title: 'A', products: [{ id: 1, name: 'สินค้า', price: 10, image: 'https://x/1.jpg' }], layout: '3x3', theme: '#06C755' }],
      '3x3'
    ) as { type: string };
    expect(flex.type).toBe('bubble');
  });

  it('buildFlexFromData returns a carousel for 2+ bubbles', () => {
    const bubble = { title: 'A', products: [{ id: 1, name: 'สินค้า', price: 10, image: 'https://x/1.jpg' }], layout: '3x3' as const, theme: '#06C755' };
    const flex = buildFlexFromData([bubble, { ...bubble, title: 'B' }], '3x3') as { type: string; contents: unknown[] };
    expect(flex.type).toBe('carousel');
    expect(flex.contents).toHaveLength(2);
  });

  it('swaps a via.placeholder.com image for the LINE CDN placeholder', () => {
    const flex = buildFlexFromData(
      [{ title: 'A', products: [{ id: 1, name: 'สินค้า', price: 10, image: 'https://via.placeholder.com/100' }], layout: '3x3', theme: '#06C755' }],
      '3x3'
    ) as { body: { contents: Array<{ contents: Array<{ contents: Array<{ url?: string }> }> }> } };
    const imageNode = flex.body.contents[0]!.contents[0]!.contents[0]!;
    expect(imageNode.url).toBe('https://scdn.line-apps.com/n/channel_devcenter/img/fx/01_1_cafe.png');
  });

  it('truncates a product name longer than 10 (UTF-16 code unit) chars with ".."', () => {
    const flex = buildFlexFromData(
      [{ title: 'A', products: [{ id: 1, name: 'ProductNameIsLong', price: 10, image: 'https://x/1.jpg' }], layout: '3x3', theme: '#06C755' }],
      '3x3'
    ) as { body: { contents: Array<{ contents: Array<{ contents: Array<{ text?: string }> }> }> } };
    const textNode = flex.body.contents[0]!.contents[0]!.contents[1]!;
    expect(textNode.text).toBe('ProductNam..');
  });

  it('does not truncate a Thai product name whose slice(0,10) code-unit count still fits (combining marks count as separate units)', () => {
    // Thai tone/vowel marks (e.g. ้ in ค้า) are separate UTF-16 code units from
    // the base consonant, exactly like PHP's own `mb_substr`-free `.slice(0,10)`
    // JS port operates on code units, not visual glyphs — reproduced as-is.
    const flex = buildFlexFromData(
      [{ title: 'A', products: [{ id: 1, name: 'สินค้าทดสอบยาวมากกกกก', price: 10, image: 'https://x/1.jpg' }], layout: '3x3', theme: '#06C755' }],
      '3x3'
    ) as { body: { contents: Array<{ contents: Array<{ contents: Array<{ text?: string }> }> }> } };
    const textNode = flex.body.contents[0]!.contents[0]!.contents[1]!;
    expect(textNode.text).toBe('สินค้าทดสอ..');
  });
});
