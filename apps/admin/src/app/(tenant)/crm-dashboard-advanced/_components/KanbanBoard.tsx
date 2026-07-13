'use client';

import { useState, useTransition } from 'react';
import { Modal } from '@/components/Modal';
import { moveDealAction } from '../actions';
import { stageBadgeClass, formatMoney, formatDealDate, STAGE_DETAIL_LABEL, type DealStage } from '../_lib/format';
import type { PipelineStage, DealRow } from '../queries';

/**
 * KanbanBoard.tsx — client island port of sales-pipeline.php's kanban board
 * (lines 40-45, `renderKanbanBoard()`/`renderDealCard()`) + its Deal Detail
 * modal (`#dealDetailModal`, `openDealDetail()`/`closeDealDetail()`) +
 * drag-and-drop handlers (`handleDragStart/DragOver/DragLeave/Drop`) +
 * `moveDeal()`/`closeDeal()`. Native HTML5 drag-and-drop, same event names
 * as the PHP source's vanilla JS.
 *
 * `editDeal()` in the PHP source is a bare `alert('Edit functionality - deal
 * ID: ' + dealId)` stub (no real edit form exists anywhere in the page) —
 * mirrored literally as a client-side `alert()`, not a real edit flow.
 */
const STAGE_BORDER: Record<string, string> = {
  lead: 'border-t-gray-400',
  qualified: 'border-t-blue-500',
  proposal: 'border-t-purple-500',
  negotiation: 'border-t-yellow-500',
  closed_won: 'border-t-green-500',
  closed_lost: 'border-t-red-500',
};

