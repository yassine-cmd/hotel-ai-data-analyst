// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChartRenderer, { mapPointerToDomain } from './ChartRenderer';

if (typeof window !== 'undefined' && !window.PointerEvent) {
  window.PointerEvent = class PointerEventPolyfill extends MouseEvent {
    constructor(type, init = {}) {
      super(type, init);
      this.pointerId = init.pointerId ?? 1;
      this.pointerType = init.pointerType ?? '';
    }
  };
}

vi.mock('html-to-image', () => ({
  toPng: vi.fn(() => Promise.resolve('data:image/png;base64,xx')),
}));

vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }) => <div className="mock-container">{children}</div>,
  BarChart: ({ children }) => <div className="mock-chart mock-bar-chart">{children}</div>,
  LineChart: ({ children }) => <div className="mock-chart mock-line-chart">{children}</div>,
  AreaChart: ({ children }) => <div className="mock-chart mock-area-chart">{children}</div>,
  PieChart: ({ children }) => <div className="mock-chart mock-pie-chart">{children}</div>,
  ScatterChart: ({ children }) => <div className="mock-chart mock-scatter-chart">{children}</div>,
  Bar: (props) => <div className="mock-bar" data-stack-id={props.stackId || ''} data-hide={props.hide ? 'true' : ''} />,
  Line: (props) => <div className="mock-line" data-name={props.name || ''} data-hide={props.hide ? 'true' : ''} />,
  Area: (props) => <div className="mock-area" data-stack-id={props.stackId || ''} data-hide={props.hide ? 'true' : ''} />,
  Pie: (props) => <div className="mock-pie" data-key={props.dataKey || ''} data-inner={props.innerRadius || ''}>{props.children}</div>,
  Cell: () => <div className="mock-cell" />,
  Scatter: (props) => <div className="mock-scatter" data-name={props.name || ''} data-points={String(props.data?.length || 0)} data-hide={props.hide ? 'true' : ''} />,
  Legend: (props) => <button className="mock-legend" onClick={() => props.onClick?.({ dataKey: 'a' })} />,
  XAxis: (props) => <div className="mock-xaxis" data-domain={props.domain ? JSON.stringify(props.domain) : ''} data-overflow={props.allowDataOverflow ? 'true' : ''} />,
  YAxis: (props) => <div className="mock-yaxis" data-domain={props.domain ? JSON.stringify(props.domain) : ''} data-overflow={props.allowDataOverflow ? 'true' : ''} />,
  CartesianGrid: () => <div className="recharts-cartesian-grid" />,
  Tooltip: () => null,
}));

const spec = { chart_type: 'bar', x: 'label', y: ['value'], data: [{ label: 'A', value: 10 }] };

afterEach(cleanup);

