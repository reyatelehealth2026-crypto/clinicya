'use client';

/**
 * ShareButtons — port of article.php's `.share-section` (lines 603-622) +
 * its `copyLink()` script (lines 660-666). A small client island (only the
 * copy-link button needs `navigator.clipboard` + a JS handler — the
 * facebook/twitter/line links are plain `<a target="_blank">`s and need no
 * client JS of their own, but they live in the same component since the PHP
 * source renders all four as one `.share-buttons` group).
 *
 * `url` is the fully-qualified article URL (see `_lib/seo.ts`'s
 * `buildArticleUrl` — the NESTED `/articles/[slug]` shape, not
 * `article.php?slug=...`) and `title` is the article's plain title, matching
 * the PHP source's `urlencode(BASE_URL . 'article.php?slug=' . $slug)` /
 * `urlencode($article['title'])` share-link params exactly (Facebook/LINE
 * only take `u`; Twitter/X also takes `text`).
 */
export function ShareButtons({ url, title }: { url: string; title: string }) {
  const encodedUrl = encodeURIComponent(url);
  const encodedTitle = encodeURIComponent(title);

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(url);
      window.alert('คัดลอกลิงก์แล้ว!');
    } catch {
      // Clipboard API can throw (permissions/insecure context) — PHP's version has no failure path
      // either (a rejected promise there just never resolves the .then()), so this silently no-ops too.
    }
  }

  return (
    <div className="mt-8 rounded-xl bg-gray-50 p-6 text-center">
      <div className="mb-4 font-semibold text-gray-700">แชร์บทความนี้</div>
      <div className="flex justify-center gap-3">
        <a
          href={`https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`}
          target="_blank"
          rel="noreferrer"
          title="Share on Facebook"
          aria-label="Share on Facebook"
          className="flex h-11 w-11 items-center justify-center rounded-full bg-[#1877F2] text-white transition hover:scale-110"
        >
          f
        </a>
        <a
          href={`https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodedTitle}`}
          target="_blank"
          rel="noreferrer"
          title="Share on Twitter"
          aria-label="Share on Twitter"
          className="flex h-11 w-11 items-center justify-center rounded-full bg-[#1DA1F2] text-white transition hover:scale-110"
        >
          𝕏
        </a>
        <a
          href={`https://social-plugins.line.me/lineit/share?url=${encodedUrl}`}
          target="_blank"
          rel="noreferrer"
          title="Share on LINE"
          aria-label="Share on LINE"
          className="flex h-11 w-11 items-center justify-center rounded-full bg-[#06C755] text-white transition hover:scale-110"
        >
          L
        </a>
        <button
          type="button"
          onClick={copyLink}
          title="Copy Link"
          aria-label="Copy Link"
          className="flex h-11 w-11 items-center justify-center rounded-full bg-gray-500 text-white transition hover:scale-110"
        >
          🔗
        </button>
      </div>
    </div>
  );
}
