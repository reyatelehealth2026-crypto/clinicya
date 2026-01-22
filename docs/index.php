<?php
/**
 * Documentation Viewer
 * แสดงเอกสาร Markdown ผ่าน Web Interface
 */

$pageTitle = '📚 Documentation';
$docsDir = __DIR__;

// Get list of markdown files
$mdFiles = glob($docsDir . '/*.md');
$docs = [];
foreach ($mdFiles as $file) {
    $filename = basename($file);
    $title = str_replace(['_', '-', '.md'], [' ', ' ', ''], $filename);
    $title = ucwords($title);
    $docs[$filename] = [
        'title' => $title,
        'path' => $file,
        'size' => filesize($file),
        'modified' => filemtime($file)
    ];
}

// Get selected doc
$selectedDoc = $_GET['doc'] ?? 'COMPLETE_DOCUMENTATION.md';
$content = '';
if (isset($docs[$selectedDoc])) {
    $content = file_get_contents($docs[$selectedDoc]['path']);
}

// Simple Markdown to HTML converter
function parseMarkdown($text)
{
    // Escape HTML
    $text = htmlspecialchars($text);

    // Code blocks with language
    $text = preg_replace('/```(\w+)?\n(.*?)```/s', '<pre class="code-block"><code class="language-$1">$2</code></pre>', $text);

    // Inline code
    $text = preg_replace('/`([^`]+)`/', '<code class="inline-code">$1</code>', $text);

    // Headers
    $text = preg_replace('/^######\s+(.*)$/m', '<h6>$1</h6>', $text);
    $text = preg_replace('/^#####\s+(.*)$/m', '<h5>$1</h5>', $text);
    $text = preg_replace('/^####\s+(.*)$/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^###\s+(.*)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^##\s+(.*)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^#\s+(.*)$/m', '<h1>$1</h1>', $text);

    // Bold
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

    // Italic
    $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);

    // Links
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" class="text-blue-600 hover:underline">$1</a>', $text);

    // Tables
    $text = preg_replace_callback('/^\|(.+)\|$/m', function ($matches) {
        $cells = explode('|', trim($matches[1]));
        $row = '<tr>';
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if (preg_match('/^[-:]+$/', $cell)) {
                return ''; // Skip separator row
            }
            $row .= '<td class="border px-3 py-2">' . $cell . '</td>';
        }
        $row .= '</tr>';
        return $row;
    }, $text);

    // Wrap tables
    $text = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<table class="w-full border-collapse mb-4">$0</table>', $text);

    // Horizontal rule
    $text = preg_replace('/^---+$/m', '<hr class="my-6 border-gray-300">', $text);

    // Lists
    $text = preg_replace('/^\s*[-*]\s+(.*)$/m', '<li class="ml-4">$1</li>', $text);
    $text = preg_replace('/(<li.*<\/li>\s*)+/', '<ul class="list-disc mb-4">$0</ul>', $text);

    // Numbered lists
    $text = preg_replace('/^\s*\d+\.\s+(.*)$/m', '<li class="ml-4">$1</li>', $text);

    // Paragraphs
    $text = preg_replace('/\n\n+/', '</p><p class="mb-4">', $text);
    $text = '<p class="mb-4">' . $text . '</p>';

    // Clean up empty paragraphs
    $text = preg_replace('/<p class="mb-4">\s*<\/p>/', '', $text);
    $text = preg_replace('/<p class="mb-4">(\s*<h[1-6]>)/', '$1', $text);
    $text = preg_replace('/(<\/h[1-6]>)\s*<\/p>/', '$1', $text);
    $text = preg_replace('/<p class="mb-4">(\s*<pre)/', '$1', $text);
    $text = preg_replace('/(<\/pre>)\s*<\/p>/', '$1', $text);
    $text = preg_replace('/<p class="mb-4">(\s*<table)/', '$1', $text);
    $text = preg_replace('/(<\/table>)\s*<\/p>/', '$1', $text);
    $text = preg_replace('/<p class="mb-4">(\s*<ul)/', '$1', $text);
    $text = preg_replace('/(<\/ul>)\s*<\/p>/', '$1', $text);
    $text = preg_replace('/<p class="mb-4">(\s*<hr)/', '$1', $text);

    return $text;
}

$htmlContent = parseMarkdown($content);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - CLINICYA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 0.5rem;
            overflow-x: auto;
            margin: 1rem 0;
            font-family: 'Fira Code', monospace;
            font-size: 0.875rem;
            white-space: pre;
        }

        .inline-code {
            background: #f1f5f9;
            color: #dc2626;
            padding: 0.125rem 0.375rem;
            border-radius: 0.25rem;
            font-family: 'Fira Code', monospace;
            font-size: 0.875em;
        }

        h1 {
            font-size: 2rem;
            font-weight: 700;
            margin: 1.5rem 0 1rem;
            color: #1e40af;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 0.5rem;
        }

        h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 1.25rem 0 0.75rem;
            color: #1e3a8a;
        }

        h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 1rem 0 0.5rem;
            color: #1e40af;
        }

        h4 {
            font-size: 1.125rem;
            font-weight: 600;
            margin: 0.75rem 0 0.5rem;
            color: #374151;
        }

        table {
            border: 1px solid #e5e7eb;
        }

        table th {
            background: #f3f4f6;
            font-weight: 600;
        }

        .sidebar {
            min-width: 280px;
        }

        .content {
            max-width: 900px;
        }

        @media print {

            .sidebar,
            .no-print {
                display: none !important;
            }

            .content {
                max-width: 100%;
            }
        }
    </style>
