import { useEffect, useState } from 'react';
import { Link, useOutletContext } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { getProfile, getTokenUsage } from '../services/profileService';
import Avatar from '../../shared/components/ui/Avatar';
import Button from '../../shared/components/ui/Button';
import Card from '../../shared/components/ui/Card';
import StatCard from '../../shared/components/ui/StatCard';
import EmptyState from '../../shared/components/ui/EmptyState';
import Pagination, { useGlobalPerPage } from '../../shared/components/ui/Pagination';
import { LoadingState } from '../../shared/components/ui/Spinner';
import { sessionLabel } from '../utils/smartTitle';
import { formatMoney } from '../../shared/utils/money';

const fmt = new Intl.NumberFormat('en-US');

export default function ProfilePage() {
  const { user } = useOutletContext();
  const [profile, setProfile] = useState(null);
  const [client, setClient] = useState(null);
  const [usage, setUsage] = useState(null);
  const [usageLoading, setUsageLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [usersPage, setUsersPage] = useState(1);
  const [perPage, setPerPage] = useGlobalPerPage();

  useEffect(() => {
    getProfile().then((d) => { setProfile(d.user); setClient(d.client); }).catch(() => {});
  }, []);

  useEffect(() => {
    let active = true;
    setUsageLoading(true);
    getTokenUsage(page, perPage, usersPage, perPage)
      .then((d) => { if (active) setUsage(d); })
      .catch(() => {})
      .finally(() => { if (active) setUsageLoading(false); });
    return () => { active = false; };
  }, [page, usersPage, perPage]);

  const displayName = profile?.name || user?.name || 'Utilisateur';
  const sessionsMeta = usage?.sessions_meta || { total: 0, page: 1, per_page: 10, last_page: 1 };
  const usersMeta = usage?.per_user_meta || { total: 0, page: 1, per_page: 10, last_page: 1 };
  const isClientAdmin = !user?.is_admin && (user?.role ?? 0) === 1;

  return (
    <div className="page-shell flex-1 overflow-y-auto">
      <div className="mx-auto w-full max-w-[1100px] p-6 md:p-8">
        <Card className="overflow-hidden">
          <div className="flex flex-wrap items-center gap-4 border-b border-line p-6">
            <Avatar name={displayName} size="lg" />
            <div className="min-w-0">
              <h1 className="truncate text-xl font-semibold tracking-tight">{displayName}</h1>
              <p className="text-[13px] text-fg-3">
                {profile?.username || user?.username || ''}
                {client?.name ? <span> · {client.name}</span> : ''}
              </p>
            </div>
            <Link className="ml-auto" to="/chat"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-3.5 w-3.5" /> Retour</Button></Link>
          </div>
        </Card>

        <Card className="mt-6 p-6">
          <div className="flex items-baseline justify-between gap-4">
            <h3 className="text-[15px] font-semibold">Utilisation globale</h3>
            <span className="text-xs text-fg-3">Toute l'équipe</span>
          </div>
          {!usage && usageLoading ? (
            <LoadingState className="mt-4" label="Chargement de l'utilisation…" />
          ) : !usage ? (
            <p className="mt-4 text-sm text-fg-3">Échec du chargement de l'utilisation.</p>
          ) : (
            <>
              {usage.budget?.limit_usd != null && (
                <div className="mt-5 rounded-lg border border-line bg-base p-4">
                  <div className="flex items-baseline justify-between gap-4">
                    <div>
                      <p className="text-[11px] font-medium uppercase tracking-wider text-fg-3">Budget mensuel global</p>
                      <p className="mt-1 text-[15px] font-semibold tabular-nums text-fg">
                        {formatMoney(usage.budget.remaining_usd ?? 0)}
                        <span className="text-[12px] font-normal text-fg-3"> restant sur {formatMoney(usage.budget.limit_usd)}</span>
                      </p>
                    </div>
                    <span className="text-[12px] tabular-nums text-fg-3">{formatMoney(usage.budget.spend_usd ?? 0)} utilisés</span>
                  </div>
                  <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-surface">
                    <div
                      className="h-full rounded-full transition-all duration-500"
                      style={{
                        width: `${Math.min(100, ((usage.budget.spend_usd ?? 0) / usage.budget.limit_usd) * 100)}%`,
                        backgroundColor: (usage.budget.spend_usd ?? 0) >= usage.budget.limit_usd ? 'var(--color-danger)' : 'var(--color-accent)',
                      }}
                    />
                  </div>
                </div>
              )}
              <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Tokens totaux" value={fmt.format(usage.totals?.total_tokens || 0)} />
                <StatCard label="Prompt" value={fmt.format(usage.totals?.prompt_tokens || 0)} />
                <StatCard label="Completion" value={fmt.format(usage.totals?.completion_tokens || 0)} />
                <StatCard label="Raisonnement" value={fmt.format(usage.totals?.reasoning_tokens || 0)} />
              </div>

              <div className="mt-8 flex items-baseline justify-between gap-4">
                <h4 className="text-[13px] font-semibold uppercase tracking-wider text-fg-3">Utilisation par membre du personnel</h4>
                {usageLoading && <span className="text-xs text-fg-3">Actualisation…</span>}
              </div>
              {isClientAdmin && (
                (usage.per_user || []).length === 0 ? (
                  <EmptyState compact title="Aucune utilisation enregistrée" />
                ) : (
                  <>
                    <ul className="mt-2 divide-y divide-line">
                      {usage.per_user.map((u) => (
                        <li key={u.user_id} className="flex items-center justify-between gap-4 py-2 text-sm">
                          <span className="truncate font-medium text-fg">{u.user_name}</span>
                          <span className="shrink-0 tabular-nums text-fg-2">
                            {fmt.format(u.total_tokens)} tokens
                            <span className="ml-2 text-xs text-fg-3">{formatMoney(u.cost_usd ?? 0)}</span>
                          </span>
                        </li>
                      ))}
                    </ul>
                    <Pagination
                      className="mt-4"
                      page={usersMeta.page}
                      perPage={usersMeta.per_page}
                      total={usersMeta.total}
                      onChange={setUsersPage}
                      onPerPageChange={(n) => { setPerPage(n); setUsersPage(1); setPage(1); }}
                    />
                  </>
                )
              )}
            </>
          )}
        </Card>

        <Card className="mt-6 p-6">
          <div className="flex items-baseline justify-between gap-4">
            <h3 className="text-[15px] font-semibold">Conversations</h3>
            {usageLoading && <span className="text-xs text-fg-3">Actualisation…</span>}
          </div>
          {!usage && usageLoading ? (
            <LoadingState className="mt-4" label="Chargement de l'utilisation…" />
          ) : !usage ? (
            <p className="mt-4 text-sm text-fg-3">Échec du chargement de l'utilisation.</p>
          ) : (usage.sessions || []).length === 0 ? (
            <EmptyState compact title="Aucune conversation" />
          ) : (
            <>
              <ul className="mt-4 divide-y divide-line">
                {usage.sessions.map((s) => (
                  <li key={s.session_id} className="flex items-center justify-between gap-4 py-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-fg">{sessionLabel(s)}</p>
                      <p className="text-xs text-fg-3">
                        {isClientAdmin && s.user_name ? `${s.user_name} · ` : ''}
                        {s.created_at ? new Date(s.created_at).toLocaleDateString() : ''} · {s.turn_count} tours
                      </p>
                    </div>
                    <span className="shrink-0 text-sm font-semibold tabular-nums text-fg-2">{fmt.format(s.total_tokens)} tokens</span>
                  </li>
                ))}
              </ul>
              <Pagination
                className="mt-4"
                page={sessionsMeta.page}
                perPage={sessionsMeta.per_page}
                total={sessionsMeta.total}
                onChange={setPage}
                onPerPageChange={(n) => { setPerPage(n); setPage(1); setUsersPage(1); }}
              />
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
