import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useGlobalPerPage } from '../components/ui/Pagination';

const EMPTY_META = { total: 0, page: 1, per_page: 10, last_page: 1 };

function normalizeMeta(meta, fallbackTotal, perPage) {
  if (!meta || typeof meta.total !== 'number') {
    return { ...EMPTY_META, total: fallbackTotal, per_page: perPage, last_page: Math.max(1, Math.ceil(fallbackTotal / perPage)) };
  }
  return {
    total: meta.total,
    page: meta.page ?? 1,
    per_page: meta.per_page ?? perPage,
    last_page: meta.last_page ?? Math.max(1, Math.ceil(meta.total / (meta.per_page ?? perPage))),
  };
}

/**
 * Server-side list with search/filter/pagination and the shared "dim while
 * refreshing" behavior. Use for every table backed by a paged API.
 *
 * @param {{ fetcher: (params) => Promise, rowsKey?: string, metaKey?: string, deps?: [] }} config
 *   rowsKey/metaKey: where the array / pagination meta live in the response.
 *   Omit both when the response itself is the rows array (unpaginated lists).
 *   deps: extra values whose change should trigger a refetch (e.g. a route id).
 */
export default function useServerTable({ fetcher, rowsKey, metaKey, deps = [] }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({});
  const [perPage, setPerPage] = useGlobalPerPage();
  const fetchRef = useRef(0);
  const fetcherRef = useRef(fetcher);
  fetcherRef.current = fetcher;

  const setFilter = useCallback((key, value) => {
    setFilters((f) => ({ ...f, [key]: value }));
    setPage(1);
  }, []);

  const clearFilters = useCallback(() => {
    setFilters({});
    setPage(1);
  }, []);

  const load = useCallback(() => {
    const runId = ++fetchRef.current;
    setRefreshing(true);
    setError(null);
    return Promise.resolve()
      .then(() => fetcherRef.current({ page, per_page: perPage, ...filters }))
      .then((res) => {
        if (runId !== fetchRef.current) return;
        setData(res);
        const normalized = normalizeMeta(metaKey ? res?.[metaKey] : undefined, res?.length ?? 0, perPage);
        setPage((p) => Math.max(1, Math.min(p, normalized.last_page)));
      })
      .catch((e) => {
        if (runId !== fetchRef.current) return;
        setError(e);
      })
      .finally(() => {
        if (runId !== fetchRef.current) return;
        setRefreshing(false);
        setLoading(false);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, perPage, filters, metaKey, ...deps]);

  useEffect(() => { load(); }, [load]);

  const reload = useCallback(() => {
    setLoading(true);
    return load();
  }, [load]);

  const patchRows = useCallback((updater) => {
    setData((d) => {
      if (!d) return d;
      if (rowsKey) return { ...d, [rowsKey]: updater(d[rowsKey] || []) };
      return updater(d);
    });
  }, [rowsKey]);

  const rows = rowsKey ? (data?.[rowsKey] ?? []) : (data ?? []);
  const meta = useMemo(
    () => normalizeMeta(metaKey ? data?.[metaKey] : undefined, rows.length, perPage),
    [data, metaKey, rows.length, perPage]
  );

  return {
    data,
    rows,
    meta,
    loading,
    refreshing,
    error,
    page,
    setPage,
    perPage,
    setPerPage,
    filters,
    setFilter,
    clearFilters,
    reload,
    patchRows,
    dimClass: refreshing ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity',
  };
}