<?php
/**
 * AI Chat Knowledge Base Fixtures
 *
 * Bundles the 3 default knowledge documents as PHP strings so they can be
 * deployed via git (.md files are excluded by .gitignore).
 *
 * Used by: api/ai-telepharmacy-admin.php (action=import_knowledge_md)
 */

return [
    'ระบบประเมินอาการเบื้องต้น' => __DIR__ . '/aichat-knowledge/symptom_assessment.md.txt',
    'ข้อมูลโรค'                 => __DIR__ . '/aichat-knowledge/disease_info.md.txt',
    'Thailand MIMS Clinical Guidelines' => __DIR__ . '/aichat-knowledge/mims_guidelines.md.txt',
];