describe('ChartRenderer', () => {
  it('renders nothing without a spec or data', () => {
    const { container } = render(<ChartRenderer />);
    expect(container).toBeEmptyDOMElement();
  });

  it('renders a bar chart from a backend-shaped spec', () => {
    render(<ChartRenderer spec={spec} sessionId="s1" />);
    expect(document.querySelector('.mock-bar-chart')).toBeInTheDocument();
    expect(document.querySelector('.chart-card')).toBeInTheDocument();
    expect(document.querySelectorAll('.mock-bar')).toHaveLength(1);
  });

  it('renders one series per y column for multi-y charts', () => {
    render(<ChartRenderer spec={{ ...spec, chart_type: 'line', y: ['a', 'b'], data: [{ label: 'A', a: 1, b: 2 }] }} sessionId="s1" />);
    expect(document.querySelector('.mock-line-chart')).toBeInTheDocument();
    expect(document.querySelectorAll('.mock-line')).toHaveLength(2);
  });

  it('pivots group_by into one series per group', () => {
    render(<ChartRenderer spec={{ chart_type: 'bar', x: 'month', y: ['cnt'], group_by: 'channel', data: [
      { month: 'Jan', channel: 'web', cnt: 3 },
      { month: 'Jan', channel: 'app', cnt: 4 },
      { month: 'Feb', channel: 'web', cnt: 1 },
      { month: 'Feb', channel: 'app', cnt: 2 },
    ] }} sessionId="s1" />);
    expect(document.querySelectorAll('.mock-bar')).toHaveLength(2);
  });

  it('renders one series per group and y column for group_by multi-y charts', () => {
    render(<ChartRenderer spec={{ chart_type: 'bar', x: 'month', y: ['nb', 'rev'], group_by: 'channel', data: [
      { month: 'Jan', channel: 'web', nb: 3, rev: 30 },
      { month: 'Jan', channel: 'app', nb: 4, rev: 40 },
      { month: 'Feb', channel: 'web', nb: 1, rev: 10 },
      { month: 'Feb', channel: 'app', nb: 2, rev: 20 },
    ] }} sessionId="s1" />);
    expect(document.querySelectorAll('.mock-bar')).toHaveLength(4);
    const labels = Array.from(document.querySelectorAll('.chart-legend-label')).map((el) => el.textContent);
    expect(labels).toEqual(['nb · web', 'rev · web', 'nb · app', 'rev · app']);
  });

  it('renders one scatter series per y column with its own points', () => {
    render(<ChartRenderer spec={{ chart_type: 'scatter', x: 'height', y: ['a', 'b'], data: [
      { height: 170, a: 70, b: 71 },
      { height: 180, a: 80, b: 81 },
    ] }} sessionId="s1" />);
    const scatters = Array.from(document.querySelectorAll('.mock-scatter'));
    expect(scatters).toHaveLength(2);
    expect(scatters.every((el) => el.getAttribute('data-points') === '2')).toBe(true);
  });

  it('renders one scatter series per group', () => {
    render(<ChartRenderer spec={{ chart_type: 'scatter', x: 'height', y: ['w'], group_by: 'sex', data: [
      { height: 170, w: 70, sex: 'M' },
      { height: 165, w: 60, sex: 'F' },
      { height: 180, w: 80, sex: 'M' },
    ] }} sessionId="s1" />);
    const scatters = Array.from(document.querySelectorAll('.mock-scatter'));
    expect(scatters).toHaveLength(2);
    expect(scatters[0]).toHaveAttribute('data-points', '2');
    expect(scatters[1]).toHaveAttribute('data-points', '1');
  });

  it('shows a note when scatter has no plottable numeric points', () => {
    render(<ChartRenderer spec={{ chart_type: 'scatter', x: 'height', y: ['w'], data: [
      { height: 'tall', w: 'heavy' },
      { height: 'short', w: 'light' },
    ] }} sessionId="s1" />);
    expect(screen.getByText('Aucun point numérique traçable pour le nuage de points')).toBeInTheDocument();
  });

  it('sets stackId on bars when stacked is true', () => {
    render(<ChartRenderer spec={{ ...spec, y: ['a', 'b'], stacked: true, data: [{ label: 'A', a: 1, b: 2 }] }} sessionId="s1" />);
    expect(document.querySelectorAll('.mock-bar[data-stack-id="stack"]')).toHaveLength(2);
  });

  it('renders pie and donut charts', () => {
    const { rerender } = render(<ChartRenderer spec={{ ...spec, chart_type: 'pie' }} sessionId="s1" />);
    expect(document.querySelector('.mock-pie-chart')).toBeInTheDocument();
    expect(document.querySelector('.mock-pie')).toHaveAttribute('data-key', 'value');
    expect(document.querySelectorAll('.mock-cell').length).toBeGreaterThan(0);

    rerender(<ChartRenderer spec={{ ...spec, chart_type: 'donut' }} sessionId="s1" />);
    expect(document.querySelector('.mock-pie')).toHaveAttribute('data-inner', '58%');
  });

  it('renders area and scatter charts', () => {
    const { rerender } = render(<ChartRenderer spec={{ ...spec, chart_type: 'area' }} sessionId="s1" />);
    expect(document.querySelector('.mock-area-chart')).toBeInTheDocument();

    rerender(<ChartRenderer spec={{ chart_type: 'scatter', x: 'height', y: ['weight'], data: [{ height: 170, weight: 70 }] }} sessionId="s1" />);
    expect(document.querySelector('.mock-scatter-chart')).toBeInTheDocument();
    expect(document.querySelector('.mock-scatter')).toHaveAttribute('data-points', '1');
  });

  it('renders histogram as bars', () => {
    render(<ChartRenderer spec={{ chart_type: 'histogram', x: 'age', y: [], data: [{ age: 21 }, { age: 33 }] }} sessionId="s1" />);
    expect(document.querySelector('.mock-bar-chart')).toBeInTheDocument();
    expect(document.querySelectorAll('.mock-bar')).toHaveLength(1);
  });

  it('chart plot has role=img and aria-label with title', () => {
    render(<ChartRenderer spec={{ ...spec, title: 'Sales by quarter' }} sessionId="s1" />);
    const plot = document.querySelector('.chart-plot');
    expect(plot).toHaveAttribute('role', 'img');
    expect(plot).toHaveAttribute('aria-label', 'Sales by quarter chart');
    expect(screen.getByText('Sales by quarter')).toBeInTheDocument();
  });

  it('shows decimation notice when decimated', () => {
    render(<ChartRenderer spec={{ ...spec, decimated: true, true_row_count: 100 }} sessionId="s1" />);
    expect(screen.getByText('Affichage de 1 points de données sur 100')).toBeInTheDocument();
  });

  it('shows partial-data notice when meta flags are set', () => {
    render(<ChartRenderer spec={{ ...spec, meta: { category_rollup: 5 } }} sessionId="s1" />);
    expect(screen.getByText('Basé sur des données partielles ou agrégées')).toBeInTheDocument();
  });

  it('lists backend warnings under the plot', () => {
    render(<ChartRenderer spec={{ ...spec, meta: { warnings: ['Column x: 3 values dropped', 'Too many categories'] } }} sessionId="s1" />);
    expect(screen.getByText('Column x: 3 values dropped')).toBeInTheDocument();
    expect(screen.getByText('Too many categories')).toBeInTheDocument();
  });

  it('shows an empty-data message when the spec has no rows', () => {
    render(<ChartRenderer spec={{ ...spec, data: [] }} sessionId="s1" />);
    expect(screen.getByText('Aucune donnée disponible pour ce graphique')).toBeInTheDocument();
  });

  it('renders a box plot with computed five-number statistics', () => {
    const boxSpec = {
      chart_type: 'box',
      x: 'label',
      y: ['v'],
      data: [
        { label: 'A', v: 1 }, { label: 'A', v: 2 }, { label: 'A', v: 3 }, { label: 'A', v: 4 }, { label: 'A', v: 5 },
        { label: 'B', v: 10 }, { label: 'B', v: 12 }, { label: 'B', v: 14 },
      ],
    };
    render(<ChartRenderer spec={boxSpec} sessionId="s1" />);
    const svg = document.querySelector('.chart-box-svg');
    expect(svg).toBeInTheDocument();
    expect(document.querySelectorAll('.chart-box-svg title').length).toBeGreaterThanOrEqual(2);
    expect(document.querySelector('.chart-box-svg title').textContent).toContain('med 3');
    expect(document.querySelector('.chart-box-svg title').textContent).toContain('min 1');
    expect(document.querySelector('.chart-box-svg title').textContent).toContain('max 5');
  });

  it('unsupported chart_type shows fallback', () => {
    render(<ChartRenderer spec={{ ...spec, chart_type: 'funnel' }} sessionId="s1" />);
    expect(screen.getByText('Type de graphique non pris en charge : funnel')).toBeInTheDocument();
  });

  it('legend click hides a series', () => {
    render(<ChartRenderer spec={{ ...spec, chart_type: 'line', y: ['a', 'b'], data: [{ label: 'A', a: 1, b: 2 }] }} sessionId="s1" />);
    const lines = document.querySelectorAll('.mock-line');
    expect(lines[0]).toHaveAttribute('data-hide', '');
    fireEvent.click(document.querySelector('.chart-legend-item'));
    expect(document.querySelectorAll('.mock-line')[0]).toHaveAttribute('data-hide', 'true');
  });

  it('uses y_labels for the legend and tooltip names', () => {
    render(<ChartRenderer spec={{ ...spec, chart_type: 'line', y: ['a', 'b'], y_labels: { a: 'Arrivées', b: 'Réservations créées' }, data: [{ label: 'A', a: 1, b: 2 }] }} sessionId="s1" />);
    const labels = Array.from(document.querySelectorAll('.chart-legend-label')).map((el) => el.textContent);
    expect(labels).toEqual(['Arrivées', 'Réservations créées']);
    const lines = document.querySelectorAll('.mock-line');
    expect(lines[0]).toHaveAttribute('data-name', 'Arrivées');
    expect(lines[1]).toHaveAttribute('data-name', 'Réservations créées');
  });

  it('falls back to raw column names when y_labels is absent', () => {
    render(<ChartRenderer spec={{ ...spec, chart_type: 'line', y: ['a', 'b'], data: [{ label: 'A', a: 1, b: 2 }] }} sessionId="s1" />);
    const labels = Array.from(document.querySelectorAll('.chart-legend-label')).map((el) => el.textContent);
    expect(labels).toEqual(['a', 'b']);
  });

  it('renders the y-axis title without truncating', () => {
    render(<ChartRenderer spec={{ ...spec, y_label: 'Nombre de réservations' }} sessionId="s1" />);
    const title = document.querySelector('.chart-y-title');
    expect(title).toBeInTheDocument();
    expect(title.textContent).toBe('Nombre de réservations');
  });

  it('omits the y-axis title when no y_label is present', () => {
    render(<ChartRenderer spec={spec} sessionId="s1" />);
    expect(document.querySelector('.chart-y-title')).not.toBeInTheDocument();
  });

  it('expands the chart in a modal', () => {
    render(<ChartRenderer spec={{ ...spec, title: 'Modal test' }} sessionId="s1" />);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Agrandir le graphique' }));
    expect(screen.getByRole('dialog')).toHaveAttribute('aria-label', 'Modal test');
    expect(document.querySelectorAll('.mock-bar-chart')).toHaveLength(2);
  });

  it('exports PNG via html-to-image', async () => {
    const { toPng } = await import('html-to-image');
    render(<ChartRenderer spec={{ ...spec, title: 'Export me' }} sessionId="s1" />);
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    fireEvent.click(screen.getByRole('button', { name: 'Exporter le graphique en PNG' }));
    await vi.waitFor(() => expect(toPng).toHaveBeenCalled());
    expect(toPng).toHaveBeenCalledWith(document.querySelector('.chart-plot'), expect.objectContaining({ pixelRatio: 2 }));
    expect(clickSpy).toHaveBeenCalled();
    clickSpy.mockRestore();
  });

  it('exports chart data as CSV', async () => {
    const createObjectURL = vi.fn(() => 'blob:mock');
    const revokeObjectURL = vi.fn();
    const originalCreate = URL.createObjectURL;
    const originalRevoke = URL.revokeObjectURL;
    URL.createObjectURL = createObjectURL;
    URL.revokeObjectURL = revokeObjectURL;
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    render(<ChartRenderer spec={{ ...spec, title: 'CSV me' }} sessionId="s1" />);
    fireEvent.click(screen.getByRole('button', { name: 'Exporter les données du graphique en CSV' }));
    expect(createObjectURL).toHaveBeenCalledOnce();
    const blob = createObjectURL.mock.calls[0][0];
    const text = await new Promise((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.readAsText(blob);
    });
    expect(text).toBe('label,value\nA,10');
    URL.createObjectURL = originalCreate;
    URL.revokeObjectURL = originalRevoke;
    clickSpy.mockRestore();
  });

  it('renders image when imageUrl prop provided', () => {
    render(<ChartRenderer imageUrl="https://example.com/chart.png" />);
    const img = document.querySelector('img');
    expect(img).toBeInTheDocument();
    expect(img).toHaveAttribute('loading', 'lazy');
    expect(img).toHaveAttribute('alt', 'Visualisation générée');
    expect(img).toHaveAttribute('src', 'https://example.com/chart.png');
  });

  it('keeps stable hook order when toggling image and spec modes', () => {
    const { rerender } = render(<ChartRenderer imageUrl="https://example.com/chart.png" />);
    expect(document.querySelector('img')).toBeInTheDocument();

    rerender(<ChartRenderer spec={spec} />);
    expect(document.querySelector('.mock-bar-chart')).toBeInTheDocument();
    expect(document.querySelector('img')).not.toBeInTheDocument();

    rerender(<ChartRenderer imageUrl="https://example.com/other.png" />);
    expect(document.querySelector('img')).toBeInTheDocument();
    expect(document.querySelector('.mock-bar-chart')).not.toBeInTheDocument();
  });
});