</head>

<body class="bg-gray-100">

    <!-- Header -->
    <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-lg no-print">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="../dashboard.php" class="text-white/80 hover:text-white">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <h1 class="text-xl font-bold">
                        <i class="fas fa-book mr-2"></i>Documentation
                    </h1>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="window.print()" class="px-4 py-2 bg-white/20 rounded-lg hover:bg-white/30">
                        <i class="fas fa-print mr-2"></i>Print
                    </button>
                    <a href="../help.php" class="px-4 py-2 bg-white/20 rounded-lg hover:bg-white/30">
                        <i class="fas fa-question-circle mr-2"></i>Help
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="sidebar bg-white shadow-lg p-4 no-print">
            <h2 class="font-bold text-gray-700 mb-4">
                <i class="fas fa-folder-open mr-2 text-blue-500"></i>Documents
            </h2>
            <nav class="space-y-2">
                <?php foreach ($docs as $filename => $doc): ?>
                    <a href="?doc=<?= urlencode($filename) ?>"
                        class="block px-4 py-3 rounded-lg transition <?= $selectedDoc === $filename ? 'bg-blue-100 text-blue-700 font-medium' : 'hover:bg-gray-100' ?>">
                        <div class="flex items-center gap-3">
                            <i
                                class="fas fa-file-alt <?= $selectedDoc === $filename ? 'text-blue-500' : 'text-gray-400' ?>"></i>
                            <div>
                                <div class="text-sm"><?= htmlspecialchars($doc['title']) ?></div>
                                <div class="text-xs text-gray-400">
                                    <?= number_format($doc['size'] / 1024, 1) ?> KB •
                                    <?= date('d M Y', $doc['modified']) ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </nav>

            <hr class="my-4">

            <h3 class="font-bold text-gray-700 mb-3">
                <i class="fas fa-link mr-2 text-green-500"></i>Quick Links
            </h3>
            <nav class="space-y-1 text-sm">
                <a href="../dev-dashboard.php" class="block px-3 py-2 rounded hover:bg-gray-100">
                    <i class="fas fa-code mr-2 text-purple-500"></i>Dev Dashboard
                </a>
                <a href="../testing-checklist.php" class="block px-3 py-2 rounded hover:bg-gray-100">
                    <i class="fas fa-tasks mr-2 text-orange-500"></i>Testing Checklist
                </a>
                <a href="../help.php" class="block px-3 py-2 rounded hover:bg-gray-100">
                    <i class="fas fa-question-circle mr-2 text-blue-500"></i>Help Center
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6">
            <div class="content mx-auto bg-white rounded-xl shadow-lg p-8">
                <!-- Document Header -->
                <div class="flex items-center justify-between mb-6 pb-4 border-b no-print">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">
                            <?= htmlspecialchars($docs[$selectedDoc]['title'] ?? 'Document') ?>
                        </h1>
                        <p class="text-sm text-gray-500 mt-1">
                            <i class="fas fa-calendar mr-1"></i>
                            Last updated: <?= date('d M Y H:i', $docs[$selectedDoc]['modified'] ?? time()) ?>
                            <span class="mx-2">•</span>
                            <i class="fas fa-file mr-1"></i>
                            <?= number_format(($docs[$selectedDoc]['size'] ?? 0) / 1024, 1) ?> KB
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="copyToClipboard()"
                            class="px-3 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm">
                            <i class="fas fa-copy mr-1"></i>Copy
                        </button>
                        <a href="<?= htmlspecialchars($selectedDoc) ?>" download
                            class="px-3 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 text-sm">
                            <i class="fas fa-download mr-1"></i>Download
                        </a>
                    </div>
                </div>

                <!-- Table of Contents -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6 no-print" id="toc">
                    <h3 class="font-bold text-gray-700 mb-2">
                        <i class="fas fa-list mr-2"></i>Table of Contents
                    </h3>
                    <div id="tocContent" class="text-sm space-y-1"></div>
                </div>

                <!-- Document Content -->
                <article class="prose max-w-none" id="docContent">
                    <?= $htmlContent ?>
                </article>
            </div>
        </main>
    </div>

    <script>
        // Generate Table of Contents
        document.addEventListener('DOMContentLoaded', function () {
            const content = document.getElementById('docContent');
            const tocContent = document.getElementById('tocContent');
            const headers = content.querySelectorAll('h1, h2, h3');

            let toc = '';
            headers.forEach((header, index) => {
                const id = 'section-' + index;
                header.id = id;

                const level = parseInt(header.tagName.charAt(1));
                const indent = (level - 1) * 16;

                toc += `<a href="#${id}" class="block py-1 hover:text-blue-600" style="padding-left: ${indent}px">
                ${header.textContent}
            </a>`;
            });

            tocContent.innerHTML = toc;
        });

        // Copy to clipboard
        function copyToClipboard() {
            const content = document.getElementById('docContent').innerText;
            navigator.clipboard.writeText(content).then(() => {
                alert('Copied to clipboard!');
            });
        }

        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>
</body>

</html>