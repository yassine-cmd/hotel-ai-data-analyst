import { useCallback, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export const PER_PAGE_OPTIONS = [10, 25, 50, 100];

const STORAGE_KEY = 'hotel.pagination.perPage';

export function getGlobalPerPage() {
  const v = Number(localStorage.getItem(STORAGE_KEY));
  return PER_PAGE_OPTIONS.includes(v) ? v : 10;
}

export function useGlobalPerPage(initial = getGlobalPerPage()) {
  const [perPage, setPerPage] = useState(initial);
  const change = useCallback((n) => {
    const v = PER_PAGE_OPTIONS.includes(n) ? n : 10;
    localStorage.setItem(STORAGE_KEY, String(v));
    setPerPage(v);
  }, []);
  return [perPage, change];
}

function pageWindow(page, lastPage, max = 5) {
  if (lastPage <= max) {
    return Array.from({ length: lastPage }, (_, i) => i + 1);
  }
  const windowStart = Math.max(2, Math.min(page - Math.floor((max - 2) / 2), lastPage - max + 1));
  const windowEnd = Math.min(lastPage - 1, windowStart + max - 3);
  const pages = [1];
  if (windowStart > 2) pages.push('…');
  for (let p = windowStart; p <= windowEnd; p++) pages.push(p);
  if (windowEnd < lastPage - 1) pages.push('…');
  pages.push(lastPage);
  return pages;
}

export default function Pagination({
  page,
  perPage,
  total,
  onChange,
  onPerPageChange,
  perPageOptions = PER_PAGE_OPTIONS,
  className = '',
}) {
  if (total <= 0) return null;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);

  const pages = pageWindow(page, lastPage);

  return (
    <div className={`flex flex-wrap items-center justify-between gap-3 text-xs text-fg-3 ${className}`}>
      <div className="flex items-center gap-2">
        <span>Lignes par page</span>
        <select
          value={perPage}
          onChange={(e) => onPerPageChange?.(Number(e.target.value))}
          className="h-7 rounded-md border border-line bg-base px-2 text-[12px] font-medium text-fg"
          aria-label="Lignes par page"
        >
          {perPageOptions.map((n) => (
            <option key={n} value={n}>{n}</option>
          ))}
        </select>
        <span>· {from}–{to} sur {total}</span>
      </div>
      <div className="flex items-center gap-1">
        <button
          className="inline-flex h-7 items-center gap-1 rounded-md px-2 font-medium text-fg-2 transition-colors hover:bg-white/5 hover:text-fg disabled:pointer-events-none disabled:opacity-40"
          onClick={() => onChange(Math.max(1, page - 1))}
          disabled={page <= 1}
        >
          <ChevronLeft className="h-3.5 w-3.5" />
          Précédent
        </button>
        {pages.map((p, i) =>
          p === '…' ? (
            <span key={`ellipsis-${i}`} className="px-1">…</span>
          ) : (
            <button
              key={p}
              className={`grid h-7 min-w-7 place-items-center rounded-md px-1.5 font-medium transition-colors ${
                p === page ? 'bg-accent text-white' : 'text-fg-2 hover:bg-white/5 hover:text-fg'
              }`}
              onClick={() => onChange(p)}
            >
              {p}
            </button>
          )
        )}
        <button
          className="inline-flex h-7 items-center gap-1 rounded-md px-2 font-medium text-fg-2 transition-colors hover:bg-white/5 hover:text-fg disabled:pointer-events-none disabled:opacity-40"
          onClick={() => onChange(Math.min(lastPage, page + 1))}
          disabled={page >= lastPage}
        >
          Suivant
          <ChevronRight className="h-3.5 w-3.5" />
        </button>
      </div>
    </div>
  );
}