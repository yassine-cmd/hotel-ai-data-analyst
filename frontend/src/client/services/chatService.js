import { apiFetch } from '../../shared/services/apiClient';

const CONNECT_TIMEOUT_MS = 30000;

function normalizeEvent(data, eventName) {
  return {
    ...data,
    type: eventName || 'message',
    code: data.code || data.error || 'UNKNOWN',
  };
}

function extractHttpError(data, status) {
  const message =
    (data && typeof data.message === 'string' && data.message) ||
    (data && typeof data.error === 'string' && data.error) ||
    (data && data.error && typeof data.error.message === 'string' && data.error.message) ||
    `Échec de la requête (${status})`;
  const code =
    (data && typeof data.code === 'string' && data.code) ||
    (data && data.error && typeof data.error.code === 'string' && data.error.code) ||
    (status === 401 ? 'AUTH_EXPIRED' : 'UNKNOWN');
  return { message, code };
}

export function startAnalysis({ query, sessionId, signal, onEvent }) {
  const controller = new AbortController();
  let timedOut = false;
  const abortFromExternal = () => controller.abort();
  signal?.addEventListener('abort', abortFromExternal);
  const timer = setTimeout(() => { timedOut = true; controller.abort(); }, CONNECT_TIMEOUT_MS);

  return apiFetch('/api/analyze', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query, session_id: sessionId }),
    signal: controller.signal,
  }).then(async (response) => {
    clearTimeout(timer);
    if (!response.ok) {
      let data = null;
      try { data = await response.json(); } catch { /* non-JSON error body */ }
      const { message, code } = extractHttpError(data, response.status);
      const err = new Error(message);
      err.status = response.status;
      err.code = code;
      throw err;
    }
    const reader = response.body?.getReader();
    if (!reader) throw new Error('Le streaming n\u2019est pas pris en charge par ce navigateur.');
    try {
      const decoder = new TextDecoder();
      let buffer = '';
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const frames = buffer.split(/\r?\n\r?\n/);
        buffer = frames.pop() || '';
        frames.forEach((frame) => {
          const eventName = frame.match(/^event:\s*(.+)$/m)?.[1]?.trim();
          const data = frame.match(/^data:\s*(.+)$/m)?.[1];
          if (!data) return;
          try { onEvent(normalizeEvent(JSON.parse(data), eventName)); } catch { /* ignore malformed SSE event */ }
        });
      }
    } finally {
      try { await reader.cancel(); } catch { /* stream already closed */ }
    }
  }).catch((error) => {
    if (timedOut) {
      const err = new Error('Le démarrage de la requête prend trop de temps. Veuillez réessayer.');
      err.code = 'REQUEST_TIMEOUT';
      throw err;
    }
    throw error;
  }).finally(() => {
    clearTimeout(timer);
    signal?.removeEventListener('abort', abortFromExternal);
  });
}
