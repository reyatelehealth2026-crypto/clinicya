<?php
/**
 * Help / Tutorials Hub
 * /help.php — ศูนย์รวม task-based tutorials สำหรับ admin
 *
 * Loads markdown files from docs/admin-tutorials/*.md, parses frontmatter
 * + step blocks (## ขั้นที่ N: ...), and renders a searchable card grid.
 */

$pageTitle = 'ศูนย์ช่วยเหลือ';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/header.php';

/**
 * Parse one tutorial markdown file
 * Returns ['meta'=>frontmatter, 'steps'=>[{heading, body, tip}]] or null
 */
function parseTutorial(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    // Extract frontmatter between --- markers
    $meta = [];
    if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m)) {
        $frontmatter = $m[1];
        $body = $m[2];
        foreach (preg_split('/\r?\n/', $frontmatter) as $line) {
            if (preg_match('/^(\w+):\s*(.+)$/', $line, $kv)) {
                $meta[trim($kv[1])] = trim($kv[2]);
            }
        }
    } else {
        return null;
    }

    // Split body into step blocks at "## "
    $steps = [];
    $parts = preg_split('/^##\s+/m', $body);
    array_shift($parts); // discard pre-first-heading content
    foreach ($parts as $part) {
        $lines = preg_split('/\r?\n/', trim($part));
        $heading = array_shift($lines);
        $rest = implode("\n", $lines);

        // Extract tip ("> 💡 ..." or "> ⚠️ ...")
        $tip = null;
        if (preg_match('/^>\s*(.+)$/m', $rest, $tm)) {
            $tip = trim($tm[1]);
            $rest = preg_replace('/^>\s*.+$/m', '', $rest);
        }

        // Convert simple markdown: **bold**, `code`, - lists
        $html = trim($rest);
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 px-1.5 py-0.5 rounded text-sm">$1</code>', $html);

        if (preg_match('/^- /m', $html)) {
            $htmlLines = preg_split('/\r?\n/', $html);
            $out = [];
            $inList = false;
            foreach ($htmlLines as $ln) {
                if (preg_match('/^- (.+)$/', $ln, $lm)) {
                    if (!$inList) { $out[] = '<ul class="list-disc pl-5 space-y-1 my-2">'; $inList = true; }
                    $out[] = '<li>' . $lm[1] . '</li>';
                } else {
                    if ($inList) { $out[] = '</ul>'; $inList = false; }
                    if (trim($ln) !== '') $out[] = '<p class="my-2">' . $ln . '</p>';
                }
            }
            if ($inList) $out[] = '</ul>';
            $html = implode("\n", $out);
        } else {
            $html = '<p>' . nl2br($html) . '</p>';
        }

        $steps[] = ['heading' => $heading, 'body' => $html, 'tip' => $tip];
    }

    return ['meta' => $meta, 'steps' => $steps];
}

// Load all tutorials from docs/admin-tutorials/*.md
$tutorialDir = __DIR__ . '/docs/admin-tutorials';
$files = glob($tutorialDir . '/*.md') ?: [];
sort($files);
$tutorials = [];
foreach ($files as $f) {
    $parsed = parseTutorial($f);
    if (!$parsed) continue;
    $id = basename($f, '.md');
    $tutorials[] = array_merge(['id' => $id], $parsed['meta'], ['steps' => $parsed['steps']]);
}

// Categories (Thai labels — must match frontmatter `category:`)
$categories = ['ทั้งหมด', 'เริ่มต้น', 'งานประจำวัน', 'การตลาด', 'ขั้นสูง'];

// JSON for JS modal — minimal payload
$jsTutorials = array_map(function ($t) {
    return [
        'id' => $t['id'],
        'title' => $t['title'] ?? '',
        'subtitle' => $t['subtitle'] ?? '',
        'icon' => $t['icon'] ?? '📘',
        'difficulty' => $t['difficulty'] ?? 'เริ่มต้น',
        'related_link' => $t['related_link'] ?? '',
        'steps' => $t['steps'],
    ];
}, $tutorials);
?>

