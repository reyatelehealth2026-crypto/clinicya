<?php
/**
 * Property-Based Test: Duplicate Files Are Removed
 * 
 * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.1, 2.2, 2.3, 2.4, 2.5**
 * 
 * Property: For any file marked as duplicate in the consolidation plan, 
 * the file SHALL NOT exist in the project after consolidation.
 */

namespace Tests\FileConsolidation;

use PHPUnit\Framework\TestCase;

class DuplicateFilesRemovalPropertyTest extends TestCase
{
    /**
     * Project root directory
     */
    private string $projectRoot;

    /**
     * Files that should be removed according to Requirements 1.x (100% duplicates)
     * These are exact duplicates that should be deleted
     */
    private array $duplicateFilesToRemove = [
        // Requirement 1.1: users_new.php is duplicate of users.php
        'users_new.php',
        // Requirement 1.2: shop/orders_new.php is duplicate of shop/orders.php
        'shop/orders_new.php',
        // Requirement 1.3: shop/order-detail-new.php is duplicate of shop/order-detail.php
        'shop/order-detail-new.php',
        // Requirement 1.4: test files should be removed
        't.php',
        'test.php',
    ];

    /**
     * Files that should be removed after version consolidation (Requirements 2.x)
     * Old versions that should be replaced by newer versions
     * 
     * Note: Based on the actual implementation:
     * - broadcast-catalog was consolidated into broadcast.php with tabs (not renamed from v2)
     * - flex-builder-v2 was renamed to flex-builder.php
     * - video-call-pro was renamed to video-call.php
     * - messages-v2 was merged into messages.php
     * - liff-shop-v3 is part of Phase 3 (LIFF cleanup) which may not be complete yet
     */
    private array $versionedFilesToRemove = [
        // Requirement 2.1: broadcast-catalog-v2.php should not exist (consolidated into broadcast.php)
        'broadcast-catalog-v2.php',
        // Requirement 2.2: flex-builder-v2.php should not exist (renamed to flex-builder.php)
        'flex-builder-v2.php',
        // Requirement 2.4: video-call old versions should not exist (pro renamed to video-call.php)
        'video-call-v2.php',
        'video-call-simple.php',
        'video-call-pro.php',
        // Requirement 2.5: messages-v2.php should not exist (merged into messages.php)
        'messages-v2.php',
    ];

    /**
     * LIFF files to remove - Part of Phase 3 (Task 4)
     * These are tested separately as they depend on Phase 3 completion
     */
    private array $liffFilesToRemove = [
        // Requirement 2.3: liff-shop-v3.php should be renamed to liff-shop.php
        // This is part of Phase 3 which handles all LIFF file cleanup
        'liff-shop-v3.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = realpath(__DIR__ . '/../../');
    }

    /**
     * Property Test: Duplicate files (100% duplicates) should not exist
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 1.1, 1.2, 1.3, 1.4**
     */
    public function testDuplicateFilesDoNotExist(): void
    {
        $existingDuplicates = [];

        foreach ($this->duplicateFilesToRemove as $file) {
            $filePath = $this->projectRoot . '/' . $file;
            if (file_exists($filePath)) {
                $existingDuplicates[] = $file;
            }
        }

        $this->assertEmpty(
            $existingDuplicates,
            "The following duplicate files should have been removed but still exist:\n- " . 
            implode("\n- ", $existingDuplicates)
        );
    }

    /**
     * Property Test: Versioned files should be consolidated (old versions removed)
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 2.1, 2.2, 2.4, 2.5**
     */
    public function testVersionedFilesAreConsolidated(): void
    {
        $existingVersionedFiles = [];

        foreach ($this->versionedFilesToRemove as $file) {
            $filePath = $this->projectRoot . '/' . $file;
            if (file_exists($filePath)) {
                $existingVersionedFiles[] = $file;
            }
        }

        $this->assertEmpty(
            $existingVersionedFiles,
            "The following versioned files should have been consolidated but still exist:\n- " . 
            implode("\n- ", $existingVersionedFiles)
        );
    }

    /**
     * Property Test: LIFF versioned files should be consolidated (Phase 3)
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 2.3**
     * 
     * Note: This test validates Phase 3 completion. If Phase 3 is not complete,
     * this test will fail as expected.
     */
    public function testLiffVersionedFilesAreConsolidated(): void
    {
        $existingLiffFiles = [];

        foreach ($this->liffFilesToRemove as $file) {
            $filePath = $this->projectRoot . '/' . $file;
            if (file_exists($filePath)) {
                $existingLiffFiles[] = $file;
            }
        }

        $this->assertEmpty(
            $existingLiffFiles,
            "The following LIFF versioned files should have been consolidated but still exist:\n- " . 
            implode("\n- ", $existingLiffFiles) .
            "\n\nNote: This may indicate Phase 3 (LIFF cleanup) is not yet complete."
        );
    }