const gridRect = () => ({ left: 0, top: 0, width: 100, height: 100, right: 100, bottom: 100, x: 0, y: 0, toJSON: () => {} });

function mockPlotRects() {
  const canvas = document.querySelector('.chart-plot-canvas');
  const grid = document.querySelector('.recharts-cartesian-grid');
  if (canvas) canvas.getBoundingClientRect = () => gridRect();
  if (grid) grid.getBoundingClientRect = () => gridRect();
  fireEvent(window, new Event('resize'));
}

describe('mapPointerToDomain', () => {
  it('maps a pointer box to a numeric x and y domain', () => {
    const rect = { left: 0, top: 0, width: 100, height: 100 };
    const out = mapPointerToDomain({ x0: 10, y0: 10, x1: 90, y1: 90, rect, xMode: 'numeric', xDomain: [0, 100], yDomain: [0, 50] });
    expect(out.x[0]).toBeCloseTo(10);
    expect(out.x[1]).toBeCloseTo(90);
    expect(out.y[0]).toBeCloseTo(5);
    expect(out.y[1]).toBeCloseTo(45);
  });

  it('maps a pointer box to category indices', () => {
    const rect = { left: 0, top: 0, width: 100, height: 100 };
    const out = mapPointerToDomain({ x0: 10, y0: 10, x1: 90, y1: 90, rect, xMode: 'category', xCount: 5, yDomain: [0, 100] });
    expect(out.x).toEqual([0, 4]);
  });

  it('rejects tiny drags', () => {
    const rect = { left: 0, top: 0, width: 100, height: 100 };
    expect(mapPointerToDomain({ x0: 49, y0: 49, x1: 51, y1: 51, rect, xMode: 'numeric', xDomain: [0, 100], yDomain: [0, 100] })).toBeNull();
  });
});

