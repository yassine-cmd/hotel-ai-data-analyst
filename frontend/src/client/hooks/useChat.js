import { useCallback, useEffect, useRef, useState } from 'react';
import { useStreaming } from './useStreaming';
import { downloadUrl } from '../services/sessionService';
import { smartTitle } from '../utils/smartTitle';
const applyUpdates = (messages, pending) => {
  let next = messages;
  pending.forEach((m) => { next = next.map((item) => item.id === m.id ? m.update(item) : item); });
  return next;
};
const id = () => `${Date.now()}_${Math.random().toString(36).slice(2)}`;
const stepNumOf = (stepId) => { const m = /^step_(\d+)_/.exec(stepId || ''); return m ? parseInt(m[1], 10) : null; };
const restored = (turn) => {
  const thoughts = Object.entries(turn.thinking_per_step || {})
    .map(([n, content]) => ({ n: parseInt(n, 10), id: id(), type: 'thinking', content: content || '' }));
  const tools = (turn.tool_calls || []).map((call, i) => ({ n: stepNumOf(call.step_id), i, call }));
  const nums = [...new Set([...thoughts.map((t) => t.n), ...tools.map((t) => t.n).filter((n) => n != null)])].sort((a, b) => a - b);
  const blocks = [];
  for (const n of nums) {
    const thought = thoughts.find((t) => t.n === n);
    if (thought) blocks.push(thought);
    tools.filter((t) => t.n === n).sort((a, b) => a.i - b.i).forEach(({ call }) => {
      const err = call.status === 'error' || call.status === 'partial'
        ? (call.result?.error || call.result?.message || null)
        : null;
      blocks.push({ id: call.step_id || id(), type: 'tool', n, tool: call.tool, args: call.args || {}, status: call.status || call.result?.status || 'success', description: call.description, error: err });
      blocks.push({ id: id(), type: 'result', n, tool: call.tool || null, result: call.result || {} });
    });
  }
  if (turn.answer) blocks.push({ id: id(), type: 'text', content: turn.answer });
  if (turn.questions?.length) blocks.push({ id: id(), type: 'questions', questions: turn.questions });
  return [{ id: id(), role: 'user', content: turn.query }, { id: id(), role: 'assistant', steps: turn.steps, blocks }];
};
export function useChat(session) {
  const [messages, setMessages] = useState([]);
  const [eventLogs, setEventLogs] = useState([]);
  useEffect(() => setMessages(session.history.flatMap(restored)), [session.history]);
  const onAssets = useCallback((result) => session.setWorkspace((old) => { const frames = { ...old.dataframes }; (result.results || []).filter((row) => row.status === 'success').forEach((row) => { frames[row.df_name] = { shape: row.shape || [0, 0], download_url: downloadUrl(session.sessionId, row.df_name) }; }); return { ...old, dataframes: frames, plots: [...new Set([...(old.plots || []), ...(result.image_urls || [])])] }; }), [session]);
  const onEvent = useCallback((event) => setEventLogs((logs) => [...logs, event]), []);
  const { isStreaming, stream, maxSteps, stop } = useStreaming({ sessionId: session.sessionId, onAssets, onEvent });
  const pendingRef = useRef([]);
  const rafRef = useRef(0);
  const flush = useCallback(() => {
    rafRef.current = 0;
    const pending = pendingRef.current;
    pendingRef.current = [];
    if (pending.length) setMessages((old) => applyUpdates(old, pending));
  }, []);
  const append = useCallback((message) => {
    if (!message.update) {
      const pending = pendingRef.current;
      pendingRef.current = [];
      if (rafRef.current) { cancelAnimationFrame(rafRef.current); rafRef.current = 0; }
      setMessages((old) => [...applyUpdates(old, pending), message]);
      return;
    }
    pendingRef.current.push(message);
    if (!rafRef.current) rafRef.current = requestAnimationFrame(flush);
  }, [flush]);
  const creatingRef = useRef(false);
  const send = async (content) => {
    if (!content.trim() || isStreaming || creatingRef.current) return;
    let sid = session.sessionId;
    if (!sid) {
      creatingRef.current = true;
      try { sid = await session.create(smartTitle(content)); } catch { creatingRef.current = false; return; }
      creatingRef.current = false;
    } else if (session.sessions.find((s) => s.session_id === sid && !s.name)) {
      session.rename(sid, smartTitle(content)).catch(() => {});
    }
    append({ id: id(), role: 'user', content });
    stream(content, append, sid);
  };
  return { messages, send: (content) => { setEventLogs([]); send(content); }, isStreaming, maxSteps, stop, eventLogs };
}
