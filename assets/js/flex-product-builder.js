/**
 * FlexProductBuilder — deterministic LINE Flex builder from real product data.
 *
 * Browser port of the /broadcast skill's build-flex-2up.cjs. Given products
 * (price/SKU/image straight from the DB) plus AI-written copy, it assembles a
 * Flex carousel. Prices and identifiers are never derived from the model, so
 * the displayed price always matches the catalogue.
 *
 * Usage:
 *   const flex = FlexProductBuilder.build({
 *     products,            // [{ sku, name, image, basePrice, promotionPrice, unit, url }]
 *     copy,                // { title, intro, ctaLabel, badgeText, footerText, closingText }
 *     theme,               // 'promotion' | 'flash_sale' | 'bestseller' | 'new_arrival' | 'product_catalog'
 *     color,               // optional hex; overrides the theme colour
 *     layout,              // '2up' (default) | '1up'
 *     shopUrl,             // optional fallback CTA url
 *   });
 *   // → { type:'bubble'|'carousel', ... }   (single bubble when 1 product & no cover)
 *
 * Returns an object suitable for FlexPreview.render and for the existing
 * "ใช้ใน Broadcast" handoff. Carousels are capped at 12 bubbles.
 *
 * @spec ai-studio-flex-product-picker
 */
(function (global) {
  'use strict';

  var PLACEHOLDER = 'https://manager.cnypharmacy.com/uploads/product_photo/placeholder.jpg';
  var MAX_BUBBLES = 12;

  var THEME = {
    promotion:       { color: '#E53E3E', icon: '🔥', badge: 'PROMOTION' },
    flash_sale:      { color: '#D69E2E', icon: '⚡', badge: 'FLASH SALE' },
    bestseller:      { color: '#15803D', icon: '🏆', badge: 'BESTSELLER' },
    new_arrival:     { color: '#805AD5', icon: '✨', badge: 'NEW' },
    product_catalog: { color: '#4299E1', icon: '🛍️', badge: 'CATALOG' },
  };

  // Map the studio "Flex type" select onto a builder theme.
  var TYPE_TO_THEME = {
    product: 'product_catalog',
    promo:   'promotion',
    promotion: 'promotion',
    flash_sale: 'flash_sale',
    bestseller: 'bestseller',
    new_arrival: 'new_arrival',
  };

  function toN(v) {
    if (v == null) return 0;
    var n = typeof v === 'number' ? v : Number(v);
    return isFinite(n) ? n : 0;
  }

  function money(n) { return '฿' + Math.round(toN(n)); }

  function resolveTheme(theme) {
    return THEME[theme] || THEME.promotion;
  }

  // Normalize a picker product into the exact fields the builder draws.
  function mapProduct(p, fallbackUrl) {
    var base = toN(p.basePrice != null ? p.basePrice : p.price);
    var promoRaw = toN(p.promotionPrice != null ? p.promotionPrice : p.sale_price);
    var promo = promoRaw > 0 && promoRaw < base ? promoRaw : null;
    return {
      sku: String(p.sku || ''),
      name: String(p.name || ''),
      image: p.image || p.image_url || PLACEHOLDER,
      basePrice: base,
      promotionPrice: promo,
      unit: String(p.unit || ''),
      url: p.url || fallbackUrl,
    };
  }

  function buildCover(cfg) {
    var c = cfg.color, count = cfg.products.length;
    return {
      type: 'bubble', size: 'mega',
      styles: { body: { backgroundColor: c }, footer: { backgroundColor: c } },
      body: {
        type: 'box', layout: 'vertical', spacing: 'md', paddingAll: 'xl',
        contents: [
          { type: 'text', text: cfg.icon, size: 'xxl', align: 'center' },
          { type: 'text', text: cfg.copy.title, weight: 'bold', size: 'xl', color: '#FFFFFF', align: 'center', wrap: true },
          cfg.copy.intro
            ? { type: 'text', text: cfg.copy.intro, size: 'sm', color: '#FFFFFF', align: 'center', wrap: true, margin: 'md' }
            : { type: 'filler' },
          { type: 'box', layout: 'vertical', backgroundColor: '#FFFFFF22', cornerRadius: 'md',
            paddingAll: 'md', margin: 'xl',
            contents: [
              { type: 'text', text: count + ' รายการ', color: '#FFFFFF', weight: 'bold', align: 'center', size: 'lg' },
              { type: 'text', text: 'พร้อมรายละเอียดและราคา', color: '#FFFFFFcc', align: 'center', size: 'xs', margin: 'sm' },
            ] },
          cfg.copy.footerText
            ? { type: 'text', text: cfg.copy.footerText, color: '#FFFFFFcc', size: 'xs', align: 'center', wrap: true, margin: 'lg' }
            : { type: 'filler' },
        ],
      },
      footer: {
        type: 'box', layout: 'vertical', paddingAll: 'md',
        contents: [
          { type: 'button', style: 'secondary', height: 'sm', color: '#FFFFFF',
            action: { type: 'uri', label: 'ดูทั้งหมด', uri: cfg.shopUrl } },
        ],
      },
    };
  }

  // One product as a vertical half (image, name, price, CTA).
  function buildHalf(p, isFirst, cfg) {
    var priceRow;
    if (p.promotionPrice != null) {
      priceRow = [
        { type: 'text', text: money(p.promotionPrice), size: 'lg', weight: 'bold', color: cfg.color, flex: 0 },
        { type: 'text', text: money(p.basePrice), size: 'sm', color: '#A0AEC0', decoration: 'line-through', margin: 'sm', flex: 0 },
      ];
    } else {
      priceRow = [
        { type: 'text', text: money(p.basePrice), size: 'lg', weight: 'bold', color: cfg.color, flex: 0 },
      ];
    }
    priceRow.push({ type: 'filler' });
    priceRow.push({ type: 'text', text: p.unit || ' ', size: 'xs', color: '#718096', align: 'end', flex: 0 });

    return {
      type: 'box', layout: 'vertical', spacing: 'sm',
      paddingTop: isFirst ? 'none' : 'lg',
      contents: [
        { type: 'image', url: p.image, aspectRatio: '20:13', aspectMode: 'cover', size: 'full' },
        { type: 'box', layout: 'vertical', spacing: 'xs', paddingTop: 'sm', contents: [
          { type: 'text', text: 'SKU ' + (p.sku || '-'), size: 'xxs', color: cfg.color, weight: 'bold' },
          { type: 'text', text: p.name, size: 'sm', weight: 'bold', wrap: true, maxLines: 2, color: '#1A202C' },
        ] },
        { type: 'box', layout: 'horizontal', alignItems: 'center', paddingTop: 'xs', contents: priceRow },
        { type: 'button', style: 'primary', color: cfg.color, height: 'sm', margin: 'sm',
          action: { type: 'uri', label: cfg.copy.ctaLabel, uri: p.url } },
      ],
    };
  }

  function buildBubble(pair, cfg) {
    var contents = [
      { type: 'box', layout: 'horizontal', backgroundColor: cfg.color, paddingAll: 'sm', cornerRadius: 'md',
        contents: [{ type: 'text', text: cfg.copy.badgeText, color: '#FFFFFF', weight: 'bold', size: 'sm', align: 'center' }] },
      buildHalf(pair[0], true, cfg),
    ];
    if (pair.length === 2) {
      contents.push({ type: 'separator', margin: 'lg', color: '#E2E8F0' });
      contents.push(buildHalf(pair[1], false, cfg));
    }
    return {
      type: 'bubble', size: 'mega',
      body: { type: 'box', layout: 'vertical', spacing: 'md', paddingAll: 'lg', contents: contents },
    };
  }

  function build(opts) {
    opts = opts || {};
    var themeKey = TYPE_TO_THEME[opts.theme] || opts.theme || 'promotion';
    var t = resolveTheme(themeKey);
    var shopUrl = opts.shopUrl || (typeof location !== 'undefined' ? location.origin : 'https://re-ya.com');

    var copy = opts.copy || {};
    var cfg = {
      color: opts.color || t.color,
      icon: t.icon,
      shopUrl: shopUrl,
      copy: {
        title: copy.title || 'โปรโมชันพิเศษ',
        intro: copy.intro || '',
        ctaLabel: copy.ctaLabel || 'สั่งเลย',
        badgeText: copy.badgeText || t.badge,
        footerText: copy.footerText || 'สนใจตัวไหน แจ้งรหัสทักแชทได้เลย',
      },
      products: [],
    };

    var products = (opts.products || []).map(function (p) { return mapProduct(p, shopUrl); });
    cfg.products = products;

    if (products.length === 0) return null;

    var layout = opts.layout === '1up' ? 1 : 2;

    // Single product, no cover → return a lone bubble for a clean preview.
    if (products.length === 1) {
      return buildBubble([products[0]], cfg);
    }

    var pairs = [];
    for (var i = 0; i < products.length; i += layout) pairs.push(products.slice(i, i + layout));

    var bubbles = [buildCover(cfg)];
    for (var j = 0; j < pairs.length; j++) bubbles.push(buildBubble(pairs[j], cfg));

    // LINE caps a carousel at 12 bubbles; trim and keep the cover.
    if (bubbles.length > MAX_BUBBLES) bubbles = bubbles.slice(0, MAX_BUBBLES);

    return { type: 'carousel', contents: bubbles };
  }

  // Best-effort altText for the broadcast handoff.
  function altText(opts) {
    return (opts && opts.copy && opts.copy.title) || 'โปรโมชันจากร้านยา';
  }

  global.FlexProductBuilder = { build: build, altText: altText, THEME: THEME, TYPE_TO_THEME: TYPE_TO_THEME };
})(typeof window !== 'undefined' ? window : this);
