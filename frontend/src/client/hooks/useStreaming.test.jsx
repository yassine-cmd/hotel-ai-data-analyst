// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useStreaming } from './useStreaming';
import { startAnalysis } from '../services/chatService';

vi.mock('../services/chatService', () => ({ startAnalysis: vi.fn() }));

async function runStream(events, sessionId = 'sid') {
  startAnalysis.mockImplementation(async ({ onEvent }) => { events.forEach((e) => onEvent(e)); });
  const append = vi.fn();
  const { result } = renderHook(() => useStreaming({ sessionId }));
  await act(async () => { await result.current.stream('q', append); });
  const calls = append.mock.calls.map(([m]) => m);
  const initial = calls.find((m) => !m.update);
  let blocks = [...(initial?.blocks || [])];
  calls.filter((m) => m.update).forEach((m) => { blocks = m.update({ id: m.id, blocks }).blocks; });
  return blocks;
}

async function runMessage(events, sessionId = 'sid') {
  startAnalysis.mockImplementation(async ({ onEvent }) => { events.forEach((e) => onEvent(e)); });
  const append = vi.fn();
  const { result } = renderHook(() => useStreaming({ sessionId }));
  await act(async () => { await result.current.stream('q', append); });
  const calls = append.mock.calls.map(([m]) => m);
  const initial = calls.find((m) => !m.update);
  let message = { id: initial.id, blocks: [...(initial?.blocks || [])] };
  calls.filter((m) => m.update).forEach((m) => { message = m.update(message); });
  return message;
}

describe('useStreaming', () => {
  it('never renders narration text when a tool starts without a commit', async () => {
    const blocks = await runStream([
      { type: 'thinking', delta: 'plan' },
      { type: 'text', delta: 'Let me describe the tables first.' },
      { type: 'tool_start', tool: 'describe_table', step_id: 's1', args: {}, description: '' },
      { type: 'tool_end', tool: 'describe_table', step_id: 's1', result: { status: 'success' } },
    ]);
    expect(blocks.filter((b) => b.type === 'text')).toHaveLength(0);
    expect(blocks.filter((b) => b.type === 'tool')).toHaveLength(1);
  });

  it('never renders narration across multiple tool steps without a commit', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'Let me check the schema.' },
      { type: 'tool_start', tool: 'describe_table', step_id: 's1', args: {}, description: '' },
      { type: 'tool_end', tool: 'describe_table', step_id: 's1', result: { status: 'success' } },
      { type: 'text', delta: 'Now let me query.' },
      { type: 'tool_start', tool: 'execute_sql', step_id: 's2', args: {}, description: '' },
      { type: 'tool_end', tool: 'execute_sql', step_id: 's2', result: { status: 'success' } },
    ]);
    expect(blocks.filter((b) => b.type === 'text')).toHaveLength(0);
    expect(blocks.filter((b) => b.type === 'tool')).toHaveLength(2);
  });

  it('merges tentative=false text into a single block flushed at stream end', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'Step limit reached.', tentative: false },
      { type: 'text', delta: ' Partial results below.', tentative: false },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('Step limit reached. Partial results below.');
  });

  it('shows the tentative=false message after a tool step and drops earlier narration', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'Let me try a query.' },
      { type: 'tool_start', tool: 'execute_sql', step_id: 's1', args: {}, description: '' },
      { type: 'tool_end', tool: 'execute_sql', step_id: 's1', result: { status: 'success' } },
      { type: 'text', delta: 'I lost connection to the model mid-step.', tentative: false },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('I lost connection to the model mid-step.');
  });

  it('does not flush buffered tentative text when tentative=false arrives', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'Half a thought that never committed.' },
      { type: 'text', delta: 'Forced summary.', tentative: false },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('Forced summary.');
  });

  it('keeps the answer text block after text_commit', async () => {
    const blocks = await runStream([
      { type: 'thinking', delta: 'plan' },
      { type: 'text', delta: 'Here is the final answer.' },
      { type: 'text_commit' },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('Here is the final answer.');
  });

  it('keeps the answer while dropping narration from earlier tool steps', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'Let me query first.' },
      { type: 'tool_start', tool: 'execute_sql', step_id: 's1', args: {}, description: '' },
      { type: 'tool_end', tool: 'execute_sql', step_id: 's1', result: { status: 'success' } },
      { type: 'text', delta: 'The answer is 42.' },
      { type: 'text_commit' },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('The answer is 42.');
    expect(blocks.filter((b) => b.type === 'tool')).toHaveLength(1);
  });

  it('renders the confirmed answer from answer_structured when no commit arrives', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'Let me query first.' },
      { type: 'tool_start', tool: 'execute_sql', step_id: 's1', args: {}, description: '' },
      { type: 'tool_end', tool: 'execute_sql', step_id: 's1', result: { status: 'success' } },
      { type: 'answer_structured', answer: { answer: 'The answer is 42.' } },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('The answer is 42.');
    expect(blocks.filter((b) => b.type === 'tool')).toHaveLength(1);
  });

  it('falls back to answer_structured inside done.meta when neither commit nor event arrive', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'Let me query first.' },
      { type: 'tool_start', tool: 'execute_sql', step_id: 's1', args: {}, description: '' },
      { type: 'tool_end', tool: 'execute_sql', step_id: 's1', result: { status: 'success' } },
      { type: 'done', meta: { steps: 1, answer_structured: { answer: 'The answer is 42.' } } },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('The answer is 42.');
  });

  it('dedupes: does not add a duplicate answer when text_commit already committed it', async () => {
    const blocks = await runStream([
      { type: 'text', delta: 'The answer is 42.' },
      { type: 'text_commit' },
      { type: 'answer_structured', answer: { answer: 'The answer is 42.' } },
    ]);
    const textBlocks = blocks.filter((b) => b.type === 'text');
    expect(textBlocks).toHaveLength(1);
    expect(textBlocks[0].content).toBe('The answer is 42.');
  });

  it('marks an interrupted stream (no done event) with a friendly error', async () => {
    const message = await runMessage([{ type: 'text', delta: 'partial', tentative: false }]);
    expect(message.error).toBeTruthy();
    expect(message.error.code).toBe('STREAM_INTERRUPTED');
    expect(message.error.retryable).toBe(true);
  });

  it('maps an error event through toUserError copy', async () => {
    const message = await runMessage([{ type: 'error', code: 'QUOTA_EXCEEDED', message: 'Quota exceeded for policy...', retryable: false }]);
    expect(message.error.code).toBe('QUOTA_EXCEEDED');
    expect(message.error.message).toContain('budget mensuel');
    expect(message.error.retryable).toBe(false);
  });

  it('does not flag a clean stream that reached done as interrupted', async () => {
    const message = await runMessage([{ type: 'done', meta: {} }]);
    expect(message.error).toBeFalsy();
  });
});
