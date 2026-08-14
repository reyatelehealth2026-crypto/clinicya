/**
 * shippingDisplay.ts — port of shop/order-detail.php's `delivery_info`
 * parsing + shipping-field fallback block (PHP lines 753-768): the
 * "$shippingName = $order['shipping_name'] ?? $deliveryInfo['name'] ?? ''"
 * chain, `$liffAddress` full-vs-parts assembly, and `$shippingAddress`.
 * Pulled out of page.tsx as a pure function for unit testing.
 */

export interface DeliveryInfoRaw {
  name?: string;
  phone?: string;
  full_address?: string;
  address?: string;
  subdistrict?: string;
  district?: string;
  province?: string;
  postcode?: string;
}

export interface ShippingDisplayOrder {
  shippingName: string | null;
  shippingPhone: string | null;
  shippingAddress: string | null;
  deliveryInfo: string | null;
}

export interface ShippingDisplay {
  /** Raw LIFF fields (for the read-only info box) — see PHP's `!empty($deliveryInfo['name'])` etc. checks. */
  liffName: string;
  liffPhone: string;
  /** The RESOLVED liffAddress (full_address, else joined parts) — PHP's `$liffAddress` variable. */
  liffAddress: string;
  /** Editable-form pre-fill values (order column, falling back to LIFF). */
  shippingName: string;
  shippingPhone: string;
  shippingAddress: string;
}

/** Mirrors PHP's `empty($x)` for a possibly-undefined string: true for undefined/null/''/'0'. */
function phpEmptyStr(value: string | null | undefined): boolean {
  return value === undefined || value === null || value === '' || value === '0';
}

/** Port of `json_decode($order['delivery_info'] ?? '{}', true)` with the same null-on-failure degrade PHP gets from a bad payload. */
export function parseDeliveryInfoJson(raw: string | null): DeliveryInfoRaw {
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as DeliveryInfoRaw) : {};
  } catch {
    return {};
  }
}

/** Port of PHP lines 753-768. */
export function computeShippingDisplay(order: ShippingDisplayOrder): ShippingDisplay {
  const di = parseDeliveryInfoJson(order.deliveryInfo);

  const shippingName = order.shippingName ?? di.name ?? '';
  const shippingPhone = order.shippingPhone ?? di.phone ?? '';

  let liffAddress = di.full_address ?? '';
  if (phpEmptyStr(liffAddress)) {
    liffAddress = [di.address, di.subdistrict, di.district, di.province, di.postcode]
      .filter((part) => !phpEmptyStr(part))
      .join(' ')
      .trim();
  }

  const shippingAddress = order.shippingAddress ?? liffAddress;

  return {
    liffName: di.name ?? '',
    liffPhone: di.phone ?? '',
    liffAddress,
    shippingName,
    shippingPhone,
    shippingAddress,
  };
}
