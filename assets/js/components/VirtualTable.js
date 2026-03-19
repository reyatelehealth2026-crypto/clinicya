/**
 * VirtualTable — Render only visible rows using node recycling
 *
 * @version 1.1.0
 * ข้อแก้ไขจากแผนเดิม (v1.0.0):
 *   - เปลี่ยนจาก innerHTML = '' ทุก scroll event (ทำ full DOM reflow)
 *     เป็น node recycling: นำ DOM nodes กลับมาใช้ใหม่แทนการสร้างใหม่
 *   - ใช้ position:absolute แทน translateY เพื่อหลีกเลี่ยง composite layer issue
 *   - เพิ่ม passive scroll listener เพื่อไม่ block main thread
 *
 * Usage:
 *   const table = new VirtualTable('container-id', {
 *     rowHeight: 48,
 *     renderRow: (node, item, index) => { node.textContent = item.name; }
 *   });
 *   table.setData(myArray);
 */
class VirtualTable {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        if (!this.container) throw new Error(`VirtualTable: element #${containerId} not found`);

        this.rowHeight  = options.rowHeight  || 48;
        this.bufferSize = options.bufferSize || 3;
        this.renderRow  = options.renderRow  || this._defaultRenderRow.bind(this);

        this.data = [];
        this._visibleStart = -1;
        this._visibleEnd   = -1;
        this._nodePool     = [];          // recycled DOM nodes
        this._activeNodes  = new Map();   // rowIndex → DOM node

        this._init();
    }

    _init() {
        this.container.style.cssText = 'overflow:auto;position:relative;';

        // Spacer controls total scroll height without rendering all rows
        this._spacer = document.createElement('div');
        this._spacer.style.cssText = 'position:absolute;top:0;left:0;width:1px;pointer-events:none;';

        // Rows container — rows are absolutely positioned inside here
        this._rows = document.createElement('div');
        this._rows.style.cssText = 'position:absolute;top:0;left:0;right:0;';

        this.container.appendChild(this._spacer);
        this.container.appendChild(this._rows);

        // passive:true ทำให้ browser ไม่ต้องรอ JS ก่อน scroll (60fps)
        this.container.addEventListener('scroll', () => this._update(), { passive: true });
        window.addEventListener('resize',  () => this._update(), { passive: true });
    }

    setData(data) {
        this.data = data;
        this._spacer.style.height = `${data.length * this.rowHeight}px`;

        // Reset state and recycle all active nodes
        this._visibleStart = -1;
        this._visibleEnd   = -1;
        this._activeNodes.forEach(node => this._nodePool.push(node));
        this._activeNodes.clear();
        this._rows.innerHTML = '';  // safe here: only called once per data load

        this._update();
    }

    _update() {
        const scrollTop = this.container.scrollTop;
        const viewH     = this.container.clientHeight;

        const start = Math.max(0,
            Math.floor(scrollTop / this.rowHeight) - this.bufferSize
        );
        const end = Math.min(this.data.length,
            Math.ceil((scrollTop + viewH) / this.rowHeight) + this.bufferSize
        );

        // No change in visible range — skip DOM work entirely
        if (start === this._visibleStart && end === this._visibleEnd) return;

        // Remove rows that scrolled out of view → put in recycle pool
        this._activeNodes.forEach((node, idx) => {
            if (idx < start || idx >= end) {
                this._rows.removeChild(node);
                this._nodePool.push(node);
                this._activeNodes.delete(idx);
            }
        });

        // Add newly visible rows — reuse recycled nodes before creating new ones
        for (let i = start; i < end; i++) {
            if (this._activeNodes.has(i)) continue;

            const node = this._nodePool.pop() || document.createElement('div');
            node.style.cssText = `position:absolute;top:${i * this.rowHeight}px;left:0;right:0;height:${this.rowHeight}px;box-sizing:border-box;`;
            this.renderRow(node, this.data[i], i);
            this._rows.appendChild(node);
            this._activeNodes.set(i, node);
        }

        this._visibleStart = start;
        this._visibleEnd   = end;
    }

    _defaultRenderRow(node, item) {
        node.textContent = JSON.stringify(item);
        node.style.borderBottom = '1px solid #eee';
        node.style.padding = '8px';
        node.style.overflow = 'hidden';
    }

    /** เลื่อนไปยัง row index ที่กำหนด */
    scrollToIndex(index) {
        this.container.scrollTop = index * this.rowHeight;
    }

    /** จำนวน rows ที่ render อยู่จริง (ไม่ใช่ทั้งหมด) */
    get activeCount() {
        return this._activeNodes.size;
    }
}

if (typeof module !== 'undefined') module.exports = VirtualTable;
