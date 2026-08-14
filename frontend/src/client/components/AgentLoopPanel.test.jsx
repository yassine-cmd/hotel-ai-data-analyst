// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AgentLoopPanel from './AgentLoopPanel';

afterEach(cleanup);

const blocks = [
  { id: 'b1', type: 'thinking', content: '## Plan', n: 1 },
  { id: 'b2', type: 'tool', tool: 'execute_sql', args: { queries: [{ sql: 'SELECT 1', df_name: 'one' }] }, description: 'Count the reservations', status: 'complete', n: 1 },
  { id: 'b3', type: 'result', tool: 'execute_sql', result: { status: 'success', results: [{ df_name: 'res_monthly', true_row_count: 12, shape: [12, 3], columns: ['month', 'count'], dtypes: { month: 'str', count: 'int64' } }] }, n: 1 },
];

describe('AgentLoopPanel', () => {
  it('renders empty state when no blocks and not streaming', () => {
    render(<AgentLoopPanel blocks={[]} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Aucune analyse en cours')).toBeInTheDocument();
  });

  it('renders waiting state with animated dots while streaming with no steps yet', () => {
    render(<AgentLoopPanel blocks={[]} isStreaming onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Analyse de la requête')).toBeInTheDocument();
  });

  it('renders the timeline spine and thought node with step label', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Processus de réflexion et outils')).toBeInTheDocument();
    expect(screen.getByText('Réflexion · Étape 1')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Plan' })).toBeInTheDocument();
  });

  it('shows analyzing dots for an empty thought while streaming', () => {
    const streamingBlocks = [{ id: 't1', type: 'thinking', content: '' }];
    render(<AgentLoopPanel blocks={streamingBlocks} isStreaming onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Réflexion en cours')).toBeInTheDocument();
  });

  it('renders tool card with action title and inline query', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Count the reservations')).toBeInTheDocument();
    expect(screen.queryByText('Réussi')).not.toBeInTheDocument();
    expect(screen.getByText('SELECT 1')).toBeInTheDocument();
    expect(screen.getByText('SQL')).toBeInTheDocument();
  });

  it('includes the tool result inside the tool card', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Résultat · execute_sql')).toBeInTheDocument();
  });

  it('expands tool card result details on header click showing columns', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    fireEvent.click(screen.getByText('Count the reservations'));
    expect(screen.getByText('month: str')).toBeInTheDocument();
    expect(screen.getByText('count: int64')).toBeInTheDocument();
  });

  it('falls back to the tool name as title when no action description', () => {
    const noDesc = [{ id: 'n1', type: 'tool', tool: 'run_python', args: {}, status: 'success' }];
    render(<AgentLoopPanel blocks={noDesc} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('run_python')).toBeInTheDocument();
  });

  it('does not render text or questions blocks as tool_call cards', () => {
    const withTrailingText = [
      { id: 'b1', type: 'thinking', content: 'Plan', n: 1 },
      { id: 'b2', type: 'tool', tool: 'execute_sql', args: {}, status: 'success', n: 1 },
      { id: 'b3', type: 'text', content: 'Final answer text' },
      { id: 'b4', type: 'questions', questions: [{ id: 'q1', text: 'A follow-up?' }] },
    ];
    render(<AgentLoopPanel blocks={withTrailingText} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('execute_sql')).toBeInTheDocument();
    expect(screen.queryByText('tool_call')).not.toBeInTheDocument();
    expect(screen.queryByText('En cours…')).not.toBeInTheDocument();
  });

  it('collapses and expands a completed tool card on header click', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    const body = () => document.querySelector('#agent-loop-panel [class*="grid-rows-"]');
    expect(body()).toHaveClass('grid-rows-[0fr]');
    fireEvent.click(screen.getByText('Count the reservations'));
    expect(body()).toHaveClass('grid-rows-[1fr]');
    fireEvent.click(screen.getByText('Count the reservations'));
    expect(body()).toHaveClass('grid-rows-[0fr]');
  });

  it('shows the error box open when a tool step errored', () => {
    const errorBlocks = [{ id: 'e1', type: 'tool', tool: 'execute_sql', args: {}, error: 'boom', status: 'error' }];
    render(<AgentLoopPanel blocks={errorBlocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('boom')).toBeInTheDocument();
    expect(screen.getByText('Erreur')).toBeInTheDocument();
  });

  it('streaming sets a running tool step to running', () => {
    const runningBlocks = [{ id: 't1', type: 'tool', tool: 'execute_sql', args: {}, status: 'running' }];
    render(<AgentLoopPanel blocks={runningBlocks} isStreaming onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('En cours…')).toBeInTheDocument();
  });

  it('auto-collapses a tool card once the running step completes', () => {
    const { rerender } = render(<AgentLoopPanel blocks={[{ id: 't1', type: 'tool', tool: 'execute_sql', args: {}, status: 'running' }]} isStreaming onClose={vi.fn()} panelOpen />);
    let body = () => document.querySelector('#agent-loop-panel [class*="grid-rows-"]');
    expect(body()).toHaveClass('grid-rows-[1fr]');
    rerender(<AgentLoopPanel blocks={[{ id: 't1', type: 'tool', tool: 'execute_sql', args: {}, status: 'success' }]} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(body()).toHaveClass('grid-rows-[0fr]');
  });

  it('labels the tool result with the producing backend tool', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Résultat · execute_sql')).toBeInTheDocument();
  });

  it('shows backend-style chart summary for create_chart_spec results', () => {
    const chart = [{ id: 'c1', type: 'tool', tool: 'create_chart_spec', args: {}, status: 'success' }, { id: 'c2', type: 'result', tool: 'create_chart_spec', result: { status: 'success', token: '[CHART_0]', chart_spec: { chart_type: 'bar' } } }];
    render(<AgentLoopPanel blocks={chart} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Résultat · create_chart_spec')).toBeInTheDocument();
  });

  it('marks error tool results with an error box', () => {
    const errorObs = [{ id: 'e1', type: 'tool', tool: 'execute_sql', args: {}, status: 'error' }, { id: 'e2', type: 'result', tool: 'execute_sql', result: { status: 'error', error: 'boom' } }];
    render(<AgentLoopPanel blocks={errorObs} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Résultat · execute_sql')).toBeInTheDocument();
    expect(screen.getByText('boom')).toBeInTheDocument();
  });

  it('expand all expands collapsed tool cards', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    const body = () => document.querySelector('#agent-loop-panel [class*="grid-rows-"]');
    expect(body()).toHaveClass('grid-rows-[0fr]');
    fireEvent.click(screen.getByLabelText('Déplier les détails de tous les outils'));
    expect(body()).toHaveClass('grid-rows-[1fr]');
  });

  it('collapse all collapses expanded tool cards', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    const body = () => document.querySelector('#agent-loop-panel [class*="grid-rows-"]');
    fireEvent.click(screen.getByLabelText('Déplier les détails de tous les outils'));
    expect(body()).toHaveClass('grid-rows-[1fr]');
    fireEvent.click(screen.getByLabelText('Replier les détails de tous les outils'));
    expect(body()).toHaveClass('grid-rows-[0fr]');
  });

  it('shows copy buttons on tool query and result output', () => {
    const outputBlocks = [{ id: 'o1', type: 'tool', tool: 'run_python', args: { code: 'print(1)' }, status: 'success' }, { id: 'o2', type: 'result', tool: 'run_python', result: { status: 'success', output: 'hello world' } }];
    render(<AgentLoopPanel blocks={[...blocks, ...outputBlocks]} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getAllByLabelText('Copier la requête').length).toBeGreaterThan(0);
    expect(screen.getAllByLabelText('Copier le résultat').length).toBeGreaterThan(0);
  });

  it('shows python code from the code argument alongside its result', () => {
    const py = [
      { id: 'p1', type: 'tool', tool: 'run_python', args: { code: 'print(1)' }, status: 'success' },
      { id: 'p2', type: 'result', tool: 'run_python', result: { status: 'success', output: 'hello world' } },
    ];
    render(<AgentLoopPanel blocks={py} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Python')).toBeInTheDocument();
    expect(screen.getByText('print(1)')).toBeInTheDocument();
    expect(screen.getByText('hello world')).toBeInTheDocument();
  });

  it('shows curated chart fields plus leftover args for create_chart_spec', () => {
    const chart = [
      { id: 'c1', type: 'tool', tool: 'create_chart_spec', args: { df: 'res_monthly', chart_type: 'bar', x: 'month', y: 'count', title: 'Bookings' }, status: 'success' },
      { id: 'c2', type: 'result', tool: 'create_chart_spec', result: { status: 'success', chart_spec: { chart_type: 'bar' } } },
    ];
    render(<AgentLoopPanel blocks={chart} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText((_c, node) => node.textContent === 'Type : bar')).toBeInTheDocument();
    expect(screen.getByText((_c, node) => node.textContent === 'X : month')).toBeInTheDocument();
    expect(screen.getByText('df:', { exact: true })).toBeInTheDocument();
    expect(screen.getByText('res_monthly', { exact: true })).toBeInTheDocument();
  });

  it('shows step progress in the header meta while streaming', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming maxSteps={9} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText(/Étape 1 sur 9/)).toBeInTheDocument();
  });

  it('shows thinking meta while streaming with thoughts but no tool yet', () => {
    render(<AgentLoopPanel blocks={[{ id: 't1', type: 'thinking', content: 'plan' }]} isStreaming maxSteps={9} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Réflexion en cours…')).toBeInTheDocument();
  });

  it('shows final summary meta with step and tool call counts when done', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('1 étape · 1 appel d\'outil')).toBeInTheDocument();
  });

  it('groups multiple tool calls under their backend step', () => {
    const twoStep = [
      { id: 's1t', type: 'thinking', content: 'first', n: 1 },
      { id: 's1x', type: 'tool', tool: 'execute_sql', args: {}, status: 'complete', n: 1 },
      { id: 's1r', type: 'result', tool: 'execute_sql', result: { status: 'success', output: '1' }, n: 1 },
      { id: 's2t', type: 'thinking', content: 'second', n: 2 },
      { id: 's2x', type: 'tool', tool: 'run_python', args: {}, status: 'complete', n: 2 },
      { id: 's2r', type: 'result', tool: 'run_python', result: { status: 'success', output: '2' }, n: 2 },
    ];
    render(<AgentLoopPanel blocks={twoStep} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(screen.getByText('Réflexion · Étape 1')).toBeInTheDocument();
    expect(screen.getByText('Réflexion · Étape 2')).toBeInTheDocument();
    expect(screen.queryAllByText('Réussi')).toHaveLength(0);
  });

  it('renders mobile drag handle bar', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    expect(document.querySelector('span[aria-hidden="true"]')).toBeInTheDocument();
  });

  it('renders a desktop resize handle with aria value reflecting width', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen width={480} />);
    const handle = screen.getByRole('separator', { name: 'Redimensionner le panneau' });
    expect(handle).toHaveAttribute('aria-orientation', 'vertical');
    expect(handle).toHaveAttribute('aria-valuemin', '320');
    expect(handle).toHaveAttribute('aria-valuemax', String(Math.max(320, (window.innerWidth || 0) - 360)));
    expect(handle).toHaveAttribute('aria-valuenow', '480');
  });

  it('resizing drags the handle and reports the new width', () => {
    const onResize = vi.fn();
    const onResizeStart = vi.fn();
    const onResizeEnd = vi.fn();
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen width={480} onResize={onResize} onResizeStart={onResizeStart} onResizeEnd={onResizeEnd} />);
    const handle = screen.getByRole('separator', { name: 'Redimensionner le panneau' });
    fireEvent(handle, new MouseEvent('pointerdown', { bubbles: true, cancelable: true, button: 0, clientX: 600 }));
    fireEvent(handle, new MouseEvent('pointermove', { bubbles: true, cancelable: true, clientX: 500 }));
    fireEvent(handle, new MouseEvent('pointerup', { bubbles: true, cancelable: true }));
    expect(onResizeStart).toHaveBeenCalledOnce();
    expect(onResizeEnd).toHaveBeenCalledOnce();
    expect(onResize).toHaveBeenCalledWith(580);
  });

  it('clamps resized width to a minimum of 320', () => {
    const onResize = vi.fn();
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen width={480} onResize={onResize} />);
    const handle = screen.getByRole('separator', { name: 'Redimensionner le panneau' });
    fireEvent(handle, new MouseEvent('pointerdown', { bubbles: true, cancelable: true, button: 0, clientX: 100 }));
    fireEvent(handle, new MouseEvent('pointermove', { bubbles: true, cancelable: true, clientX: 2000 }));
    fireEvent(handle, new MouseEvent('pointerup', { bubbles: true, cancelable: true }));
    expect(onResize).toHaveBeenCalledWith(320);
  });

  it('Escape closes the panel when open', () => {
    const onClose = vi.fn();
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={onClose} panelOpen />);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledOnce();
  });

  it('close button calls onClose', () => {
    const onClose = vi.fn();
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={onClose} panelOpen />);
    fireEvent.click(screen.getByLabelText('Fermer le panneau'));
    expect(onClose).toHaveBeenCalledOnce();
  });

  it('closed panel is translated off-screen', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen={false} />);
    expect(document.querySelector('#agent-loop-panel')).toHaveClass('translate-x-full');
    expect(document.querySelector('#agent-loop-panel')).not.toHaveClass('translate-x-0');
  });

  it('open panel slides in and shows a backdrop on mobile', () => {
    render(<AgentLoopPanel blocks={blocks} isStreaming={false} onClose={vi.fn()} panelOpen />);
    const aside = document.querySelector('#agent-loop-panel');
    expect(aside).toHaveClass('translate-x-0');
    expect(aside).not.toHaveClass('translate-x-full');
    expect(document.querySelector('[class*="backdrop"]')).toBeInTheDocument();
  });
});
