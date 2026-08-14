// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import useServerTable from './useServerTable';

afterEach(() => { cleanup(); });

function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { resolve, promise };
}

describe('useServerTable', () => {
  it('fetches rows and meta from the fetcher', async () => {
    const fetcher = vi.fn().mockResolvedValue({ users: [{ id: 1 }], meta: { total: 1, page: 1, per_page: 10, last_page: 1 } });
    const { result } = renderHook(() => useServerTable({ fetcher, rowsKey: 'users', metaKey: 'meta' }));

    expect(result.current.loading).toBe(true);
    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(fetcher).toHaveBeenCalledWith({ page: 1, per_page: 10 });
    expect(result.current.rows).toEqual([{ id: 1 }]);
    expect(result.current.meta.total).toBe(1);
  });

  it('handles a response that is itself the rows array', async () => {
    const fetcher = vi.fn().mockResolvedValue([{ id: 2 }, { id: 3 }]);
    const { result } = renderHook(() => useServerTable({ fetcher }));

    await waitFor(() => expect(result.current.loading).toBe(false));
    expect(result.current.rows).toEqual([{ id: 2 }, { id: 3 }]);
    expect(result.current.meta.total).toBe(2);
  });

  it('setFilter resets page to 1 and refetches with the filter', async () => {
    const fetcher = vi.fn().mockResolvedValue({ users: [], meta: { total: 0, page: 1, per_page: 10, last_page: 1 } });
    const { result } = renderHook(() => useServerTable({ fetcher, rowsKey: 'users', metaKey: 'meta' }));

    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1));

    await act(async () => { result.current.setFilter('q', 'acme'); });
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    expect(fetcher).toHaveBeenLastCalledWith({ page: 1, per_page: 10, q: 'acme', status: undefined });
    expect(result.current.filters.q).toBe('acme');
  });

  it('patchRows applies an updater inside the configured rowsKey', async () => {
    const fetcher = vi.fn().mockResolvedValue({ users: [{ id: 1, name: 'A' }], meta: { total: 1, page: 1, per_page: 10, last_page: 1 } });
    const { result } = renderHook(() => useServerTable({ fetcher, rowsKey: 'users', metaKey: 'meta' }));

    await waitFor(() => expect(result.current.loading).toBe(false));
    act(() => result.current.patchRows((rows) => rows.map((r) => ({ ...r, name: 'B' }))));
    expect(result.current.rows[0].name).toBe('B');
  });

  it('dims while refreshing and exposes a stable dimClass', async () => {
    const d = deferred();
    const fetcher = vi.fn().mockReturnValue(d.promise);
    const { result } = renderHook(() => useServerTable({ fetcher, rowsKey: 'users', metaKey: 'meta' }));

    expect(result.current.refreshing).toBe(true);
    expect(result.current.dimClass).toContain('opacity-60');

    await act(async () => { d.resolve({ users: [{ id: 1 }], meta: { total: 1, page: 1, per_page: 10, last_page: 1 } }); });
    await waitFor(() => expect(result.current.refreshing).toBe(false));
    expect(result.current.dimClass).toBe('transition-opacity');
  });

  it('records a fetch error without throwing', async () => {
    const fetcher = vi.fn().mockRejectedValue(new Error('boom'));
    const { result } = renderHook(() => useServerTable({ fetcher, rowsKey: 'users', metaKey: 'meta' }));

    await waitFor(() => expect(result.current.error).toBeTruthy());
    expect(result.current.refreshing).toBe(false);
    expect(result.current.rows).toEqual([]);
  });

  it('refetches when a dependency changes', async () => {
    const fetcher = vi.fn().mockResolvedValue({ users: [], meta: { total: 0, page: 1, per_page: 10, last_page: 1 } });
    let id = 1;
    const { result, rerender } = renderHook(
      () => useServerTable({ fetcher: (p) => fetcher(id, p), rowsKey: 'users', metaKey: 'meta', deps: [id] })
    );

    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1));
    id = 2;
    rerender();
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    expect(fetcher).toHaveBeenLastCalledWith(2, expect.objectContaining({ page: 1 }));
  });
});