    /**
     * Property Test: For any randomly selected duplicate file, it should not exist
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.1, 2.2, 2.4, 2.5**
     */
    public function testRandomDuplicateFileDoesNotExist(): void
    {
        $allFilesToRemove = array_merge(
            $this->duplicateFilesToRemove,
            $this->versionedFilesToRemove
        );

        // Property-based testing: test 100 random selections
        for ($i = 0; $i < 100; $i++) {
            $randomIndex = array_rand($allFilesToRemove);
            $file = $allFilesToRemove[$randomIndex];
            $filePath = $this->projectRoot . '/' . $file;

            $this->assertFileDoesNotExist(
                $filePath,
                "Duplicate file '{$file}' should not exist in the project"
            );
        }
    }

    /**
     * Data provider for individual file removal tests
     */
    public static function duplicateFileProvider(): array
    {
        return [
            // Requirement 1.1
            'users_new.php' => ['users_new.php', '1.1'],
            // Requirement 1.2
            'shop/orders_new.php' => ['shop/orders_new.php', '1.2'],
            // Requirement 1.3
            'shop/order-detail-new.php' => ['shop/order-detail-new.php', '1.3'],
            // Requirement 1.4
            't.php' => ['t.php', '1.4'],
            'test.php' => ['test.php', '1.4'],
        ];
    }

    /**
     * Data provider for versioned file removal tests
     */
    public static function versionedFileProvider(): array
    {
        return [
            // Requirement 2.1
            'broadcast-catalog-v2.php' => ['broadcast-catalog-v2.php', '2.1'],
            // Requirement 2.2
            'flex-builder-v2.php' => ['flex-builder-v2.php', '2.2'],
            // Requirement 2.4
            'video-call-v2.php' => ['video-call-v2.php', '2.4'],
            'video-call-simple.php' => ['video-call-simple.php', '2.4'],
            'video-call-pro.php' => ['video-call-pro.php', '2.4'],
            // Requirement 2.5
            'messages-v2.php' => ['messages-v2.php', '2.5'],
        ];
    }

    /**
     * Property Test: Each duplicate file should not exist
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 1.1, 1.2, 1.3, 1.4**
     * 
     * @dataProvider duplicateFileProvider
     */
    public function testEachDuplicateFileDoesNotExist(string $file, string $requirement): void
    {
        $filePath = $this->projectRoot . '/' . $file;

        $this->assertFileDoesNotExist(
            $filePath,
            "Duplicate file '{$file}' should not exist (Requirement {$requirement})"
        );
    }

    /**
     * Property Test: Each versioned file should be consolidated
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 2.1, 2.2, 2.4, 2.5**
     * 
     * @dataProvider versionedFileProvider
     */
    public function testEachVersionedFileIsConsolidated(string $file, string $requirement): void
    {
        $filePath = $this->projectRoot . '/' . $file;

        $this->assertFileDoesNotExist(
            $filePath,
            "Versioned file '{$file}' should have been consolidated (Requirement {$requirement})"
        );
    }

    /**
     * Property Test: Consolidated files should exist after version merge
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 2.1, 2.2, 2.4, 2.5**
     */
    public function testConsolidatedFilesExist(): void
    {
        $consolidatedFiles = [
            // Requirement 2.1: broadcast pages consolidated into broadcast.php with tabs
            'broadcast.php' => '2.1',
            // Requirement 2.2: flex-builder-v2.php renamed to flex-builder.php
            'flex-builder.php' => '2.2',
            // Requirement 2.4: video-call-pro.php renamed to video-call.php
            'video-call.php' => '2.4',
            // Requirement 2.5: messages.php should exist with merged content
            'messages.php' => '2.5',
        ];

        $missingFiles = [];

        foreach ($consolidatedFiles as $file => $requirement) {
            $filePath = $this->projectRoot . '/' . $file;
            if (!file_exists($filePath)) {
                $missingFiles[] = "{$file} (Requirement {$requirement})";
            }
        }

        $this->assertEmpty(
            $missingFiles,
            "The following consolidated files should exist but are missing:\n- " . 
            implode("\n- ", $missingFiles)
        );
    }

    /**
     * Property Test: No _new.php suffix files should exist at root level
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 1.1, 1.2, 1.3**
     */
    public function testNoNewSuffixFilesExistAtRoot(): void
    {
        $newSuffixFiles = glob($this->projectRoot . '/*_new.php');
        
        $this->assertEmpty(
            $newSuffixFiles,
            "No files with '_new.php' suffix should exist at root level. Found:\n- " . 
            implode("\n- ", array_map('basename', $newSuffixFiles ?: []))
        );
    }

    /**
     * Property Test: No _new.php suffix files should exist in shop folder
     * 
     * **Feature: file-consolidation, Property 6: Duplicate Files Are Removed**
     * **Validates: Requirements 1.2, 1.3**
     */
    public function testNoNewSuffixFilesExistInShop(): void
    {
        $shopPath = $this->projectRoot . '/shop';
        if (!is_dir($shopPath)) {
            $this->markTestSkipped('Shop directory does not exist');
        }

        $newSuffixFiles = glob($shopPath . '/*_new.php');
        
        $this->assertEmpty(
            $newSuffixFiles,
            "No files with '_new.php' suffix should exist in shop folder. Found:\n- " . 
            implode("\n- ", array_map('basename', $newSuffixFiles ?: []))
        );
    }
}
