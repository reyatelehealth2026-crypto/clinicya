<?php
/**
 * Property-Based Test: Setup checklist URLs resolve to real pages
 *
 * **Feature: onboarding-saas-alignment, Property 2: Checklist links exist**
 *
 * Property: every `url` in SetupStatusChecker::SETUP_CHECKLIST must resolve to
 * a page that actually exists in the repo (a .php file, or a directory index
 * for clean-URL hubs like /inventory/). A 404 link in the setup checklist
 * sends a fresh tenant admin to a dead page.
 */

namespace Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use Modules\Onboarding\SetupStatusChecker;

class SetupChecklistUrlsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(__DIR__ . '/../../');
    }

    /**
     * Resolve a site-absolute URL to a repo file, honouring clean URLs
     * (.htaccess strips .php) and directory indexes.
     */
    private function urlResolvesToFile(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        $path = trim($path, '/');
        if ($path === '') {
            return is_file($this->root . '/index.php');
        }
        $candidates = [
            "{$this->root}/{$path}",            // exact (e.g. inventory/index.php passed directly)
            "{$this->root}/{$path}.php",        // clean URL → add .php
            "{$this->root}/{$path}/index.php",  // directory hub → index.php
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    public static function checklistUrlProvider(): array
    {
        $rows = [];
        foreach (SetupStatusChecker::SETUP_CHECKLIST as $category => $items) {
            foreach ($items as $key => $item) {
                $rows[] = [(string) $category, (string) $key, (string) ($item['url'] ?? '')];
            }
        }
        return $rows;
    }

    /**
     * @dataProvider checklistUrlProvider
     */
    public function testEveryChecklistUrlExists(string $category, string $key, string $url): void
    {
        $this->assertNotSame('', $url, "Checklist item {$category}.{$key} has no url");
        $this->assertTrue(
            $this->urlResolvesToFile($url),
            "Checklist URL for {$category}.{$key} → '{$url}' does not resolve to an existing page"
        );
    }
}
