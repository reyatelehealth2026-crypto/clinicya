import { render, screen } from '@testing-library/react';
import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { StatsTab } from './StatsTab';

describe('StatsTab', () => {
  describe('branch 1: campaignId=0 -> campaign picker', () => {
    it('renders the 4 overall-stat tiles and the empty state when there are no campaigns', async () => {
      const { db } = makeFakeTenantDb(() => []);
      const element = await StatsTab({ db, lineAccountId: 1, campaignId: 0 });
      render(element);

      expect(screen.getByText('เลือก Campaign เพื่อดูสถิติ')).toBeInTheDocument();
      expect(screen.getByText('Broadcasts ทั้งหมด')).toBeInTheDocument();
      expect(screen.getByText('ส่งแล้ว')).toBeInTheDocument();
      expect(screen.getByText('ผู้รับสะสม')).toBeInTheDocument();
      expect(screen.getByText('Total Clicks')).toBeInTheDocument();
      expect(screen.getByText('ยังไม่มี Campaign')).toBeInTheDocument();
      expect(screen.getByRole('link', { name: 'สร้าง Broadcast ใหม่' })).toHaveAttribute('href', '?tab=products');
    });

    it('renders overall stat numbers and a populated picker grid with correctly-routed hrefs', async () => {
      const { db } = makeFakeTenantDb((sqlText) => {
        if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcast_campaigns') && sqlText.includes("status = 'sent'")) return [{ c: 3 }];
        if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcast_campaigns')) return [{ c: 5 }];
        if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcasts') && sqlText.includes("status = 'sent'")) return [{ c: 0 }];
        if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcasts')) return [{ c: 0 }];
        if (sqlText.includes('total_sent_users')) return [{ total_sent_users: 999 }];
        if (sqlText.includes('FROM broadcast_clicks WHERE line_account_id')) return [{ c: 77 }];
        if (sqlText.includes('UNION ALL')) {
          return [
            {
              id: 9,
              name: 'แคมเปญคาตาล็อก',
              status: 'sent',
              created_at: new Date('2026-08-01T03:00:00Z'),
              sent_count: 200,
              kind: 'campaign',
            },
            {
              id: 11,
              name: 'ส่งด่วนวันนี้',
              status: 'draft',
              created_at: new Date('2026-08-02T03:00:00Z'),
              sent_count: 0,
              kind: 'quick',
            },
          ];
        }
        return [];
      });

      const element = await StatsTab({ db, lineAccountId: 7, campaignId: 0 });
      render(element);

      expect(screen.getByText('5')).toBeInTheDocument();
      expect(screen.getByText('999')).toBeInTheDocument();
      expect(screen.getByText('77')).toBeInTheDocument();

      const campaignCard = screen.getByRole('link', { name: /แคมเปญคาตาล็อก/ });
      expect(campaignCard).toHaveAttribute('href', '?tab=stats&id=9');
      expect(campaignCard).toHaveTextContent('Catalog/Carousel');
      expect(campaignCard).toHaveTextContent('ส่งแล้ว');

      const quickCard = screen.getByRole('link', { name: /ส่งด่วนวันนี้/ });
      expect(quickCard).toHaveAttribute('href', '?tab=send');
      expect(quickCard).toHaveTextContent('Quick Send');
      expect(quickCard).toHaveTextContent('รอส่ง');
    });
  });

  describe('branch 2: campaignId set, campaign not found', () => {
    it('renders the "ไม่พบ Broadcast" empty state with a back link', async () => {
      const { db } = makeFakeTenantDb(() => []);
      const element = await StatsTab({ db, lineAccountId: 1, campaignId: 999 });
      render(element);

      expect(screen.getByText('ไม่พบ Broadcast')).toBeInTheDocument();
      expect(screen.getByRole('link', { name: 'กลับไปเลือก Campaign' })).toHaveAttribute('href', '?tab=stats');
    });
  });

  describe('branch 3: campaign found -> stat tiles + items + recent clicks', () => {
    function wireFoundCampaignDb() {
      return makeFakeTenantDb((sqlText) => {
        if (sqlText.includes('FROM broadcast_campaigns')) {
          return [{ id: 5, name: 'โปรเดือนสิงหาคม', status: 'sent', sent_count: 300 }];
        }
        if (sqlText.includes('FROM broadcast_items')) {
          return [
            { id: 1, item_name: 'ยาลดไข้', item_image: null, click_count: 20 },
            { id: 2, item_name: 'วิตามินซี', item_image: 'https://x/img.jpg', click_count: 5 },
          ];
        }
        if (sqlText.includes('FROM broadcast_clicks')) {
          return [
            {
              id: 1,
              clicked_at: new Date('2026-08-14T03:30:00Z'),
              tag_assigned: 1,
              display_name: 'สมชาย ใจดี',
              picture_url: null,
              item_name: 'ยาลดไข้',
            },
          ];
        }
        return [];
      });
    }

    it('renders campaign name, item click bars, and the recent-clicks feed', async () => {
      const { db } = wireFoundCampaignDb();
      const element = await StatsTab({ db, lineAccountId: 1, campaignId: 5 });
      render(element);

      expect(screen.getByText('โปรเดือนสิงหาคม')).toBeInTheDocument();
      expect(screen.getByText('ยาลดไข้')).toBeInTheDocument();
      expect(screen.getByText('วิตามินซี')).toBeInTheDocument();
      expect(screen.getByText('20')).toBeInTheDocument();
      expect(screen.getByText('สมชาย ใจดี')).toBeInTheDocument();
      expect(screen.getByText('สนใจ: ยาลดไข้')).toBeInTheDocument();
      expect(screen.getByText('Tagged')).toBeInTheDocument();
    });

    it('shows totalSent=0 (confirmed dead `total_sent` column) and CTR=0.0% even though clicks exist', async () => {
      // sent_count=300 on the row is irrelevant — StatsCampaign.totalSent is
      // hardcoded 0 (see ../_lib/stats-queries.ts's confirmed-finding doc),
      // matching PHP's `$campaign['total_sent'] ?? 0` referencing a column
      // that doesn't exist on `broadcast_campaigns`.
      const { db } = wireFoundCampaignDb();
      const element = await StatsTab({ db, lineAccountId: 1, campaignId: 5 });
      render(element);

      const sentTile = screen.getByText('ส่งแล้ว').previousElementSibling;
      expect(sentTile).toHaveTextContent('0');
      expect(screen.getByText('0.0%')).toBeInTheDocument();
    });

    it('shows the item count tile (2 items) and total-clicks tile (20 + 5 = 25)', async () => {
      const { db } = wireFoundCampaignDb();
      const element = await StatsTab({ db, lineAccountId: 1, campaignId: 5 });
      render(element);

      expect(screen.getByText('สินค้า')).toBeInTheDocument();
      expect(screen.getByText('2')).toBeInTheDocument();
      expect(screen.getByText('25')).toBeInTheDocument();
    });

    it('shows the "ไม่มีข้อมูล" / "ยังไม่มีการคลิก" empty states when items/clicks are both empty', async () => {
      const { db } = makeFakeTenantDb((sqlText) => {
        if (sqlText.includes('FROM broadcast_campaigns')) {
          return [{ id: 5, name: 'แคมเปญว่าง', status: 'draft', sent_count: 0 }];
        }
        return [];
      });
      const element = await StatsTab({ db, lineAccountId: 1, campaignId: 5 });
      render(element);

      expect(screen.getByText('ไม่มีข้อมูล')).toBeInTheDocument();
      expect(screen.getByText('ยังไม่มีการคลิก')).toBeInTheDocument();
    });
  });
});
