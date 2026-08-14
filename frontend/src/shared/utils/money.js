const usd = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  minimumFractionDigits: 0,
  maximumFractionDigits: 8,
});

// Formats a USD amount preserving the decimal digits the backend actually
// returns (e.g. budget/remaining rounded to 4, per-user cost to 6, raw
// aggregation floats as-is), up to a sane cap of 8. It never forces an
// arbitrary 2-decimal "cents" rendering that would mask small amounts.
export function formatMoney(value) {
  const n = typeof value === 'number' && Number.isFinite(value) ? value : 0;
  return usd.format(n);
}