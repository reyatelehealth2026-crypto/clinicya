/**
 * SearchBox — port of articles.php's `.search-form` (lines 416-427): a
 * plain GET form, `name="q"`, no client JS. Submitting it always lands on
 * `/articles?q=...` with no other query params carried over — the PHP
 * source's `<form method="GET">` has no `action` attribute (so the browser
 * submits to the current path) and no hidden `category` field, so a
 * category filter active before searching is dropped by the browser's own
 * GET-form serialization, not by any app code. `action="/articles"` here is
 * the explicit equivalent of that same implicit behavior.
 */
export function SearchBox({ defaultValue }: { defaultValue: string }) {
  return (
    <form method="GET" action="/articles" role="search" className="mx-auto flex max-w-xl gap-3">
      <input
        type="text"
        name="q"
        defaultValue={defaultValue}
        placeholder="ค้นหาบทความ..."
        aria-label="ค้นหาบทความ"
        className="flex-1 rounded-xl border-2 border-gray-200 px-4 py-3 text-base focus:border-emerald-600 focus:outline-none"
      />
      <button type="submit" className="rounded-xl bg-emerald-600 px-6 py-3 font-semibold text-white hover:opacity-90" aria-label="ค้นหา">
        🔍
      </button>
    </form>
  );
}
