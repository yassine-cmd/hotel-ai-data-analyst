import { useCallback, useRef, useState } from 'react';
import { startAnalysis } from '../services/chatService';
import { toUserError } from '../utils/errors';

const id = () => `${Date.now()}_${Math.random().toString(36).slice(2)}`;
export function useStreaming({ sessionId, onAssets, onEvent }) {
  const abortRef = useRef(null); const [isStreaming, setIsStreaming] = useState(false);
  const [maxSteps, setMaxSteps] = useState(null);
  const stream = useCallback(async (query, append, sid = sessionId) => {
    const assistantId = id(); append({ id: assistantId, role: 'assistant', blocks: [], streaming: true });
    const update = (recipe) => append({ id: assistantId, update: recipe });
    setIsStreaming(true); abortRef.current = new AbortController();
    let thinkingId; let activeToolId; let tentativeBuffer; let finalBuffer; let finalAnswer;
    let sawDone = false; let errorSet = false; let aborted = false;
    const setError = (m, error) => ({ ...m, error });
    const markError = (message, code, retryable = true) => (m) =>
      setError(m, toUserError({ code, message, retryable }));
    const applyError = (message, code, retryable = true) => {
      errorSet = true;
      update(markError(message, code, retryable));
    };
    // Backend is the source of truth for step numbers: the agent emits
    // phase("reasoning", step=n) before each reasoning round and tags every
    // tool call with step_id "step_{n}_...". We mirror those numbers onto the
    // blocks so the drawer can group by step exactly like the backend counts.
    let currentStep = 0;
    try {
      await startAnalysis({ query, sessionId: sid, signal: abortRef.current.signal, onEvent: (event) => {
        onEvent?.(event);
        if (event.type === 'status' && event.max_steps != null) setMaxSteps(event.max_steps);
        if (event.type === 'phase' && event.step != null) currentStep = event.step;
        if (event.type === 'thinking') {
          const blockId = thinkingId || (thinkingId = id());
          const delta = event.delta || '';
          update((m) => {
            let found = false;
            const blocks = m.blocks.map((b) => { if (b.id === blockId) { found = true; return { ...b, content: b.content + delta }; } return b; });
            return { ...m, blocks: found ? blocks : [...blocks, { id: blockId, type: 'thinking', n: currentStep, content: delta }] };
          });
        }
        if (event.type === 'tool_start') {
          const toolId = event.step_id || id();
          activeToolId = toolId;
          thinkingId = undefined;
          tentativeBuffer = undefined;
          const stepFromId = /^step_(\d+)_/.exec(event.step_id || '');
          update((m) => ({ ...m, blocks: [...m.blocks, { id: toolId, type: 'tool', n: stepFromId ? parseInt(stepFromId[1], 10) : currentStep, tool: event.tool || 'tool_call', args: event.args || {}, status: 'running', description: event.description }] }));
        }
        if (event.type === 'tool_end') {
          const toolId = event.step_id || activeToolId;
          const result = event.result || {};
          update((message) => ({
            ...message,
            blocks: message.blocks.flatMap((block) => block.id !== toolId
              ? [block]
              : [{ ...block, status: result.status === 'error' ? 'error' : result.status === 'partial' ? 'partial' : 'success', error: (result.status === 'error' || result.status === 'partial') ? (result.error || result.message || 'Échec de l\u2019outil') : null }, { id: id(), type: 'result', n: block.n, tool: event.tool || null, result }]),
          }));
          onAssets?.(result);
        }
        if (event.type === 'text' || event.type === 'narration') {
          const delta = event.delta || '';
          if (event.tentative === false) finalBuffer = (finalBuffer || '') + delta;
          else tentativeBuffer = (tentativeBuffer || '') + delta;
        }
        if (event.type === 'text_commit') {
          const content = tentativeBuffer || '';
          tentativeBuffer = undefined;
          if (content) {
            const blockId = id();
            update((m) => ({ ...m, blocks: [...m.blocks, { id: blockId, type: 'text', content }] }));
          }
        }
        if (event.type === 'question') {
          tentativeBuffer = undefined;
          update((m) => ({ ...m, blocks: [...m.blocks, { id: id(), type: 'questions', questions: event.questions || [] }] }));
        }
        if (event.type === 'error') applyError(event.message || 'Une erreur est survenue. Veuillez réessayer.', event.code || 'UNKNOWN', event.retryable !== false);
        if (event.type === 'answer_structured') finalAnswer = event.answer?.answer || finalAnswer;
        if (event.type === 'done') { sawDone = true; if (event.meta?.steps != null) update((m) => ({ ...m, steps: event.meta.steps })); }
        if (event.type === 'done' && !finalAnswer) finalAnswer = event.meta?.answer_structured?.answer;
      }});
    } catch (error) {
      if (error.name === 'AbortError') { aborted = true; }
      else {
        applyError(error.message || 'Une erreur est survenue. Veuillez réessayer.', error.code || 'NETWORK', true);
      }
    }
    finally {
      const content = finalBuffer || finalAnswer;
      if (content) {
        const blockId = id();
        update((m) => (m.blocks.some((b) => b.type === 'text') ? m : { ...m, blocks: [...m.blocks, { id: blockId, type: 'text', content }] }));
      }
      if (!sawDone && !errorSet && !aborted) {
        update(markError('La connexion a été interrompue avant la fin de la réponse. Veuillez réessayer.', 'STREAM_INTERRUPTED', true));
      }
      finalBuffer = undefined; finalAnswer = undefined;
      update((m) => ({ ...m, streaming: false })); setIsStreaming(false); abortRef.current = null;
    }
  }, [onAssets]);
  return { isStreaming, stream, maxSteps, stop: () => abortRef.current?.abort() };
}
