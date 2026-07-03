/**
 * Website Wizard — โหมดไกด์ตั้งค่าเว็บร้านทีละขั้นบน website.php
 *
 * หลักการ: ไม่มีฟอร์ม/handler ของตัวเอง — พาผู้ใช้ไล่ card เดิมของ hub ทีละใบ
 * (หรี่ใบอื่น + แถบ stepper ล่างจอ) การบันทึกแต่ละขั้นคือ submit ฟอร์มเดิมปกติ
 * ซึ่งรีโหลดหน้า จึงเก็บสถานะไว้ใน sessionStorage แล้ว resume ขั้นเดิมหลังรีโหลด
 *
 * เปิดใช้: ปุ่มเรียก WebsiteWizard.start() หรือเปิดหน้า /website?wizard=1
 */
(function () {
    'use strict';

    var STORE_KEY = 'reyaWebsiteWizard'; // '{"step":N}' = กำลังไกด์อยู่

    // หา element เป้าหมายของแต่ละขั้นจาก DOM เดิม (ไม่ต้องแก้ markup ของ hub)
    function cardOf(actionValue) {
        var input = document.querySelector('input[name="action"][value="' + actionValue + '"]');
        if (!input) { return null; }
        var card = input.closest('.bg-white');
        return card || input.closest('form');
    }

    var STEPS = [
        {
            title: 'ขั้นที่ 1: ข้อมูลร้าน',
            hint: 'กรอกชื่อร้าน โลโก้ ที่อยู่ และเบอร์โทร แล้วกด "บันทึกข้อมูลร้าน"',
            find: function () { return document.getElementById('shop-info'); }
        },
        {
            title: 'ขั้นที่ 2: เวลาทำการ',
            hint: 'ตั้งเวลาเปิด-ปิดแต่ละวัน หน้าเว็บจะโชว์ป้าย "เปิดอยู่ตอนนี้" ให้เอง',
            find: function () { return document.getElementById('hours'); }
        },
        {
            title: 'ขั้นที่ 3: ธีมและหน้าตา',
            hint: 'เลือกธีมสี รูปแบบ hero และข้อความหลัก แล้วกด "บันทึกร่าง"',
            find: function () { return cardOf('save_v2_draft'); }
        },
        {
            title: 'ขั้นที่ 4: รูปหน้าร้านจริง',
            hint: 'อัปโหลดรูปถ่ายจริงอย่างน้อย 1 รูป ช่วยให้ลูกค้าเชื่อใจมากขึ้น',
            find: function () { return cardOf('upload_v2_photo'); }
        },
        {
            title: 'ขั้นที่ 5: ตรวจและเผยแพร่',
            hint: 'กด "ดูตัวอย่างร่าง" เช็คก่อน ถ้าพอใจแล้วกด "เผยแพร่" ได้เลย',
            find: function () { return cardOf('publish_v2'); }
        }
    ];

    var state = null; // {step: N} | null
    var bar = null;

    function readState() {
        try {
            var raw = sessionStorage.getItem(STORE_KEY);
            if (!raw) { return null; }
            var parsed = JSON.parse(raw);
            if (typeof parsed.step !== 'number' || parsed.step < 0 || parsed.step >= STEPS.length) { return null; }
            return parsed;
        } catch (e) { return null; }
    }

    function writeState() {
        try {
            if (state) { sessionStorage.setItem(STORE_KEY, JSON.stringify(state)); }
            else { sessionStorage.removeItem(STORE_KEY); }
        } catch (e) { /* storage เต็ม/ปิดไว้ = ไกด์ยังทำงานได้แค่ไม่ resume */ }
    }

    function allCards() {
        return STEPS.map(function (s) { return s.find(); }).filter(Boolean);
    }

    function render() {
        var cards = allCards();
        var current = STEPS[state.step].find();

        cards.forEach(function (card) {
            card.classList.toggle('wz-dim', card !== current);
            card.classList.toggle('wz-focus', card === current);
        });

        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'wz-bar';
            bar.setAttribute('role', 'region');
            bar.setAttribute('aria-label', 'ไกด์ตั้งค่าเว็บร้าน');
            document.body.appendChild(bar);
        }

        var dots = STEPS.map(function (s, i) {
            return '<i class="' + (i <= state.step ? 'on' : '') + '"></i>';
        }).join('');

        bar.innerHTML =
            '<div class="wz-bar-info">' +
                '<div class="wz-bar-step">ไกด์ตั้งค่า ' + (state.step + 1) + '/' + STEPS.length + '</div>' +
                '<div class="wz-bar-title"></div>' +
                '<div class="wz-bar-hint"></div>' +
                '<div class="wz-dots">' + dots + '</div>' +
            '</div>' +
            '<div class="wz-bar-actions">' +
                '<button type="button" class="wz-btn wz-btn-prev">ย้อนกลับ</button>' +
                '<button type="button" class="wz-btn wz-btn-next"></button>' +
                '<button type="button" class="wz-btn wz-btn-exit">ออกจากไกด์</button>' +
            '</div>';

        // ใส่ข้อความผ่าน textContent กัน markup แปลกปลอม
        bar.querySelector('.wz-bar-title').textContent = STEPS[state.step].title;
        bar.querySelector('.wz-bar-hint').textContent = STEPS[state.step].hint;

        var prevBtn = bar.querySelector('.wz-btn-prev');
        var nextBtn = bar.querySelector('.wz-btn-next');
        prevBtn.disabled = state.step === 0;
        nextBtn.textContent = state.step === STEPS.length - 1 ? 'เสร็จสิ้น' : 'ถัดไป';

        prevBtn.addEventListener('click', function () { go(state.step - 1); });
        nextBtn.addEventListener('click', function () {
            if (state.step === STEPS.length - 1) { stop(); }
            else { go(state.step + 1); }
        });
        bar.querySelector('.wz-btn-exit').addEventListener('click', stop);

        document.body.classList.add('wz-active');

        if (current) {
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            current.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        }
    }

    function go(step) {
        state = { step: Math.max(0, Math.min(STEPS.length - 1, step)) };
        writeState();
        render();
    }

    function start() {
        // เริ่มที่ขั้นแรกที่หา element เจอ (กันกรณี card บางใบไม่ render เช่นไม่มี tenant)
        var first = 0;
        for (var i = 0; i < STEPS.length; i++) {
            if (STEPS[i].find()) { first = i; break; }
        }
        go(first);
    }

    function stop() {
        state = null;
        writeState();
        allCards().forEach(function (card) {
            card.classList.remove('wz-dim', 'wz-focus');
        });
        if (bar) { bar.remove(); bar = null; }
        document.body.classList.remove('wz-active');
    }

    // resume หลังฟอร์มใน step บันทึก (หน้ารีโหลด) หรือเปิดผ่าน ?wizard=1
    function init() {
        var saved = readState();
        var params = new URLSearchParams(window.location.search);
        if (saved) {
            state = saved;
            render();
        } else if (params.get('wizard') === '1') {
            start();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.WebsiteWizard = { start: start, stop: stop };
})();
