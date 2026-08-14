import { useEffect, useState } from 'react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { DollarSign, Building2, CircleCheck, Database } from 'lucide-react';
import { adminService } from '../services/adminService';
import Card from '../../shared/components/ui/Card';
import StatCard from '../../shared/components/ui/StatCard';
import StatusBadge from '../../shared/components/ui/StatusBadge';
import EmptyState from '../../shared/components/ui/EmptyState';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import { LoadingState } from '../../shared/components/ui/Spinner';
import { CHART_COLORS, CHART_PALETTE } from '../../shared/brand';

function formatCost(n) {
  const abs = Math.abs(n);
  if (abs >= 1_000) return '$' + Math.round(n).toLocaleString('en-US');
  if (abs >= 1) return '$' + n.toFixed(2);
  if (abs >= 0.01) return '$' + n.toFixed(4);
  return '$' + n.toFixed(6);
}

function Leaderboard({ title, rows, valueKey, valueFmt }) {
  const max = rows.length ? rows[0][valueKey] : 0;
  return (
    <Card className="p-5">
      <h3 className="text-[15px] font-semibold text-[#30364d]">{title}</h3>
      {rows.length === 0 ? (
        <EmptyState compact title="Aucune utilisation enregistrée" />
      ) : (
        <div className="mt-4 space-y-3">
          {rows.map((r, i) => (
            <div key={`${r.client_id ?? r.user_id}-${i}`} className="flex items-center gap-3 text-[13px]">
              <span className="grid h-5 w-5 shrink-0 place-items-center rounded-md bg-accent/10 text-[11px] font-semibold text-accent-fg">{i + 1}</span>
              <span className="w-40 truncate text-fg-2">{r.name || r.user_name || r.client_id}</span>
              <div className="h-1.5 min-w-0 flex-1 rounded-full bg-line">
                <div className="h-full rounded-full bg-accent" style={{ width: max > 0 ? `${(r[valueKey] / max) * 100}%` : '0%' }} />
              </div>
              <span className="shrink-0 font-semibold tabular-nums text-fg-2">{valueFmt(r[valueKey])}</span>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

function ClientRow({ client }) {
  const [open, setOpen] = useState(false);
  const maxCost = client.users.length ? client.users[0].cost : 0;
  return (
    <>
      <Tr className="cursor-pointer" onClick={() => setOpen(!open)}>
        <Td>
          <div className="font-medium text-fg">{client.name}</div>
          <div className="text-xs text-fg-3">{client.client_id}</div>
        </Td>
        <Td className="font-medium tabular-nums text-accent-fg">{formatCost(client.cost || 0)}</Td>
        <Td><span className="text-fg-3">{open ? '−' : '+'}</span></Td>
      </Tr>
      {open && (
        <Tr key="expanded" className="bg-base/40">
          <Td colSpan={3}>
            {client.users.length === 0 ? (
              <EmptyState compact title="Aucun membre du personnel" description="Aucun membre du personnel n'est relié à ce client." />
            ) : (
              <div className="space-y-2">
                {client.users.map((u) => (
                  <div key={u.user_id} className="flex items-center gap-3 text-[13px]">
                    <span className="w-40 truncate text-fg-2">{u.user_name}</span>
                    <div className="h-1.5 min-w-0 flex-1 rounded-full bg-line">
                      <div className="h-full rounded-full bg-accent/70" style={{ width: maxCost > 0 ? `${(u.cost / maxCost) * 100}%` : '0%' }} />
                    </div>
                    <span className="shrink-0 font-semibold tabular-nums text-fg-2">{formatCost(u.cost || 0)}</span>
                  </div>
                ))}
              </div>
            )}
          </Td>
        </Tr>
      )}
    </>
  );
}

export default function AdminDashboardPage() {
  const [data, setData] = useState(null);
  const [usage, setUsage] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    Promise.all([adminService.dashboard(), adminService.usage()])
      .then(([d, u]) => { setData(d); setUsage(u); })
      .catch((e) => setError(e.message));
  }, []);

  if (error) return <div className="text-sm text-danger">Échec du chargement du tableau de bord : {error}</div>;
  if (!data || !usage) return <LoadingState label="Chargement du tableau de bord…" />;

  const totals = usage.totals || {};
  const c = CHART_COLORS;
  const series = (usage.series || []).map((d) => ({ ...d, cost: Number(d.cost || 0) }));
  const topClients = (usage.top_clients || []).map((cl) => ({ ...cl, name: cl.name, cost: Number(cl.cost || 0) }));

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Coût total du système" value={formatCost(totals.cost || 0)} icon={<DollarSign className="h-5 w-5" />} />
        <StatCard label="Clients au total" value={data.total_clients} icon={<Building2 className="h-5 w-5" />} />
        <StatCard label="Clients actifs" value={data.active_clients} icon={<CircleCheck className="h-5 w-5" />} />
        <StatCard label="Tables documentées" value={data.total_tables} icon={<Database className="h-5 w-5" />} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2">
          <h3 className="text-[15px] font-semibold text-[#30364d]">Coût — 30 derniers jours</h3>
          <div className="mt-4 h-[260px]">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={series} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                <defs>
                  <linearGradient id="usageFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={CHART_PALETTE[0]} stopOpacity={0.35} />
                    <stop offset="100%" stopColor={CHART_PALETTE[0]} stopOpacity={0.02} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke={c.grid} />
                <XAxis dataKey="date" tick={{ fill: c.tick, fontSize: 11 }} tickFormatter={(d) => d.slice(5)} minTickGap={28} stroke={c.axis} />
                <YAxis tick={{ fill: c.tick, fontSize: 11 }} tickFormatter={formatCost} width={56} stroke={c.axis} />
                <Tooltip contentStyle={{ background: c.tooltipBg, border: `1px solid ${c.tooltipBorder}`, borderRadius: 8, fontSize: 12, color: c.tooltipText }} formatter={(v) => formatCost(v)} labelFormatter={(d) => d} />
                <Area type="monotone" dataKey="cost" name="Coût" stroke={CHART_PALETTE[0]} strokeWidth={2} fill="url(#usageFill)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Leaderboard title="Clients les plus coûteux" rows={topClients} valueKey="cost" valueFmt={formatCost} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="overflow-hidden lg:col-span-2">
          <div className="border-b border-[#e8ecf2] px-5 py-4">
            <h3 className="text-[15px] font-semibold text-[#30364d]">Coût par client</h3>
          </div>
          {(usage.per_client || []).length === 0 ? (
            <TableEmpty title="Aucune utilisation enregistrée" description="Les coûts des clients apparaîtront une fois l'utilisation journalisée." />
          ) : (
            <Table framed={false}>
              <thead><tr><Th>Client</Th><Th>Coût</Th><Th /></tr></thead>
              <tbody>
                {(usage.per_client || []).map((cl) => <ClientRow key={cl.client_id} client={cl} />)}
              </tbody>
            </Table>
          )}
        </Card>

        <Card className="overflow-hidden">
          <div className="border-b border-[#e8ecf2] px-5 py-4">
            <h3 className="text-[15px] font-semibold text-[#30364d]">Vue d'ensemble des clients</h3>
          </div>
          {(data.client_breakdown || []).length === 0 ? (
            <TableEmpty title="Aucun client" description="Les clients apparaîtront ici une fois intégrés." />
          ) : (
            <Table framed={false}>
              <thead><tr><Th>Client</Th><Th>Statut</Th><Th>Utilisateurs</Th></tr></thead>
              <tbody>
                {(data.client_breakdown || []).map((c) => (
                  <Tr key={c.client_id}>
                    <Td>
                      <div className="font-medium text-fg">{c.client_id}</div>
                      <div className="text-xs text-fg-3">{c.name}</div>
                    </Td>
                    <Td><StatusBadge status={c.is_active ? 'active' : 'inactive'} /></Td>
                    <Td>{c.users_count}</Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      </div>
    </div>
  );
}