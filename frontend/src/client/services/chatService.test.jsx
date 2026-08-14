// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { startAnalysis } from './chatService';

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

function stubFetch(reader) {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    body: { getReader: () => reader },
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

describe('chatService', () => {
  it('parses SSE frames and cancels the reader on clean completion', async () => {
    const events = [];
    const reader = {
      read: vi.fn()
        .mockResolvedValueOnce({ value: new TextEncoder().encode('event: thinking\ndata: {"delta":"hi"}\n\n'), done: false })
        .mockResolvedValueOnce({ value: undefined, done: true }),
      cancel: vi.fn().mockResolvedValue(),
    };
    stubFetch(reader);
    await startAnalysis({ query: 'q', sessionId: 's1', onEvent: (e) => events.push(e) });
    expect(events).toEqual([{ delta: 'hi', type: 'thinking', code: 'UNKNOWN' }]);
    expect(reader.cancel).toHaveBeenCalled();
  });

  it('cancels the reader and rejects when the stream throws mid-read', async () => {
    const reader = {
      read: vi.fn().mockRejectedValue(new Error('stream broke')),
      cancel: vi.fn().mockResolvedValue(),
    };
    stubFetch(reader);
    await expect(startAnalysis({ query: 'q', sessionId: 's1', onEvent: () => {} })).rejects.toThrow('stream broke');
    expect(reader.cancel).toHaveBeenCalled();
  });

  it('rejects with a typed error when the backend responds non-2xx', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 401,
      json: async () => ({ message: 'Unauthenticated.', error: 'Unauthenticated.' }),
    });
    vi.stubGlobal('fetch', fetchMock);
    const error = await startAnalysis({ query: 'q', sessionId: 's1', onEvent: () => {} }).catch((e) => e);
    expect(error).toBeInstanceOf(Error);
    expect(error.code).toBe('AUTH_EXPIRED');
    expect(error.status).toBe(401);
  });

  it('survives a non-JSON error body', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      json: async () => { throw new Error('not json'); },
    });
    vi.stubGlobal('fetch', fetchMock);
    const error = await startAnalysis({ query: 'q', sessionId: 's1', onEvent: () => {} }).catch((e) => e);
    expect(error.message).toContain('Échec de la requête');
  });

  it('rejects with REQUEST_TIMEOUT when the connection stalls', async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn().mockImplementation((_url, { signal }) => new Promise((_resolve, reject) => {
      signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    vi.stubGlobal('fetch', fetchMock);
    const promise = startAnalysis({ query: 'q', sessionId: 's1', onEvent: () => {} }).catch((e) => e);
    await vi.advanceTimersByTimeAsync(31000);
    const error = await promise;
    expect(error.code).toBe('REQUEST_TIMEOUT');
    expect(error.message).toContain('Le démarrage de la requête prend trop de temps');
  });

  it('aborts the upstream request when the caller aborts', async () => {
    const controller = new AbortController();
    const fetchMock = vi.fn().mockImplementation((_url, { signal }) => new Promise((_resolve, reject) => {
      signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    vi.stubGlobal('fetch', fetchMock);
    const promise = startAnalysis({ query: 'q', sessionId: 's1', signal: controller.signal, onEvent: () => {} });
    controller.abort();
    const error = await promise.catch((e) => e);
    expect(error.name).toBe('AbortError');
  });
});
