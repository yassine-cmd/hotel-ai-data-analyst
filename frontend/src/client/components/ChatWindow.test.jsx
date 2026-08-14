// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChatWindow from './ChatWindow';

vi.mock('../../shared/components/ChartRenderer', () => ({
  default: ({ spec }) => <div className="chart-card" data-token={spec?.token || ''}>mock chart</div>,
}));

afterEach(cleanup);

const thinkingBlocks = [
  { id: 'tb1', type: 'thinking', content: 'plan' },
  { id: 'tb2', type: 'tool', tool: 'execute_sql', args: { queries: [{ sql: 'SELECT 1', df_name: 'one' }] }, description: 'Count the reservations', status: 'success' },
];

describe('ChatWindow', () => {
  it('renders empty state when no messages', () => {
    render(<ChatWindow messages={[]} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.getByText(/Comment puis-je vous aider/)).toBeInTheDocument();
    expect(screen.getByText(/Posez une question pour lancer une analyse\./)).toBeInTheDocument();
  });

  it('shows the restoring animation while history loads and no messages exist', () => {
    render(<ChatWindow messages={[]} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" loadingHistory />);
    expect(screen.getByText(/Restauration de la conversation/)).toBeInTheDocument();
    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByText(/Comment puis-je vous aider/)).not.toBeInTheDocument();
  });

  it('renders the empty state once history has loaded with no messages', () => {
    render(<ChatWindow messages={[]} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" loadingHistory={false} />);
    expect(screen.getByText(/Comment puis-je vous aider/)).toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('renders assistant answer content as markdown', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', blocks: [{ id: 'a1', type: 'text', content: '**bold** answer' }] },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.getByText('bold')).toBeInTheDocument();
  });

  it('renders reasoning strip with backend step count after a user message', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', steps: 10, blocks: thinkingBlocks },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.getByText('A réfléchi pendant 10 étapes')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Ouvrir le panneau de réflexion/i })).toBeInTheDocument();
  });

  it('shows Reasoning by default while streaming with no running tool', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', streaming: true, blocks: [{ id: 'tb1', type: 'thinking', content: 'plan' }] },
    ];
    render(<ChatWindow messages={messages} isStreaming onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.getByText('Réflexion…')).toBeInTheDocument();
  });

  it('shows the running tool action description while streaming', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', streaming: true, blocks: [
        { id: 'tb1', type: 'thinking', content: 'plan' },
        { id: 'tb2', type: 'tool', tool: 'execute_sql', args: {}, description: 'Count the reservations', status: 'running' },
      ] },
    ];
    render(<ChatWindow messages={messages} isStreaming onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.getByText('Count the reservations')).toBeInTheDocument();
    expect(screen.queryByText(/Réflexion/)).not.toBeInTheDocument();
  });

  it('falls back to plain Reasoning when no backend step count', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', blocks: thinkingBlocks },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.getByText('Processus de réflexion')).toBeInTheDocument();
  });

  it('no strip when assistant message has no thinking/tool blocks', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', blocks: [{ id: 'a1', type: 'text', content: 'ok' }] },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.queryByText(/Réflexion/)).not.toBeInTheDocument();
  });

  it('strip reflects active panel state in aria-label', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', blocks: thinkingBlocks },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen activeMessageId="m2" sessionId="s1" />);
    expect(screen.getByRole('button', { name: /Fermer le panneau de réflexion/i })).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('button', { name: /Fermer le panneau de réflexion/i })).toHaveAttribute('aria-controls', 'agent-loop-panel');
  });

  it('only the message whose thinking is shown renders as active', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'first' },
      { id: 'm2', role: 'assistant', steps: 2, blocks: thinkingBlocks },
      { id: 'm3', role: 'user', content: 'second' },
      { id: 'm4', role: 'assistant', steps: 3, blocks: thinkingBlocks },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen activeMessageId="m4" sessionId="s1" />);
    expect(screen.getAllByRole('button', { name: /panneau de réflexion/i })).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: /Fermer le panneau de réflexion/i })).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: /Ouvrir le panneau de réflexion/i })).toHaveLength(1);
  });

  it('clicking the strip toggles the panel for that message', () => {
    const onTogglePanel = vi.fn();
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', blocks: thinkingBlocks },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={onTogglePanel} panelOpen={false} sessionId="s1" />);
    fireEvent.click(screen.getByRole('button', { name: /Ouvrir le panneau de réflexion/i }));
    expect(onTogglePanel).toHaveBeenCalledTimes(1);
    expect(onTogglePanel).toHaveBeenCalledWith(expect.objectContaining({ id: 'm2' }));
  });

  it('strips [CHART_x] tokens from answer content', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      { id: 'm2', role: 'assistant', blocks: [{ id: 'a1', type: 'text', content: 'Here is the chart:\n\n[CHART_5]\n\nMore text' }] },
    ];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.queryByText(/CHART_5/)).not.toBeInTheDocument();
    expect(screen.getByText(/Here is the chart/)).toBeInTheDocument();
    expect(screen.getByText(/More text/)).toBeInTheDocument();
  });

  it('renders charts inline at the token position inside a single answer bubble', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      {
        id: 'm2',
        role: 'assistant',
        blocks: [
          { id: 'a1', type: 'text', content: 'Intro text.\n\n[CHART_5]\n\nOutro text.' },
          { id: 'c1', type: 'result', result: { chart_spec: { token: '[CHART_5]', chart_id: 'CHART_5', chart_type: 'bar' } } },
        ],
      },
    ];
    const { container } = render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    const assistantBubbles = container.querySelectorAll('.message-bubble.assistant');
    expect(assistantBubbles).toHaveLength(1);
    const content = assistantBubbles[0].querySelector('.bubble-content').textContent;
    expect(content).toContain('Intro text.');
    expect(content).toContain('Outro text.');
    expect(container.querySelector('.chart-card')).toHaveAttribute('data-token', '[CHART_5]');
  });

  it('renders a chart alone inside a single assistant bubble when the token is on its own line', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      {
        id: 'm2',
        role: 'assistant',
        blocks: [
          { id: 'a1', type: 'text', content: '[CHART_3]' },
          { id: 'c1', type: 'result', result: { chart_spec: { token: '[CHART_3]', chart_type: 'line' } } },
        ],
      },
    ];
    const { container } = render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    const assistantBubbles = container.querySelectorAll('.message-bubble.assistant');
    expect(assistantBubbles).toHaveLength(1);
    expect(assistantBubbles[0].querySelector('.chart-card')).toBeInTheDocument();
    expect(container.querySelectorAll('.chart-card')).toHaveLength(1);
  });

  it('appends charts not referenced by any token after the answers', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      {
        id: 'm2',
        role: 'assistant',
        blocks: [
          { id: 'a1', type: 'text', content: 'Just text.' },
          { id: 'c1', type: 'result', result: { chart_spec: { token: '[CHART_9]', chart_type: 'bar' } } },
        ],
      },
    ];
    const { container } = render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    const flow = [...container.querySelector('.chat-container > div').children].map((n) => {
      if (n.className.includes('chart-card')) return 'chart';
      const contents = n.querySelectorAll('.bubble-content');
      return contents.length ? [...contents].pop().textContent.trim() : n.textContent.trim();
    });
    expect(flow).toEqual(['hi', 'Just text.', 'chart']);
    expect(container.querySelector('.chart-card')).toHaveAttribute('data-token', '[CHART_9]');
  });

  it('hides orphan charts while the message is streaming', () => {
    const messages = [
      { id: 'm1', role: 'user', content: 'hi' },
      {
        id: 'm2',
        role: 'assistant',
        streaming: true,
        blocks: [
          { id: 'a1', type: 'text', content: 'Working on it.' },
          { id: 'c1', type: 'result', result: { chart_spec: { token: '[CHART_9]', chart_type: 'bar' } } },
        ],
      },
    ];
    const { container } = render(<ChatWindow messages={messages} isStreaming onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(container.querySelectorAll('.chart-card')).toHaveLength(0);
  });

  it('renders assistant errors', () => {
    const messages = [{ id: 'm1', role: 'assistant', blocks: [], error: { message: 'An error occurred', retryable: true } }];
    render(<ChatWindow messages={messages} isStreaming={false} onSend={vi.fn()} onTogglePanel={vi.fn()} panelOpen={false} sessionId="s1" />);
    expect(screen.getByText('An error occurred')).toBeInTheDocument();
  });

  it('renders a session failure banner with a retry that re-sends the query', () => {
    const onSend = vi.fn();
    const messages = [{ id: 'm1', role: 'assistant', blocks: [] }];
    render(
      <ChatWindow
        messages={messages}
        isStreaming={false}
        onSend={onSend}
        onTogglePanel={vi.fn()}
        panelOpen={false}
        sessionId="s1"
        sessionError={{ code: 'STREAM_ERROR', message: 'The last analysis did not complete.', retryable: true, query: 'how many rooms?' }}
      />
    );
    expect(screen.getByText(/La dernière analyse ne s'est pas terminée/)).toBeInTheDocument();
    fireEvent.click(screen.getByText('Réessayer'));
    expect(onSend).toHaveBeenCalledWith('how many rooms?');
  });

  it('dismisses the session failure banner', () => {
    const onDismiss = vi.fn();
    const messages = [{ id: 'm1', role: 'assistant', blocks: [] }];
    render(
      <ChatWindow
        messages={messages}
        isStreaming={false}
        onSend={vi.fn()}
        onTogglePanel={vi.fn()}
        panelOpen={false}
        sessionId="s1"
        sessionError={{ code: 'STREAM_ERROR', message: 'The last analysis did not complete.', retryable: false }}
        onDismissSessionError={onDismiss}
      />
    );
    fireEvent.click(screen.getByText('Ignorer'));
    expect(onDismiss).toHaveBeenCalled();
  });
});
