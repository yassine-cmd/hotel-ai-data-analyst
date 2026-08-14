// ============================================================
// Brand — single source of truth for product identity.
// Change the brand name/palette here; it propagates everywhere
// (client top bar, admin sidebar, auth pages, app title).
// ============================================================

export const BRAND = {
  name: 'FMS Analytics',
  short: 'FMS',
  tagline: 'Analytics hôtelière, propulsée par l’IA',
  // Used as the mark letter on logo avatars (e.g. "F").
  monogram: 'F',
};

// Chart palette — single source of truth used by ChartRenderer and the admin
// dashboard. Recharts requires literal hex values (CSS vars do not resolve in
// SVG presentation attributes).
export const CHART_PALETTE = ['#4680ff', '#3fcc7e', '#4abad2', '#6673fc', '#e44f56'];

// Neutral surface ramp used by recharts tooltips/grids (light theme).
export const CHART_COLORS = {
  grid: 'rgba(148, 163, 184, 0.12)',
  tick: '#64748b',
  axis: '#e3e7ee',
  line: '#e3e7ee',
  tooltipBg: '#ffffff',
  tooltipBorder: '#cfd5de',
  tooltipText: '#30364d',
};
