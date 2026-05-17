<?php
/**
 * Tutorial Modal Component
 * แสดง step-by-step tutorial ใน popup modal
 *
 * ใช้ร่วมกับ help.php — Tutorial data ฝังเป็น JSON ใน window.__TUTORIALS__ จาก help.php
 */
?>
<!-- Tutorial Modal Overlay -->
<div id="tutorial-modal" class="hidden fixed inset-0 z-[200] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" role="dialog" aria-modal="true">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden">

    <!-- Progress Bar -->
    <div class="h-1.5 bg-gray-100">
      <div id="tut-progress-bar" class="h-full bg-gradient-to-r from-green-500 to-emerald-500 transition-all duration-300" style="width: 0%"></div>
    </div>

    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <div class="flex items-center gap-3 min-w-0">
        <span id="tut-icon" class="text-3xl">📘</span>
        <div class="min-w-0">
          <h2 id="tut-title" class="text-lg font-bold text-gray-800 truncate">Tutorial Title</h2>
          <p id="tut-subtitle" class="text-xs text-gray-500 truncate">Subtitle here</p>
        </div>
      </div>
      <button id="tut-close" class="w-9 h-9 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500" aria-label="ปิด">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Step Indicator -->
    <div class="px-6 py-3 bg-gray-50 border-b flex items-center justify-between text-sm">
      <span id="tut-step-indicator" class="font-medium text-gray-700">ขั้นที่ 1 จาก 5</span>
      <span id="tut-difficulty-badge" class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">เริ่มต้น</span>
    </div>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto px-6 py-5" id="tut-content">
      <h3 id="tut-step-heading" class="text-xl font-bold text-gray-800 mb-3">Loading...</h3>
      <div id="tut-step-body" class="prose prose-sm max-w-none text-gray-700 leading-relaxed"></div>

      <!-- Image (auto-hides if file missing) -->
      <div id="tut-step-image" class="hidden mt-4 rounded-xl overflow-hidden border border-gray-200 bg-gray-50">
        <img id="tut-image-el" alt="" class="w-full h-auto block"
             onerror="document.getElementById('tut-step-image').classList.add('hidden')"
             onload="document.getElementById('tut-step-image').classList.remove('hidden')" />
      </div>

      <!-- Tip Box -->
      <div id="tut-step-tip" class="hidden mt-4 p-3 rounded-xl bg-yellow-50 border border-yellow-200 text-sm text-yellow-900">
        <span class="font-semibold">💡 Tip: </span><span id="tut-tip-text"></span>
      </div>
    </div>

    <!-- Footer Buttons -->
    <div class="px-6 py-4 border-t bg-gray-50 flex items-center justify-between">
      <button id="tut-prev" class="px-4 py-2 rounded-lg text-gray-600 hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed">
        ← ย้อน
      </button>
      <div class="flex items-center gap-2">
        <a id="tut-try-now" href="#" target="_blank" class="hidden px-4 py-2 rounded-lg bg-emerald-100 text-emerald-700 hover:bg-emerald-200 text-sm font-medium">
          🚀 ลองทำเลย
        </a>
        <button id="tut-next" class="px-5 py-2 rounded-lg bg-gradient-to-r from-green-500 to-emerald-500 text-white font-medium hover:opacity-90">
          ถัดไป →
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const modal = document.getElementById('tutorial-modal');
  const $ = (id) => document.getElementById(id);
  let currentTut = null;
  let currentStep = 0;

  function open(tutorialId) {
    const tut = (window.__TUTORIALS__ || []).find(t => t.id === tutorialId);
    if (!tut) return;
    currentTut = tut;
    currentStep = 0;
    $('tut-icon').textContent = tut.icon || '📘';
    $('tut-title').textContent = tut.title;
    $('tut-subtitle').textContent = tut.subtitle;
    $('tut-difficulty-badge').textContent = tut.difficulty;
    $('tut-difficulty-badge').className = 'px-2.5 py-0.5 rounded-full text-xs font-medium ' + difficultyClass(tut.difficulty);
    render();
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }

  function close() {
    modal.classList.add('hidden');
    document.body.style.overflow = '';
    currentTut = null;
  }

  function difficultyClass(d) {
    if (d === 'เริ่มต้น') return 'bg-blue-100 text-blue-700';
    if (d === 'กลาง') return 'bg-amber-100 text-amber-700';
    return 'bg-rose-100 text-rose-700';
  }

  function render() {
    if (!currentTut) return;
    const total = currentTut.steps.length;
    const step = currentTut.steps[currentStep];
    const pct = Math.round(((currentStep + 1) / total) * 100);
    $('tut-progress-bar').style.width = pct + '%';
    $('tut-step-indicator').textContent = `ขั้นที่ ${currentStep + 1} จาก ${total}`;
    $('tut-step-heading').textContent = step.heading;
    $('tut-step-body').innerHTML = step.body;
    // Set image src — onerror handler will hide the block if file missing
    const imgEl = $('tut-image-el');
    $('tut-step-image').classList.add('hidden');
    imgEl.src = `/uploads/tutorials/${currentTut.id}/step${currentStep + 1}.png?t=${Date.now()}`;
    if (step.tip) {
      $('tut-step-tip').classList.remove('hidden');
      $('tut-tip-text').textContent = step.tip;
    } else {
      $('tut-step-tip').classList.add('hidden');
    }
    $('tut-prev').disabled = currentStep === 0;
    const isLast = currentStep === total - 1;
    $('tut-next').textContent = isLast ? 'เสร็จสิ้น ✓' : 'ถัดไป →';
    if (isLast && currentTut.related_link) {
      $('tut-try-now').classList.remove('hidden');
      $('tut-try-now').href = currentTut.related_link;
    } else {
      $('tut-try-now').classList.add('hidden');
    }
  }

  $('tut-close').addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
  $('tut-prev').addEventListener('click', () => { if (currentStep > 0) { currentStep--; render(); } });
  $('tut-next').addEventListener('click', () => {
    if (!currentTut) return;
    if (currentStep < currentTut.steps.length - 1) { currentStep++; render(); }
    else { close(); }
  });

  // Expose globally
  window.openTutorial = open;
})();
</script>
