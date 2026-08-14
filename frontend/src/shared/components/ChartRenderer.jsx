import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toPng } from 'html-to-image';
import { Download, FileSpreadsheet, Maximize2, RotateCcw } from 'lucide-react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Line, LineChart, Pie, PieChart, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis } from 'recharts';
import { CHART_COLORS, CHART_PALETTE } from '../brand';
import Modal from './ui/Modal';

const TOOLTIP = {
  background: CHART_COLORS.tooltipBg,
  border: `1px solid ${CHART_COLORS.tooltipBorder}`,
  color: CHART_COLORS.tooltipText,
  borderRadius: 8,
  fontSize: 12,
};
const TOOLTIP_CURSOR = { fill: 'rgba(48, 54, 77, 0.06)' };
const CHART_TYPES = ['bar', 'line', 'area', 'scatter', 'pie', 'donut', 'histogram', 'box'];

const seriesColor = (i) => CHART_PALETTE[i % CHART_PALETTE.length];

const numFmt = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
const fmtValue = (v) => (typeof v === 'number' && Number.isFinite(v) ? numFmt.format(v) : v);

const shortDate = (v) => {
  const s = String(v).trim();
  // Handle YYYY-MM format (common for monthly data) → "MMM YYYY"
  if (/^\d{4}-\d{2}$/.test(s)) {
    const [y, m] = s.split('-');
    const d = new Date(Number(y), Number(m) - 1, 1);
    if (!Number.isNaN(d.getTime())) {
      return d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    }
  }
  const d = new Date(s);
  if (Number.isNaN(d.getTime())) return s;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const slugify = (s) => (s || 'chart').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'chart';

function yColumns(spec) {
  const y = spec?.y;
  return Array.isArray(y) ? y : y != null ? [y] : [];
}

function downloadBlob(content, mime, filename) {
  const blob = new Blob([content], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function csvFromData(spec) {
  const xField = spec.x || 'label';
  const yCols = yColumns(spec);
  const groupCol = spec.group_by;
  const cols = [xField, ...yCols, ...(groupCol ? [groupCol] : [])].filter((c) => c && c in (spec.data[0] || {}));
  const esc = (v) => {
    const s = v == null ? '' : String(v);
    return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const header = cols.map((c) => esc(c)).join(',');
  const lines = (spec.data || []).map((r) => cols.map((c) => esc(r[c])).join(','));
  return [header, ...lines].join('\n');
}

export function mapPointerToDomain({ x0, y0, x1, y1, rect, xMode, xDomain, xCount, yDomain, minBox = 4 }) {
  if (!rect || rect.width <= 0 || rect.height <= 0) return null;
  const sx = Math.max(Math.min(x0, x1), rect.left);
  const ex = Math.min(Math.max(x0, x1), rect.left + rect.width);
  const sy = Math.max(Math.min(y0, y1), rect.top);
  const ey = Math.min(Math.max(y0, y1), rect.top + rect.height);
  if (ex - sx < minBox || ey - sy < minBox) return null;
  const fx0 = (sx - rect.left) / rect.width;
  const fx1 = (ex - rect.left) / rect.width;
  const fy0 = 1 - (ey - rect.top) / rect.height;
  const fy1 = 1 - (sy - rect.top) / rect.height;
  let x;
  if (xMode === 'category') {
    const n = Math.max(1, xCount || 0);
    const a = Math.min(n - 1, Math.max(0, Math.floor(fx0 * n)));
    const b = Math.min(n - 1, Math.max(a + 1, Math.floor(fx1 * n) - (fx1 >= 1 ? 1 : 0)));
    x = [a, b];
  } else {
    const [lo, hi] = xDomain || [0, 1];
    const span = (hi - lo) || 1;
    x = [lo + fx0 * span, lo + fx1 * span];
  }
  const [ylo, yhi] = yDomain || [0, 1];
  const yspan = (yhi - ylo) || 1;
  const y = [ylo + fy0 * yspan, ylo + fy1 * yspan];
  return { x, y };
}

function measurePlotRect(node) {
  const canvas = node?.getBoundingClientRect?.();
  const gridEl = node?.querySelector?.('.recharts-cartesian-grid');
  const grid = gridEl ? gridEl.getBoundingClientRect() : null;
  const toObj = (r) => (r && (r.width || r.height))
    ? { left: r.left, top: r.top, width: r.width, height: r.height }
    : null;
  return { canvas: toObj(canvas), grid: toObj(grid) };
}

function PlotCanvas({ zoomable, hasZoom, onZoom, onReset, children }) {
  const ref = useRef(null);
  const [rect, setRect] = useState(null);
  const [drag, setDrag] = useState(null);

  useEffect(() => {
    if (!zoomable) { setRect(null); setDrag(null); return; }
    const node = ref.current;
    if (!node) return;
    const measure = () => setRect(measurePlotRect(node));
    measure();
    if (typeof ResizeObserver !== 'undefined') {
      const ro = new ResizeObserver(measure);
      ro.observe(node);
      return () => ro.disconnect();
    }
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, [zoomable]);

  const start = (e) => {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    e.preventDefault();
    try { e.currentTarget.setPointerCapture?.(e.pointerId); } catch { /* pointer capture unavailable */ }
    setDrag({ x0: e.clientX, y0: e.clientY, x1: e.clientX, y1: e.clientY });
  };
  const move = (e) => { if (drag) setDrag({ ...drag, x1: e.clientX, y1: e.clientY }); };
  const end = (e) => {
    if (!drag) return;
    const box = { x0: drag.x0, y0: drag.y0, x1: e.clientX, y1: e.clientY, rect };
    setDrag(null);
    onZoom(box);
  };

  const box = drag && rect?.canvas ? {
    left: Math.min(drag.x0, drag.x1) - rect.canvas.left,
    top: Math.min(drag.y0, drag.y1) - rect.canvas.top,
    width: Math.abs(drag.x1 - drag.x0),
    height: Math.abs(drag.y1 - drag.y0),
  } : null;

  return (
    <div
      ref={ref}
      className="chart-plot-canvas relative"
      style={zoomable ? { cursor: hasZoom ? 'grab' : 'crosshair' } : undefined}
      onPointerDown={zoomable ? start : undefined}
      onPointerMove={zoomable && drag ? move : undefined}
      onPointerUp={zoomable && drag ? end : undefined}
      onPointerCancel={zoomable ? () => setDrag(null) : undefined}
      onDoubleClick={zoomable && hasZoom ? () => onReset() : undefined}
    >
      {children}
      {box && <div className="chart-zoom-box" aria-hidden="true" style={{ left: box.left, top: box.top, width: box.width, height: box.height }} />}
    </div>
  );
}

function BoxPlot({ spec }) {
  const xField = spec.x || 'label';
  const yCols = yColumns(spec);
  const groupCol = spec.group_by;
  const yKey = groupCol ? yCols[0] : null;

  const seriesDefs = useMemo(() => {
    if (groupCol) {
      return Array.from(new Set((spec.data || []).map((r) => r[groupCol])))
        .filter((g) => g != null)
        .map((g) => ({ key: String(g) }));
    }
    return yCols.map((col) => ({ key: col }));
  }, [spec, groupCol, yCols]);

  const cats = useMemo(() => {
    const unique = Array.from(new Set((spec.data || []).map((r) => r[xField]))).filter((v) => v != null);
    const numeric = spec.x_type === 'numeric';
    return unique.sort((a, b) => (numeric ? Number(a) - Number(b) : String(a).localeCompare(String(b))));
  }, [spec, xField]);

  const statsBySeries = useMemo(() => {
    const quantile = (sorted, p) => {
      if (!sorted.length) return null;
      const pos = (sorted.length - 1) * p;
      const base = Math.floor(pos);
      const rest = pos - base;
      return base + 1 < sorted.length ? sorted[base] + rest * (sorted[base + 1] - sorted[base]) : sorted[base];
    };
    const out = {};
    for (const s of seriesDefs) {
      out[s.key] = {};
      for (const c of cats) {
        const vals = (spec.data || [])
          .filter((r) => r[xField] === c && (groupCol ? String(r[groupCol]) === s.key : true))
          .map((r) => Number(r[groupCol ? yKey : s.key]))
          .filter(Number.isFinite)
          .sort((a, b) => a - b);
        if (!vals.length) {
          out[s.key][c] = null;
          continue;
        }
        const q1 = quantile(vals, 0.25);
        const q3 = quantile(vals, 0.75);
        const iqr = q3 - q1;
        out[s.key][c] = {
          min: vals[0],
          q1,
          median: quantile(vals, 0.5),
          q3,
          max: vals[vals.length - 1],
          outliers: vals.filter((v) => v < q1 - 1.5 * iqr || v > q3 + 1.5 * iqr),
        };
      }
    }
    return out;
  }, [spec, cats, seriesDefs, xField, groupCol, yKey]);

  const W = 640;
  const H = 260;
  const PAD = { top: 12, right: 12, bottom: 34, left: 46 };
  const plotW = W - PAD.left - PAD.right;
  const plotH = H - PAD.top - PAD.bottom;

  let vmin = Infinity;
  let vmax = -Infinity;
  for (const s of seriesDefs) {
    for (const c of cats) {
      const st = statsBySeries[s.key]?.[c];
      if (!st) continue;
      vmin = Math.min(vmin, st.min, ...st.outliers);
      vmax = Math.max(vmax, st.max, ...st.outliers);
    }
  }
  if (!Number.isFinite(vmin) || !Number.isFinite(vmax)) vmin = 0;
  if (vmin === vmax) { vmin -= 1; vmax += 1; }
  const span = vmax - vmin || 1;
  const pad = span * 0.05;
  vmin -= pad;
  vmax += pad;

  const yFor = (v) => PAD.top + plotH - ((v - vmin) / (vmax - vmin)) * plotH;
  const band = plotW / Math.max(1, cats.length);
  const groupW = Math.min(band * 0.55, 72) / Math.max(1, seriesDefs.length);

  const ticks = [];
  for (let i = 0; i <= 4; i += 1) {
    const v = vmin + (i / 4) * (vmax - vmin);
    ticks.push({ v, y: yFor(v) });
  }

  return (
    <svg width="100%" height={H} viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="xMidYMid meet" className="chart-box-svg" role="img" aria-label="Diagramme en boîte">
      {ticks.map((t) => (
        <g key={t.v}>
          <line x1={PAD.left} x2={W - PAD.right} y1={t.y} y2={t.y} stroke="var(--border)" strokeDasharray="3 3" />
          <text x={PAD.left - 6} y={t.y + 3.5} textAnchor="end" fontSize="10" fill="var(--text-muted)">{fmtValue(t.v)}</text>
        </g>
      ))}
      {cats.map((c, i) => (
        <text key={c} x={PAD.left + band * i + band / 2} y={H - 12} textAnchor="middle" fontSize="10" fill="var(--text-muted)" style={{ maxWidth: band }}>
          {String(c).length > 12 ? `${String(c).slice(0, 12)}\u2026` : String(c)}
        </text>
      ))}
      {seriesDefs.map((s, si) => {
        const color = seriesColor(si);
        return cats.map((c, i) => {
          const st = statsBySeries[s.key]?.[c];
          if (!st) return null;
          const cx = PAD.left + band * i + band / 2 + (si - (seriesDefs.length - 1) / 2) * groupW;
          const x0 = cx - groupW / 2;
          const [yMin, yQ1, yMed, yQ3, yMax] = [st.min, st.q1, st.median, st.q3, st.max].map(yFor);
          const iqr = st.q3 - st.q1 || 1;
          const whiskerLow = st.q1 - 1.5 * iqr;
          const whiskerHigh = st.q3 + 1.5 * iqr;
          const capLow = yFor(Math.max(whiskerLow, st.min));
          const capHigh = yFor(Math.min(whiskerHigh, st.max));
          return (
            <g key={`${s.key}-${c}`}>
              <title>{`${s.key} \u00b7 ${c}\nmin ${fmtValue(st.min)} \u00b7 q1 ${fmtValue(st.q1)} \u00b7 med ${fmtValue(st.median)} \u00b7 q3 ${fmtValue(st.q3)} \u00b7 max ${fmtValue(st.max)}`}</title>
              <line x1={cx} x2={cx} y1={capLow} y2={capHigh} stroke={color} strokeWidth={1.5} />
              <line x1={x0} x2={x0 + groupW} y1={capLow} y2={capLow} stroke={color} strokeWidth={1.5} />
              <line x1={x0} x2={x0 + groupW} y1={capHigh} y2={capHigh} stroke={color} strokeWidth={1.5} />
              <rect x={x0} y={yQ1} width={groupW} height={Math.max(1, yQ3 - yQ1)} fill={color} fillOpacity={0.25} stroke={color} strokeWidth={1.5} rx={1} />
              <line x1={x0} x2={x0 + groupW} y1={yMed} y2={yMed} stroke={color} strokeWidth={2} />
              {st.outliers.map((o, oi) => (
                <circle key={oi} cx={cx + (oi % 2 === 0 ? -3 : 3)} cy={yFor(o)} r={2} fill={color} />
              ))}
            </g>
          );
        });
      })}
      {seriesDefs.length > 1 && (
        <g className="chart-box-legend">
          {seriesDefs.map((s, si) => (
            <g key={s.key} transform={`translate(${W - PAD.right - seriesDefs.length * 64 + si * 64}, ${PAD.top - 2})`}>
              <rect x={0} y={-7} width={8} height={8} rx={1} fill={seriesColor(si)} fillOpacity={0.4} stroke={seriesColor(si)} strokeWidth={1.5} />
              <text x={12} y={0} fontSize="10" fill="var(--text-muted)">{s.key}</text>
            </g>
          ))}
        </g>
      )}
    </svg>
  );
}

function buildAxes(xDataKey, xIsNumeric, xIsTemporal, xLabel, yLabel, xDomain, yDomain) {
  const allowOverflow = !!(xDomain || yDomain);
  const tick = { fill: CHART_COLORS.tick, fontSize: 11 };
  const axisLine = { stroke: CHART_COLORS.axis };
  return [
    <CartesianGrid key="grid" strokeDasharray="3 3" stroke={CHART_COLORS.grid} />,
    <XAxis
      key="x"
      dataKey={xDataKey}
      type={xIsNumeric ? 'number' : 'category'}
      allowDuplicatedCategory={!xIsNumeric}
      domain={xDomain}
      allowDataOverflow={allowOverflow}
      tick={tick}
      tickFormatter={xIsNumeric ? fmtValue : xIsTemporal ? shortDate : undefined}
      axisLine={axisLine}
      label={xLabel ? { value: xLabel, position: 'insideBottom', offset: -2, fill: CHART_COLORS.tick, fontSize: 11 } : undefined}
    />,
    <YAxis
      key="y"
      domain={yDomain}
      allowDataOverflow={allowOverflow}
      tick={tick}
      tickFormatter={fmtValue}
      axisLine={axisLine}
    />,
    <Tooltip key="tip" contentStyle={TOOLTIP} cursor={TOOLTIP_CURSOR} formatter={(value, name) => [fmtValue(value), name]} />,
  ];
}

function YAxisTitle({ label }) {
  if (!label) return null;
  return (
    <span className="chart-y-title" aria-hidden="true">{label}</span>
  );
}

export default function ChartRenderer({ spec, imageUrl }) {
  const plotRef = useRef(null);
  const [hidden, setHidden] = useState(() => new Set());
  const [expanded, setExpanded] = useState(false);
  const [zoom, setZoom] = useState(null);

  const data = spec?.data || null;
  const type = spec?.chart_type || 'bar';
  const xField = spec?.x || 'label';
  const yCols = yColumns(spec);
  const isPie = type === 'pie' || type === 'donut';
  const xIsNumeric = type === 'scatter' || spec?.x_type === 'numeric';
  const xIsTemporal = spec?.x_type === 'temporal';

  const prepared = useMemo(() => {
    if (!data) return { rows: [], series: [] };
    const yLabels = spec.y_labels || {};
    const seriesLabel = (col, g) => (g ? `${yLabels[col] || col} · ${g}` : yLabels[col] || col);

    if (type === 'scatter') {
      const build = (rows, col) => ({
        points: rows.map((r) => {
          const x = Number(r[xField]);
          const y = Number(r[col]);
          return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
        }).filter(Boolean),
      });
      const series = [];
      if (spec.group_by) {
        const groups = Array.from(new Set(data.map((r) => r[spec.group_by])));
        groups.forEach((g) => {
          const subset = data.filter((r) => r[spec.group_by] === g);
          if (yCols.length === 1) {
            const { points } = build(subset, yCols[0]);
            series.push({ key: String(g), label: String(g), points });
          } else {
            yCols.forEach((col) => {
              const { points } = build(subset, col);
              series.push({ key: `${g}\u2063${col}`, label: seriesLabel(col, g), points });
            });
          }
        });
      } else {
        yCols.forEach((col) => {
          const { points } = build(data, col);
          series.push({ key: col, label: seriesLabel(col, null), points });
        });
      }
      return { rows: data, series };
    }

    if (spec.group_by && !isPie) {
      const uniqueX = Array.from(new Set(data.map((r) => r[xField]))).sort((a, b) => (xIsNumeric ? Number(a) - Number(b) : String(a).localeCompare(String(b))));
      const uniqueGroups = Array.from(new Set(data.map((r) => r[spec.group_by])));
      const none = (v) => v === null || v === undefined;
      const multiY = yCols.length > 1;
      const lookup = new Map(data.map((r) => [`${String(r[xField])}\u2063${String(r[spec.group_by])}`, r]));
      const series = multiY
        ? uniqueGroups.flatMap((g) => yCols.map((col) => ({ key: `${g}\u2063${col}`, label: seriesLabel(col, g) })))
        : uniqueGroups.map((g) => ({ key: String(g), label: String(g) }));
      const rows = uniqueX.map((xVal) => {
        const row = { [xField]: xIsNumeric ? Number(xVal) : xVal };
        uniqueGroups.forEach((g) => {
          const rec = lookup.get(`${String(xVal)}\u2063${String(g)}`);
          if (multiY) {
            yCols.forEach((col) => {
              const v = rec == null ? null : rec[col];
              row[`${g}\u2063${col}`] = none(v) ? null : Number(v);
            });
          } else {
            const v = rec == null ? null : rec[yCols[0]];
            row[String(g)] = none(v) ? null : Number(v);
          }
        });
        return row;
      });
      return { rows, series };
    }

    if (type === 'histogram') {
      return { rows: data, series: [{ key: 'count', label: 'count' }] };
    }

    return { rows: data, series: yCols.map((col) => ({ key: col, label: seriesLabel(col, null) })) };
  }, [type, spec, data, xField, yCols, xIsNumeric, isPie]);

  const rows = prepared?.rows ?? [];
  const series = prepared?.series ?? [];
  const xMode = xIsNumeric ? 'numeric' : 'category';

  const displayRows = zoom && xMode === 'category' ? rows.slice(zoom.x[0], zoom.x[1] + 1) : rows;
  const xAxisDomain = zoom && xMode === 'numeric' ? zoom.x : undefined;
  const yAxisDomain = zoom ? zoom.y : undefined;

  const xDataDomain = useMemo(() => {
    if (xMode !== 'numeric') return [0, 1];
    let lo = Infinity, hi = -Infinity;
    for (const r of rows) { const v = Number(r[xField]); if (Number.isFinite(v)) { lo = Math.min(lo, v); hi = Math.max(hi, v); } }
    if (!Number.isFinite(lo)) return [0, 1];
    if (lo === hi) { lo -= 1; hi += 1; }
    return [lo, hi];
  }, [xMode, rows, xField]);

  const yDataDomain = useMemo(() => {
    let lo = Infinity, hi = -Infinity;
    if (type === 'scatter') {
      for (const s of series) {
        for (const p of s.points || []) {
          if (Number.isFinite(p.y)) { lo = Math.min(lo, p.y); hi = Math.max(hi, p.y); }
        }
      }
    } else {
      for (const r of rows) { for (const s of series) { const v = Number(r[s.key]); if (Number.isFinite(v)) { lo = Math.min(lo, v); hi = Math.max(hi, v); } } }
    }
    if (!Number.isFinite(lo)) return [0, 1];
    if (lo === hi) { lo -= 1; hi += 1; }
    return [lo, hi];
  }, [type, rows, series]);

  const onZoom = useCallback(({ x0, y0, x1, y1, rect }) => {
    if (!rect?.grid) return;
    const currentXDomain = xMode === 'numeric' ? (zoom ? zoom.x : xDataDomain) : null;
    const currentYDomain = zoom ? zoom.y : yDataDomain;
    const range = mapPointerToDomain({ x0, y0, x1, y1, rect: rect.grid, xMode, xDomain: currentXDomain, xCount: displayRows.length, yDomain: currentYDomain });
    if (!range) return;
    if (xMode === 'category') {
      const base = zoom ? zoom.x[0] : 0;
      setZoom({ x: [base + range.x[0], base + range.x[1]], y: range.y });
    } else {
      setZoom(range);
    }
  }, [xMode, zoom, xDataDomain, yDataDomain, displayRows.length]);

  const onReset = useCallback(() => setZoom(null), []);

  if (imageUrl) return <div className="chart-card"><img src={imageUrl} alt="Visualisation générée" className="chart-image" loading="lazy" onError={(e) => { e.target.style.display = 'none'; e.target.parentElement.innerHTML = '<p class=\'chart-error\'>Échec du chargement de l\'image</p>'; }} /></div>;

  if (!spec || !data) return null;

  const xLabel = spec.x_label || xField;
  const yLabel = spec.y_label || (type === 'histogram' ? 'count' : undefined);
  const stacked = !!spec.stacked;
  const title = spec.title || '';
  const decimated = spec.decimated;
  const trueRowCount = spec.true_row_count;
  const showLegend = series.length > 1 && !isPie;
  const xDataKey = type === 'scatter' ? 'x' : xField;
  const zoomable = !isPie && type !== 'box' && rows.length >= 2;
  const scatterEmpty = type === 'scatter' && rows.length > 0 && series.every((s) => !(s.points || []).length);

  const toggleSeries = (key) => {
    setHidden((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const exportPng = async () => {
    const node = plotRef.current;
    if (!node) return;
    try {
      const dataUrl = await toPng(node, { pixelRatio: 2, cacheBust: true, skipFonts: true });
      const a = document.createElement('a');
      a.href = dataUrl;
      a.download = `${slugify(title || spec.chart_id)}.png`;
      a.click();
    } catch {
      // Swallow: PNG export is best-effort and the button stays usable.
    }
  };

  const exportCsv = () => {
    if (!data.length) return;
    downloadBlob(csvFromData(spec), 'text/csv;charset=utf-8', `${slugify(title || spec.chart_id)}.csv`);
  };

  if (!CHART_TYPES.includes(type)) {
    return <div className="chart-card"><p className="chart-unsupported">Type de graphique non pris en charge : {type}</p></div>;
  }

  if (Array.isArray(data) && data.length === 0) {
    return <div className="chart-card"><p className="chart-error">Aucune donnée disponible pour ce graphique</p></div>;
  }

  const warnings = spec.meta?.warnings || [];
  const partialNote = spec.meta?.truncated || spec.meta?.limited || (spec.meta?.category_rollup || 0) > 0;
  const yLabels = spec.y_labels || {};
  const seriesName = (s) => s?.label || yLabels[s?.key] || s?.key;

  const buildChart = () => {
    if (type === 'box') return <BoxPlot spec={spec} />;
    if (isPie) {
      const donut = type === 'donut';
      return (
        <PieChart margin={{ top: 8, right: 8, bottom: 8, left: 8 }}>
          <Tooltip contentStyle={TOOLTIP} formatter={(value) => [fmtValue(value), '']} />
          <Pie data={rows} dataKey={series[0]?.key} nameKey={xField} innerRadius={donut ? '58%' : undefined} outerRadius="82%" paddingAngle={donut ? 2 : 0} stroke="var(--bg-elevated)" strokeWidth={1}>
            {rows.map((r, i) => <Cell key={`cell-${i}`} fill={seriesColor(i)} />)}
          </Pie>
        </PieChart>
      );
    }
    if (type === 'scatter') {
      return (
        <ScatterChart margin={{ top: 8, right: 8, bottom: 8, left: 0 }}>
          {buildAxes(xDataKey, xIsNumeric, xIsTemporal, xLabel, yLabel, xAxisDomain, yAxisDomain)}
          {series.map((s, i) => <Scatter key={s.key} name={seriesName(s)} data={s.points} fill={seriesColor(i)} hide={hidden.has(s.key)} />)}
        </ScatterChart>
      );
    }
    if (type === 'area') {
      return (
        <AreaChart data={displayRows} margin={{ top: 8, right: 8, bottom: 8, left: 0 }}>
          {buildAxes(xDataKey, xIsNumeric, xIsTemporal, xLabel, yLabel, xAxisDomain, yAxisDomain)}
          {series.map((s, i) => <Area key={s.key} name={seriesName(s)} type="monotone" dataKey={s.key} stackId={stacked ? 'stack' : undefined} stroke={seriesColor(i)} fill={seriesColor(i)} fillOpacity={0.25} strokeWidth={2} hide={hidden.has(s.key)} />)}
        </AreaChart>
      );
    }
    if (type === 'line') {
      return (
        <LineChart data={displayRows} margin={{ top: 8, right: 8, bottom: 8, left: 0 }}>
          {buildAxes(xDataKey, xIsNumeric, xIsTemporal, xLabel, yLabel, xAxisDomain, yAxisDomain)}
          {series.map((s, i) => <Line key={s.key} name={seriesName(s)} type="monotone" dataKey={s.key} stroke={seriesColor(i)} strokeWidth={2} dot={false} activeDot={{ r: 4 }} hide={hidden.has(s.key)} />)}
        </LineChart>
      );
    }
    return (
      <BarChart data={displayRows} margin={{ top: 8, right: 8, bottom: 8, left: 0 }}>
        {buildAxes(xDataKey, xIsNumeric, xIsTemporal, xLabel, yLabel, xAxisDomain, yAxisDomain)}
        {series.map((s, i) => {
          return <Bar key={s.key} name={seriesName(s)} dataKey={s.key} stackId={stacked ? 'stack' : undefined} fill={seriesColor(i)} radius={stacked ? undefined : [4, 4, 0, 0]} hide={hidden.has(s.key)} />;
        })}
      </BarChart>
    );
  };

  return (
    <div className="chart-card">
      <div className="chart-header">
        {title ? <h3 className="chart-title">{title}</h3> : <span className="chart-title-spacer" />}
        <div className="chart-toolbar">
          <button type="button" className="chart-tool" aria-label="Exporter le graphique en PNG" title="Exporter le graphique en PNG" onClick={exportPng}>
            <Download className="h-3.5 w-3.5" />
          </button>
          <button type="button" className="chart-tool" aria-label="Exporter les données du graphique en CSV" title="Exporter les données du graphique en CSV" onClick={exportCsv}>
            <FileSpreadsheet className="h-3.5 w-3.5" />
          </button>
          <button type="button" className="chart-tool" aria-label="Agrandir le graphique" title="Agrandir le graphique" onClick={() => setExpanded(true)}>
            <Maximize2 className="h-3.5 w-3.5" />
          </button>
          {zoom && (
            <button type="button" className="chart-tool chart-tool-accent" aria-label="Réinitialiser le zoom" title="Réinitialiser le zoom" onClick={onReset}>
              <RotateCcw className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
      </div>
      {decimated && <p className="chart-decimated">Affichage de {rows.length} points de données sur {trueRowCount}</p>}
      {partialNote && <p className="chart-note">Basé sur des données partielles ou agrégées</p>}
      {scatterEmpty && <p className="chart-note">Aucun point numérique traçable pour le nuage de points</p>}
      <div className="chart-plot" ref={plotRef} role="img" aria-label={title ? `${title} chart` : 'Visualisation du graphique'}>
        <div className="chart-plot-inner">
          {yLabel && <YAxisTitle label={yLabel} />}
          <PlotCanvas zoomable={zoomable} hasZoom={!!zoom} onZoom={onZoom} onReset={onReset}>
            <ResponsiveContainer width="100%" height={260}>
              {buildChart()}
            </ResponsiveContainer>
          </PlotCanvas>
        </div>
        {showLegend && (
          <div className="chart-legend">
            {series.map((s, i) => {
              const key = s.key;
              const isHidden = hidden.has(key);
              return (
                <button key={key} type="button" className={`chart-legend-item${isHidden ? ' chart-legend-item-hidden' : ''}`} onClick={() => toggleSeries(key)}>
                  <span className="chart-legend-swatch" style={{ background: seriesColor(i) }} />
                  <span className="chart-legend-label">{seriesName(s)}</span>
                </button>
              );
            })}
          </div>
        )}
      </div>
      {warnings.length > 0 && (
        <ul className="chart-warnings">
          {warnings.map((w, wi) => <li key={wi}>{w}</li>)}
        </ul>
      )}
      <Modal open={expanded} onClose={() => setExpanded(false)} title={title || 'Graphique'} size="xl">
        <div className="chart-modal-plot">
          {yLabel && <YAxisTitle label={yLabel} />}
          <PlotCanvas zoomable={zoomable} hasZoom={!!zoom} onZoom={onZoom} onReset={onReset}>
            {type === 'box' ? <BoxPlot spec={spec} /> : <ResponsiveContainer width="100%" height={480}>{buildChart()}</ResponsiveContainer>}
          </PlotCanvas>
        </div>
      </Modal>
    </div>
  );
}