describe('ChartRenderer zoom', () => {
  const zoomSpec = { chart_type: 'bar', x: 'label', y: ['value'], data: [
    { label: 'A', value: 10 }, { label: 'B', value: 30 }, { label: 'C', value: 50 }, { label: 'D', value: 70 }, { label: 'E', value: 90 },
  ] };

  it('zooms axes on a box drag and resets via the toolbar button', () => {
    render(<ChartRenderer spec={zoomSpec} sessionId="s1" />);
    mockPlotRects();
    const canvas = document.querySelector('.chart-plot-canvas');
    expect(canvas).toHaveStyle({ cursor: 'crosshair' });

    fireEvent.pointerDown(canvas, { clientX: 20, clientY: 20, pointerId: 1, button: 0 });
    fireEvent.pointerMove(canvas, { clientX: 80, clientY: 80, pointerId: 1 });
    expect(document.querySelector('.chart-zoom-box')).toBeInTheDocument();
    fireEvent.pointerUp(canvas, { clientX: 80, clientY: 80, pointerId: 1 });

    const yaxis = document.querySelector('.mock-yaxis');
    const yDomain = JSON.parse(yaxis.getAttribute('data-domain'));
    expect(yDomain[0]).toBeCloseTo(26);
    expect(yDomain[1]).toBeCloseTo(74);
    expect(yaxis).toHaveAttribute('data-overflow', 'true');
    expect(screen.getByRole('button', { name: 'Réinitialiser le zoom' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Réinitialiser le zoom' }));
    expect(document.querySelector('.mock-yaxis')).toHaveAttribute('data-domain', '');
    expect(document.querySelector('.mock-yaxis')).not.toHaveAttribute('data-overflow', 'true');
    expect(screen.queryByRole('button', { name: 'Réinitialiser le zoom' })).not.toBeInTheDocument();
  });

  it('slices category rows while zoomed', () => {
    render(<ChartRenderer spec={zoomSpec} sessionId="s1" />);
    mockPlotRects();
    const canvas = document.querySelector('.chart-plot-canvas');
    fireEvent.pointerDown(canvas, { clientX: 20, clientY: 20, pointerId: 1, button: 0 });
    fireEvent.pointerMove(canvas, { clientX: 80, clientY: 80, pointerId: 1 });
    fireEvent.pointerUp(canvas, { clientX: 80, clientY: 80, pointerId: 1 });

    const xaxis = document.querySelector('.mock-xaxis');
    expect(xaxis).toHaveAttribute('data-domain', '');
    expect(xaxis).toHaveAttribute('data-overflow', 'true');
  });

  it('does not enable zoom for single-row charts', () => {
    render(<ChartRenderer spec={{ chart_type: 'bar', x: 'label', y: ['value'], data: [{ label: 'A', value: 1 }] }} sessionId="s1" />);
    mockPlotRects();
    const canvas = document.querySelector('.chart-plot-canvas');
    expect(canvas).not.toHaveStyle({ cursor: 'crosshair' });
    fireEvent.pointerDown(canvas, { clientX: 20, clientY: 20, pointerId: 1, button: 0 });
    fireEvent.pointerUp(canvas, { clientX: 80, clientY: 80, pointerId: 1 });
    expect(screen.queryByRole('button', { name: 'Réinitialiser le zoom' })).not.toBeInTheDocument();
    expect(document.querySelector('.mock-yaxis')).toHaveAttribute('data-domain', '');
  });

  it('double-click resets the zoom', () => {
    render(<ChartRenderer spec={zoomSpec} sessionId="s1" />);
    mockPlotRects();
    const canvas = document.querySelector('.chart-plot-canvas');
    fireEvent.pointerDown(canvas, { clientX: 20, clientY: 20, pointerId: 1, button: 0 });
    fireEvent.pointerUp(canvas, { clientX: 80, clientY: 80, pointerId: 1 });
    expect(screen.getByRole('button', { name: 'Réinitialiser le zoom' })).toBeInTheDocument();
    fireEvent.doubleClick(canvas);
    expect(screen.queryByRole('button', { name: 'Réinitialiser le zoom' })).not.toBeInTheDocument();
  });
});
