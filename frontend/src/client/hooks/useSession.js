import { useCallback, useEffect, useRef, useState } from 'react';
import { listSessions, createSession, getHistory, deleteSession, renameSession } from '../services/sessionService';

export function useSession(user) {
  const clientId = user?.client_id || null;
  const [sessions, setSessions] = useState([]);
  const [activeSessionId, setActiveSessionId] = useState(null);
  const [history, setHistory] = useState([]);
  const [workspace, setWorkspace] = useState({ dataframes: {}, plots: [] });
  const [sessionError, setSessionError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingHistory, setLoadingHistory] = useState(false);
  const freshIdsRef = useRef(new Set());

  const refreshSessions = useCallback(async () => {
    try {
      const data = await listSessions();
      setSessions(data);
      if (!activeSessionId && data.length > 0) {
        setActiveSessionId(data[0].session_id);
      }
    } catch { /* server might be offline */ }
  }, [activeSessionId]);

  useEffect(() => {
    if (clientId) {
      setLoading(true);
      refreshSessions().finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, [clientId, refreshSessions]);

  const load = useCallback(async () => {
    if (!activeSessionId) return;
    if (freshIdsRef.current.has(activeSessionId)) {
      freshIdsRef.current.delete(activeSessionId);
      return;
    }
    setLoadingHistory(true);
    try {
      const data = await getHistory(activeSessionId);
      setHistory(data.history || []);
      setWorkspace({
        dataframes: data.workspace?.dataframes || {},
        plots: data.workspace?.plots || [],
      });
      setSessionError(data.session_error || null);
    } catch { /* session might be empty/new */ }
    finally { setLoadingHistory(false); }
  }, [activeSessionId]);

  const create = useCallback(async (name) => {
    const result = await createSession(name);
    freshIdsRef.current.add(result.session_id);
    setActiveSessionId(result.session_id);
    setHistory([]);
    setWorkspace({ dataframes: {}, plots: [] });
    setSessionError(null);
    await refreshSessions();
    return result.session_id;
  }, [refreshSessions]);

  const select = useCallback(async (id) => {
    if (id === activeSessionId) return;
    setActiveSessionId(id);
    setHistory([]);
    setWorkspace({ dataframes: {}, plots: [] });
    setSessionError(null);
  }, [activeSessionId]);

  const remove = useCallback(async (id) => {
    try {
      await deleteSession(id);
      const next = sessions.filter((s) => s.session_id !== id);
      setSessions(next);
      if (id === activeSessionId) {
        const fallback = next[0] || null;
        setActiveSessionId(fallback?.session_id || null);
        setHistory([]);
        setWorkspace({ dataframes: {}, plots: [] });
        setSessionError(null);
      }
    } catch { /* handle error */ }
  }, [sessions, activeSessionId]);

  const rename = useCallback(async (id, name) => {
    const updated = await renameSession(id, name);
    setSessions((prev) => prev.map((s) => s.session_id === id ? { ...s, name: updated.name || name } : s));
    return updated;
  }, []);

  const clear = useCallback(async () => {
    await remove(activeSessionId);
  }, [remove, activeSessionId]);

  return {
    clientId,
    sessionId: activeSessionId,
    sessions,
    history,
    setHistory,
    workspace,
    setWorkspace,
    sessionError,
    setSessionError,
    loading,
    loadingHistory,
    load,
    create,
    select,
    remove,
    rename,
    clear,
  };
}