<style>
  .help-card { transition: transform .15s ease, box-shadow .15s ease; }
  .help-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -8px rgba(0,0,0,0.15); }
  .cat-tab.active { background: linear-gradient(90deg, #10b981, #059669); color: white; border-color: transparent; }
  .diff-เริ่มต้น { background: #dbeafe; color: #1e40af; }
  .diff-กลาง { background: #fef3c7; color: #92400e; }
  .diff-สูง { background: #fee2e2; color: #991b1b; }
</style>

<div class="max-w-7xl mx-auto px-4 py-6">

  <!-- Hero -->
  <section class="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-3xl p-8 md:p-10 text-white mb-6 shadow-lg">
    <h1 class="text-3xl md:text-4xl font-bold mb-2">📚 ศูนย์ช่วยเหลือ</h1>
    <p class="text-emerald-50 mb-5">คู่มือทีละขั้นตอนสำหรับงานยอดนิยม ใช้งานง่าย เริ่มได้ทันที</p>

    <div class="relative max-w-2xl">
      <input id="help-search" type="text" placeholder="ค้นหา tutorial เช่น 'จ่ายยา', 'broadcast'…"
             class="w-full px-5 py-3 pl-12 rounded-full text-gray-800 focus:outline-none focus:ring-4 focus:ring-emerald-300 shadow">
      <i class="fas fa-search absolute left-5 top-1/2 -translate-y-1/2 text-gray-400"></i>
    </div>
  </section>

  <!-- Category Tabs -->
  <div class="flex flex-wrap gap-2 mb-6">
    <?php foreach ($categories as $cat): ?>
      <button class="cat-tab px-4 py-2 rounded-full border border-gray-200 bg-white text-sm font-medium hover:bg-gray-50 <?= $cat === 'ทั้งหมด' ? 'active' : '' ?>"
              data-cat="<?= htmlspecialchars($cat) ?>">
        <?= htmlspecialchars($cat) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Card Grid -->
  <div id="tutorial-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-10">
    <?php foreach ($tutorials as $t): ?>
      <div class="help-card cursor-pointer bg-white rounded-2xl border border-gray-100 p-6 flex flex-col"
           data-tutorial-id="<?= htmlspecialchars($t['id']) ?>"
           data-cat="<?= htmlspecialchars($t['category'] ?? 'เริ่มต้น') ?>"
           data-search="<?= htmlspecialchars(mb_strtolower(($t['title'] ?? '') . ' ' . ($t['subtitle'] ?? ''))) ?>"
           onclick="openTutorial('<?= htmlspecialchars($t['id']) ?>')">
        <div class="text-5xl mb-3"><?= $t['icon'] ?? '📘' ?></div>
        <h3 class="text-lg font-bold text-gray-800 mb-1"><?= htmlspecialchars($t['title'] ?? '') ?></h3>
        <p class="text-sm text-gray-500 mb-4 flex-1 leading-relaxed"><?= htmlspecialchars($t['subtitle'] ?? '') ?></p>
        <div class="flex items-center justify-between text-xs">
          <span class="text-gray-500"><i class="far fa-clock mr-1"></i><?= htmlspecialchars($t['duration'] ?? '') ?></span>
          <span class="diff-<?= htmlspecialchars($t['difficulty'] ?? 'เริ่มต้น') ?> px-2.5 py-1 rounded-full font-medium">
            <?= htmlspecialchars($t['difficulty'] ?? 'เริ่มต้น') ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Empty state -->
  <div id="help-empty" class="hidden text-center py-10 text-gray-400">
    <i class="fas fa-search text-4xl mb-3 block"></i>
    ไม่พบ tutorial ที่ตรงกับการค้นหา
  </div>

  <!-- Contact CTA -->
  <section class="bg-gradient-to-r from-slate-800 to-slate-900 rounded-2xl p-6 md:p-8 text-white flex flex-col md:flex-row items-center justify-between gap-4">
    <div>
      <h2 class="text-xl font-bold mb-1">ยังไม่ได้คำตอบ?</h2>
      <p class="text-slate-300 text-sm">ทีมงานพร้อมช่วยเหลือคุณตลอด 24/7</p>
    </div>
    <div class="flex gap-3">
      <a href="https://line.me/R/ti/p/@cny" target="_blank"
         class="px-5 py-3 bg-green-500 hover:bg-green-600 rounded-xl font-semibold inline-flex items-center gap-2">
        <i class="fab fa-line"></i> LINE ทีมงาน
      </a>
      <a href="mailto:support@re-ya.com"
         class="px-5 py-3 bg-white/10 hover:bg-white/20 rounded-xl font-semibold inline-flex items-center gap-2">
        <i class="far fa-envelope"></i> ส่งอีเมล
      </a>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/help/tutorial-modal.php'; ?>

<script>
  window.__TUTORIALS__ = <?= json_encode($jsTutorials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  // Category filter
  let activeCat = 'ทั้งหมด';
  document.querySelectorAll('.cat-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      activeCat = btn.dataset.cat;
      applyFilters();
    });
  });

  const searchInput = document.getElementById('help-search');
  searchInput.addEventListener('input', applyFilters);

  function applyFilters() {
    const q = searchInput.value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('#tutorial-grid .help-card').forEach(card => {
      const matchCat = (activeCat === 'ทั้งหมด') || (card.dataset.cat === activeCat);
      const matchSearch = !q || (card.dataset.search || '').includes(q) || card.textContent.toLowerCase().includes(q);
      const show = matchCat && matchSearch;
      card.style.display = show ? '' : 'none';
      if (show) shown++;
    });
    document.getElementById('help-empty').classList.toggle('hidden', shown > 0);
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
