import { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { adminService } from '../services/adminService';
import PageHeader from '../../shared/components/ui/PageHeader';
import SearchInput from '../../shared/components/ui/SearchInput';
import FilterSelect from '../../shared/components/ui/FilterSelect';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import Pagination from '../../shared/components/ui/Pagination';
import { LoadingState } from '../../shared/components/ui/Spinner';
import useServerTable from '../../shared/hooks/useServerTable';

const CATEGORY_FILTERS = [
  { value: '', label: 'Tous' },
  { value: 'security', label: 'Sécurité' },
  { value: 'errors', label: 'Erreurs' },
  { value: 'warnings', label: 'Avertissements' },
  { value: 'info', label: 'Info' },
];

function LevelBadge({ level }) {
  if (!level) return <span className="text-[12px] text-fg-3">—</span>;
  const map = {
    CRITICAL: 'bg-[#7f1d1d] text-white',
    ERROR: 'bg-[#fef2f2] text-[#f04444] ring-1 ring-[#f04444]/20',
    WARNING: 'bg-[#fff6e8] text-[#f5a142] ring-1 ring-[#f5a142]/20',
    INFO: 'bg-[#eff6ff] text-[#3b82f6] ring-1 ring-[#3b82f6]/20',
    DEBUG: 'bg-[#f3f5f7] text-[#777b85] ring-1 ring-[#777b85]/20',
  };
  const cls = map[level] || map.INFO;
  return (
    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide ${cls}`}>
      {level}
    </span>
  );
}

export default function AdminLogsPage() {
  const [category, setCategory] = useState('');
  const [expanded, setExpanded] = useState({});

  const list = useServerTable({
    fetcher: (params) => adminService.logs({ ...params, category: category || undefined }),
    rowsKey: 'events',
    metaKey: 'meta',
    deps: [category],
  });

  const events = list.rows;
  const meta = list.meta;
  const toggle = (i) => setExpanded((prev) => ({ ...prev, [i]: !prev[i] }));

  return (
    <div>
      <PageHeader
        description="Événements de sécurité, erreurs et activité de l'API sur l'ensemble du système."
      />

      <div className="mb-3 flex flex-wrap items-center gap-3">
        <SearchInput
          value={list.filters.q ?? ''}
          onChange={(v) => list.setFilter('q', v)}
          placeholder="Rechercher des événements, clients, détails…"
          className="w-full max-w-[320px]"
        />
        <FilterSelect
          label="Catégorie"
          value={category}
          onChange={setCategory}
          options={CATEGORY_FILTERS}
        />
      </div>

      <div className={`overflow-hidden rounded-xl border border-line bg-raised ${list.dimClass}`}>
        {list.loading && events.length === 0 ? (
          <LoadingState label="Chargement des journaux…" />
        ) : events.length === 0 ? (
          <TableEmpty title="Aucune entrée de journal ne correspond à votre recherche" />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th className="w-8" />
                <Th>Heure</Th>
                <Th>Niveau</Th>
                <Th>Événement</Th>
                <Th>Client</Th>
              </tr>
            </thead>
            <tbody>
              {events.flatMap((ev, i) => {
                const hasContext = ev.context && Object.keys(ev.context).length > 0;
                const isOpen = !!expanded[i];
                const rows = [
                  <Tr key={i} onClick={hasContext ? () => toggle(i) : undefined} className={hasContext ? 'cursor-pointer' : ''}>
                    <Td className="w-8">
                      {hasContext ? (
                        isOpen ? <ChevronDown className="h-4 w-4 text-fg-3" /> : <ChevronRight className="h-4 w-4 text-fg-3" />
                      ) : null}
                    </Td>
                    <Td className="font-mono text-[12px] text-fg-3">{ev.time || '—'}</Td>
                    <Td><LevelBadge level={ev.level} /></Td>
                    <Td className="font-mono text-[12px] text-fg">{ev.event || (ev.raw ? ev.raw.slice(0, 80) : '—')}</Td>
                    <Td className="text-[12px] text-fg-3">{ev.client_id ?? '—'}</Td>
                  </Tr>,
                ];
                if (hasContext && isOpen) {
                  rows.push(
                    <tr key={`${i}-ctx`}>
                      <td colSpan={5} className="border-b border-[#f0f2f6] bg-[#fafbfc] px-4 py-2.5">
                        <div className="flex flex-wrap gap-x-5 gap-y-1 text-[12px]">
                          {Object.entries(ev.context).map(([k, v]) => (
                            <span key={k} className="min-w-0">
                              <span className="font-medium text-fg-2">{k}</span>
                              <span className="text-fg-3">: {JSON.stringify(v)}</span>
                            </span>
                          ))}
                        </div>
                      </td>
                    </tr>,
                  );
                }
                return rows;
              })}
            </tbody>
          </Table>
        )}
      </div>

      {events.length > 0 && (
        <div className="mt-3">
          <Pagination
            page={list.page}
            perPage={list.perPage}
            total={meta.total}
            onChange={list.setPage}
            onPerPageChange={list.setPerPage}
          />
        </div>
      )}

      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-[12px] text-fg-3">
        <span>
          {meta.total} entrée{meta.total === 1 ? '' : 's'} au total
          {meta.file ? ` · ${meta.file}` : ''}
          {meta.size_bytes ? ` · ${(meta.size_bytes / 1024).toFixed(1)} KB` : ''}
        </span>
        <span>
          {meta.retention_days === 0 ? (
            <span className="font-medium text-fg-2">Rétention : n'expire jamais</span>
          ) : (
            <>Rétention : {meta.retention_days} jour{meta.retention_days === 1 ? '' : 's'}</>
          )}
        </span>
      </div>
    </div>
  );
}