export function KanbanBoard({ stages }: { stages: PipelineStage[] }) {
  const [draggedDealId, setDraggedDealId] = useState<number | null>(null);
  const [dragOverStage, setDragOverStage] = useState<string | null>(null);
  const [detailDeal, setDetailDeal] = useState<DealRow | null>(null);
  const [isPending, startTransition] = useTransition();

  function handleDrop(newStage: string) {
    setDragOverStage(null);
    if (draggedDealId == null) return;
    const currentStage = stages.find((s) => s.deals.some((d) => d.id === draggedDealId))?.id;
    if (currentStage !== newStage) {
      startTransition(async () => {
        const result = await moveDealAction(draggedDealId, newStage);
        if (!result.success) {
          alert('Failed to move deal: ' + (result.error ?? 'Unknown error'));
        }
      });
    }
    setDraggedDealId(null);
  }

  function closeDeal(dealId: number, outcome: 'won' | 'lost') {
    const stage = outcome === 'won' ? 'closed_won' : 'closed_lost';
    if (confirm(`Are you sure you want to mark this deal as ${outcome}?`)) {
      startTransition(async () => {
        const result = await moveDealAction(dealId, stage);
        if (result.success) {
          setDetailDeal(null);
        }
      });
    }
  }

  return (
    <>
      <div className="overflow-x-auto pb-4">
        <div className="flex gap-4 min-w-max">
          {stages.map((stage) => (
            <div key={stage.id} className={`bg-slate-50 rounded-md min-w-[240px] max-w-[280px] border-t-4 ${STAGE_BORDER[stage.id] ?? ''}`}>
              <div className="px-3 py-2.5 font-semibold text-xs flex items-center justify-between border-b border-gray-200">
                <span>{stage.name}</span>
                <span className="bg-gray-200 px-2 py-0.5 rounded-full text-[11px] font-mono">{stage.count}</span>
              </div>
              <div
                className={`p-2 min-h-[200px] rounded-b-md transition-colors ${dragOverStage === stage.id ? 'bg-blue-50 ring-2 ring-blue-400 ring-inset' : ''}`}
                onDragOver={(e) => {
                  e.preventDefault();
                  setDragOverStage(stage.id);
                }}
                onDragLeave={() => setDragOverStage(null)}
                onDrop={(e) => {
                  e.preventDefault();
                  handleDrop(stage.id);
                }}
              >
                {stage.deals.map((deal) => (
                  <div
                    key={deal.id}
                    draggable
                    onDragStart={() => setDraggedDealId(deal.id)}
                    onDragEnd={() => setDraggedDealId(null)}
                    onClick={() => setDetailDeal(deal)}
                    className="bg-white border border-gray-200 rounded-md p-2.5 mb-2 cursor-grab hover:border-blue-400 hover:shadow-sm"
                  >
                    <div className="flex items-start justify-between mb-1.5">
                      <span className="font-mono text-sm font-semibold">฿{formatMoney(deal.value)}</span>
                      <span className="text-[11px] px-1.5 py-0.5 bg-gray-100 rounded">{deal.probability || 0}%</span>
                    </div>
                    <h4 className="font-medium text-sm mb-1 truncate">{deal.title}</h4>
                    <div className="text-xs text-gray-500 truncate mb-1.5">{deal.customer_name || 'Unknown'}</div>
                    <div className="flex items-center justify-between text-[11px] text-gray-400">
                      <span>{deal.expected_close ? formatDealDate(deal.expected_close) : 'No date'}</span>
                      <span>{deal.source || 'manual'}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>

      <Modal open={detailDeal !== null} onClose={() => setDetailDeal(null)} title="Deal Details" size="md">
        {detailDeal ? (
          <div>
            <div className="grid grid-cols-2 gap-4 mb-4">
              <div className="bg-gray-50 p-3 rounded-md">
                <div className="text-xs text-gray-500 uppercase">Deal Value</div>
                <div className="text-xl font-mono font-semibold">฿{formatMoney(detailDeal.value)}</div>
              </div>
              <div className="bg-gray-50 p-3 rounded-md">
                <div className="text-xs text-gray-500 uppercase">Probability</div>
                <div className="text-xl font-semibold">{detailDeal.probability || 0}%</div>
              </div>
            </div>

            <div className="mb-4">
              <div className="text-xs text-gray-500 uppercase">Stage</div>
              <span className={`inline-block mt-1 text-xs px-2 py-0.5 rounded ${stageBadgeClass(detailDeal.stage)}`}>
                {STAGE_DETAIL_LABEL[detailDeal.stage as DealStage] ?? detailDeal.stage}
              </span>
            </div>

            <div className="mb-4">
              <div className="text-xs text-gray-500 uppercase">Customer</div>
              <div className="font-medium mt-1">{detailDeal.customer_name || 'Unknown'}</div>
            </div>

            <div className="mb-4">
              <div className="text-xs text-gray-500 uppercase">Expected Close</div>
              <div className="font-medium">{detailDeal.expected_close ? formatDealDate(detailDeal.expected_close) : 'Not set'}</div>
            </div>

            {detailDeal.description ? (
              <div className="mb-4">
                <div className="text-xs text-gray-500 uppercase">Description</div>
                <p className="mt-1 text-gray-700">{detailDeal.description}</p>
              </div>
            ) : null}

            <div className="flex gap-2 mt-6">
              <button
                type="button"
                onClick={() => alert('Edit functionality - deal ID: ' + detailDeal.id)}
                className="flex-1 px-4 py-2 rounded-md bg-slate-900 text-white text-sm font-medium"
              >
                ✎ Edit Deal
              </button>
              {detailDeal.stage !== 'closed_won' && detailDeal.stage !== 'closed_lost' ? (
                <>
                  <button
                    type="button"
                    disabled={isPending}
                    onClick={() => closeDeal(detailDeal.id, 'won')}
                    className="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 text-sm font-medium disabled:opacity-60"
                  >
                    ✓ Mark Won
                  </button>
                  <button
                    type="button"
                    disabled={isPending}
                    onClick={() => closeDeal(detailDeal.id, 'lost')}
                    className="flex-1 px-4 py-2 rounded-md bg-gray-100 text-red-600 text-sm font-medium disabled:opacity-60"
                  >
                    ✕ Mark Lost
                  </button>
                </>
              ) : null}
            </div>
          </div>
        ) : null}
      </Modal>
    </>
  );
}
