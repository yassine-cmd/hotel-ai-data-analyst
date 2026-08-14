import { useState } from 'react';
import { Link, useOutletContext } from 'react-router-dom';
import { listStaff, deactivateStaff, activateStaff } from '../services/staffAdminService';
import { ArrowLeft } from 'lucide-react';
import Avatar from '../../shared/components/ui/Avatar';
import Badge from '../../shared/components/ui/Badge';
import Card from '../../shared/components/ui/Card';
import PageHeader from '../../shared/components/ui/PageHeader';
import Pagination from '../../shared/components/ui/Pagination';
import StatusBadge from '../../shared/components/ui/StatusBadge';
import Toggle from '../../shared/components/ui/Toggle';
import SearchInput from '../../shared/components/ui/SearchInput';
import FilterSelect from '../../shared/components/ui/FilterSelect';
import EmptyState from '../../shared/components/ui/EmptyState';
import { LoadingState } from '../../shared/components/ui/Spinner';
import Button from '../../shared/components/ui/Button';
import useServerTable from '../../shared/hooks/useServerTable';

export default function UsersAccessPage() {
  const { user } = useOutletContext();
  const list = useServerTable({
    fetcher: (params) => listStaff(params),
    rowsKey: 'users',
    metaKey: 'meta',
  });
  const users = list.rows;
  const meta = list.meta;
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);

  const toggle = async (u) => {
    setBusy(u.id);
    setError(null);
    try {
      if (u.deactivated_at) await activateStaff(u.id);
      else await deactivateStaff(u.id);
      list.patchRows((rows) => rows.map((x) => (
        x.id === u.id ? { ...x, deactivated_at: x.deactivated_at ? null : new Date().toISOString() } : x
      )));
    } catch {
      setError('Échec de la mise à jour de l\u2019utilisateur.');
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="page-shell flex-1 overflow-y-auto">
      <div className="mx-auto w-full max-w-[1100px] p-6 md:p-8">
        <PageHeader
          title="Utilisateurs et accès"
          description={`Gérez les identifiants de connexion du personnel de ${user?.name || 'votre équipe'}. La désactivation d'un utilisateur bloque son accès jusqu'à ce que vous le réactiviez.`}
          actions={<Link to="/chat"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-3.5 w-3.5" /> Retour</Button></Link>}
        />
        {(error || list.error) && <p className="mb-4 text-sm text-[#f04444]">{error || 'Échec du chargement du personnel.'}</p>}

        <div className="mb-3 flex flex-wrap items-center gap-3">
          <SearchInput
            value={list.filters.q ?? ''}
            onChange={(v) => list.setFilter('q', v)}
            placeholder="Rechercher par nom, identifiant ou service…"
            className="w-full max-w-[320px]"
          />
          <FilterSelect
            label="Statut"
            value={list.filters.status ?? ''}
            onChange={(v) => list.setFilter('status', v)}
            options={[
              { value: 'active', label: 'Actif' },
              { value: 'deactivated', label: 'Désactivé' },
            ]}
          />
        </div>

        <Card className={`overflow-hidden ${list.dimClass}`}>
          {list.loading && !users.length ? (
            <LoadingState className="p-10" label="Chargement du personnel…" />
          ) : users.length === 0 ? (
            <EmptyState compact title="Aucun utilisateur trouvé" />
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-line text-[11px] font-semibold uppercase tracking-wider text-fg-3">
                  <th className="px-6 py-3">Utilisateur</th>
                  <th className="px-4 py-3">Permissions</th>
                  <th className="px-4 py-3">Statut</th>
                  <th className="px-6 py-3 text-right">Accès</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {users.map((u) => (
                  <tr key={u.id}>
                    <td className="px-6 py-3">
                      <div className="flex items-center gap-3">
                        <Avatar name={u.name || 'Utilisateur'} size="sm" />
                        <div className="min-w-0">
                          <p className="truncate font-medium text-fg">{u.name}</p>
                          <p className="text-xs text-fg-3">{u.username}{u.department ? ` · ${u.department}` : ''}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex max-w-[220px] flex-wrap gap-1">
                        {(u.permissions || []).length === 0
                          ? <span className="text-xs text-fg-3">Aucune permission</span>
                          : u.permissions.map((p) => (
                            <Badge key={p.code} variant="subtle">{p.name}</Badge>
                          ))}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge status={u.deactivated_at ? 'inactive' : 'active'} />
                    </td>
                    <td className="px-6 py-3 text-right">
                      <Toggle
                        checked={!u.deactivated_at}
                        disabled={busy === u.id}
                        onChange={() => toggle(u)}
                        label="Actif"
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {users && users.length > 0 && (
            <Pagination
              className="border-t border-line p-4"
              page={meta.page}
              perPage={meta.per_page}
              total={meta.total}
              onChange={list.setPage}
              onPerPageChange={(n) => { list.setPerPage(n); list.setPage(1); }}
            />
          )}
        </Card>
      </div>
    </div>
  );
}