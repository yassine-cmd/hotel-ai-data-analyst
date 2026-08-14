import { useRef, useState } from 'react';
import { Link, useOutletContext, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, RefreshCw, Users, KeyRound, Copy, Download, Check } from 'lucide-react';
import { adminService } from '../services/adminService';
import PageHeader from '../../shared/components/ui/PageHeader';
import Card from '../../shared/components/ui/Card';
import StatCard from '../../shared/components/ui/StatCard';
import Badge from '../../shared/components/ui/Badge';
import StatusBadge from '../../shared/components/ui/StatusBadge';
import Toggle from '../../shared/components/ui/Toggle';
import SearchInput from '../../shared/components/ui/SearchInput';
import FilterSelect from '../../shared/components/ui/FilterSelect';
import Field, { TextInput } from '../../shared/components/ui/Field';
import Button from '../../shared/components/ui/Button';
import Modal from '../../shared/components/ui/Modal';
import Pagination from '../../shared/components/ui/Pagination';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import { LoadingState } from '../../shared/components/ui/Spinner';
import useServerTable from '../../shared/hooks/useServerTable';
import { formatMoney } from '../../shared/utils/money';

async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }
}

function downloadText(filename, text) {
  const a = document.createElement('a');
  a.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(text);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

function formatCost(n) {
  const v = Number(n || 0);
  if (v >= 1000) return formatMoney(Math.round(v));
  if (v >= 1) return '$' + v.toFixed(2);
  if (v >= 0.01) return '$' + v.toFixed(4);
  return '$' + v.toFixed(6);
}

function DetailItem({ label, value, mono }) {
  return (
    <div className="flex items-center justify-between gap-4 py-2 text-[13px]">
      <span className="text-fg-3">{label}</span>
      <span className={`${mono ? 'font-mono text-[12.5px]' : 'font-medium'} text-fg ${mono ? 'truncate' : ''}`}>{value}</span>
    </div>
  );
}

const ROLE_META = {
  1: { label: 'Administrateur', variant: 'accent' },
  0: { label: 'Gestionnaire', variant: 'neutral' },
};

export default function ClientDashboardPage() {
  const { id } = useParams();
  const { notify } = useOutletContext();
  const list = useServerTable({
    fetcher: (params) => adminService.clientDashboard(id, params),
    rowsKey: 'users',
    metaKey: 'users_meta',
    deps: [id],
  });
  const data = list.data;

  const [syncOpen, setSyncOpen] = useState(false);
  const [syncUsers, setSyncUsers] = useState([]);
  const [syncSummary, setSyncSummary] = useState(null);
  const [syncLoading, setSyncLoading] = useState(false);
  const [accessBusy, setAccessBusy] = useState(null);
  const [generatedKey, setGeneratedKey] = useState(null);
  const [showKeyPassword, setShowKeyPassword] = useState(false);
  const [keyPassword, setKeyPassword] = useState('');
  const [keyBusy, setKeyBusy] = useState(false);
  const [copied, setCopied] = useState(false);
  const copyTimerRef = useRef(null);

  const handleCopyKey = async () => {
    await copyToClipboard(generatedKey?.private_key || '');
    setCopied(true);
    clearTimeout(copyTimerRef.current);
    copyTimerRef.current = setTimeout(() => setCopied(false), 2000);
  };

  const fail = (e) => notify({ variant: 'error', message: e.message });

  const openSync = async () => {
    setSyncOpen(true);
    setSyncSummary(null);
    setSyncUsers([]);
    setSyncLoading(true);
    try {
      const res = await adminService.discoverUsers(id);
      setSyncSummary(res.data?.summary ?? null);
      setSyncUsers(res.data?.users ?? []);
    } catch (e) { notify({ variant: 'error', message: e.message }); }
    finally { setSyncLoading(false); }
  };
  const closeSync = () => {
    setSyncOpen(false);
    setSyncSummary(null);
    setSyncUsers([]);
  };
  const runSync = async () => {
    setSyncLoading(true);
    try {
      const res = await adminService.syncUsers(id);
      setSyncSummary(res.data?.summary ?? null);
      setSyncUsers(res.data?.users ?? []);
      list.reload();
      notify({ variant: 'success', message: 'Synchronisation des utilisateurs terminée' });
    } catch (e) { notify({ variant: 'error', message: e.message }); }
    finally { setSyncLoading(false); }
  };

  const toggleUserAccess = async (u, enable) => {
    setAccessBusy(u.id);
    try {
      if (enable) await adminService.activateUser(id, u.id);
      else await adminService.deactivateUser(id, u.id);
      list.patchRows((rows) => rows.map((x) =>
        x.id === u.id ? { ...x, deactivated_at: enable ? null : new Date().toISOString() } : x
      ));
      notify({ variant: 'success', message: `${u.name || 'Utilisateur'} ${enable ? 'activé' : 'désactivé'}` });
    } catch (e) { notify({ variant: 'error', message: e.message }); }
    finally { setAccessBusy(null); }
  };

  if (list.loading && !data) return <LoadingState label="Chargement du tableau de bord client…" />;
  if (list.error || !data) return <div className="text-sm text-danger">Échec du chargement du tableau de bord client{list.error?.message ? ` : ${list.error.message}` : ''}</div>;

  const client = data.client || {};
  const budget = data.budget || {};
  const users = list.rows || [];
  const usersMeta = list.meta;
  const pct = budget.limit_usd != null && budget.limit_usd > 0
    ? Math.min(100, ((budget.spend_usd ?? 0) / budget.limit_usd) * 100)
    : 0;
  const overBudget = budget.limit_usd != null && (budget.spend_usd ?? 0) >= budget.limit_usd;

  return (
    <div>
      <PageHeader
        title={<span className="flex items-center gap-2"><Building2 className="h-5 w-5 text-accent-fg" /> {client.name || 'Client'}</span>}
        description={`Client #${client.id} · ${client.analytics_db_name ? `database “${client.analytics_db_name}”` : ''}`.trim()}
        actions={
          <>
            <Link to="/admin/clients"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-3.5 w-3.5" /> Retour</Button></Link>
            <StatusBadge status={client.is_active ? 'active' : 'inactive'} />
          </>
        }
      />

      <Card className="p-6">
        <div className="flex items-end justify-between gap-4">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-fg-3">Budget mensuel</p>
            {budget.limit_usd != null ? (
              <p className="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-fg">
                {formatMoney(budget.remaining_usd ?? 0)}
                <span className="ml-2 text-[13px] font-normal text-fg-3">restant sur {formatMoney(budget.limit_usd)}</span>
              </p>
            ) : (
              <p className="mt-2 text-sm text-fg-3">Aucun budget mensuel défini pour ce client.</p>
            )}
          </div>
          {budget.limit_usd != null && (
            <span className={`text-[13px] font-medium tabular-nums ${overBudget ? 'text-danger' : 'text-fg-2'}`}>{formatMoney(budget.spend_usd ?? 0)} utilisés</span>
          )}
        </div>
        {budget.limit_usd != null && (
          <>
            <div className="mt-5 h-3 w-full overflow-hidden rounded-full bg-line">
              <div className="h-full rounded-full transition-all duration-500" style={{ width: `${pct}%`, backgroundColor: overBudget ? '#f04444' : 'var(--color-accent)' }} />
            </div>
            <p className="mt-2 text-[11.5px] text-fg-3">Période du {budget.period_start || '—'}</p>
          </>
        )}
      </Card>

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2">
          <h3 className="text-[15px] font-semibold text-fg">Détails de connexion</h3>
          <div className="mt-3 divide-y divide-line">
            <DetailItem label="Hôte de la base" value={client.analytics_db_host ?? '—'} mono />
            <DetailItem label="Port" value={client.analytics_db_port ?? '—'} mono />
            <DetailItem label="Base de données" value={client.analytics_db_name ?? '—'} mono />
            <DetailItem label="DSN" value={client.analytics_db_dsn ?? '—'} mono />
            <DetailItem label="Créé" value={client.created_at ? new Date(client.created_at).toLocaleDateString() : '—'} />
          </div>
        </Card>

        <div className="space-y-4">
          <Card className="p-5">
            <h3 className="text-[15px] font-semibold text-fg">Authentification de l'instance</h3>
            <div className="mt-3 divide-y divide-line">
              <DetailItem label="Statut" value={client.public_key ? <span className="inline-flex items-center gap-1 text-success"><Check className="h-3.5 w-3.5" /> Clé enregistrée</span> : <span className="text-warning">Aucune clé configurée</span>} />
              {client.public_key && <DetailItem label="Clé publique" value={`${client.public_key.slice(0, 12)}…`} mono />}
            </div>
            <div className="mt-4 flex gap-2">
              <Button variant="secondary" size="sm" onClick={() => { setShowKeyPassword(true); setKeyPassword(''); }}>
                {client.public_key ? 'Régénérer les clés' : 'Générer les clés'}
              </Button>
            </div>
          </Card>
          <StatCard label="Utilisateurs du personnel" value={usersMeta.total ?? 0} icon={<Users className="h-5 w-5" />} />
        </div>
      </div>

      <div className={`mt-6 overflow-hidden rounded-[18px] border border-line bg-white shadow-[0_2px_10px_rgba(48,54,77,0.05)] ${list.dimClass}`}>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-semibold text-fg">Utilisateurs et permissions</h3>
          <Button variant="secondary" size="sm" onClick={openSync}>
            <RefreshCw className="mr-1 h-3.5 w-3.5" /> Synchroniser
          </Button>
        </div>
        <div className="flex flex-wrap items-center gap-3 border-b border-line bg-base/40 px-5 py-3">
          <SearchInput
            value={list.filters.q ?? ''}
            onChange={(v) => list.setFilter('q', v)}
            placeholder="Rechercher par nom, identifiant ou service…"
            className="w-full max-w-[320px]"
          />
          <FilterSelect
            label="Rôle"
            value={list.filters.role ?? ''}
            onChange={(v) => list.setFilter('role', v)}
            options={[
              { value: '1', label: 'Administrateur' },
              { value: '0', label: 'Gestionnaire' },
            ]}
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
        {users.length === 0 ? (
          <TableEmpty title="Aucun membre du personnel" description="Aucun membre du personnel n'est associé à ce client." />
        ) : (
          <Table>
            <thead><tr><Th>Utilisateur</Th><Th>Rôle</Th><Th>Permissions</Th><Th>Coût</Th><Th>Dernière synchro</Th><Th className="text-right">Accès</Th></tr></thead>
            <tbody>
              {users.map((u) => (
                <Tr key={u.id}>
                  <Td>
                    <div className="font-medium text-fg">{u.name || '—'}</div>
                    <div className="text-xs font-mono text-fg-3">{u.username}{u.department ? ` · ${u.department}` : ''}</div>
                  </Td>
                  <Td>
                    <Badge variant={ROLE_META[u.role]?.variant || 'neutral'}>{ROLE_META[u.role]?.label || 'Utilisateur'}</Badge>
                  </Td>
                  <Td>
                    {u.permissions.length === 0 ? <span className="text-fg-3">—</span> : (
                      <div className="flex max-w-[300px] flex-wrap gap-1.5">
                        {u.permissions.map((p) => <Badge key={p.code} variant={p.is_active ? 'accent' : 'neutral'}>{p.name}</Badge>)}
                      </div>
                    )}
                  </Td>
                  <Td className="tabular-nums text-fg-2">{formatCost(u.usage?.cost_usd ?? 0)}</Td>
                  <Td className="text-fg-3">{u.last_synced_at ? new Date(u.last_synced_at).toLocaleDateString() : '—'}</Td>
                  <Td className="text-right">
                    <Toggle
                      checked={!u.deactivated_at}
                      disabled={accessBusy === u.id}
                      onChange={(v) => toggleUserAccess(u, v)}
                      label="Actif"
                    />
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
        {usersMeta.total > users.length && (
          <div className="border-t border-line px-5 py-3">
            <Pagination
              page={usersMeta.page}
              perPage={usersMeta.per_page}
              total={usersMeta.total}
              onChange={list.setPage}
              onPerPageChange={(n) => { list.setPerPage(n); list.setPage(1); }}
            />
          </div>
        )}
      </div>

      <Modal open={syncOpen} title={`Synchronisation des utilisateurs — ${client.name ?? ''}`} size="lg"
        onClose={closeSync}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={closeSync}>Fermer</Button>
            <Button variant="primary" size="sm" onClick={runSync} disabled={syncLoading}>
              <RefreshCw className="mr-1 h-3.5 w-3.5" /> {syncLoading ? 'Traitement…' : 'Découvrir et synchroniser'}
            </Button>
          </>
        }
      >
        {syncLoading ? (
          <LoadingState label="Découverte des utilisateurs de l'hôtel…" />
        ) : (
          <div>
            {syncSummary && (
              <div className="mb-3 flex flex-wrap gap-2 text-xs">
                <span className="rounded-full border border-line px-2 py-0.5 text-fg-2">{syncSummary.users_live} en ligne</span>
                <span className="rounded-full border border-line px-2 py-0.5 text-fg-2">{syncSummary.users_local} locaux</span>
                {syncSummary.new > 0 && <span className="rounded-full bg-accent/15 px-2 py-0.5 text-accent-fg">{syncSummary.new} nouveaux</span>}
                {syncSummary.changed > 0 && <span className="rounded-full bg-warning/15 px-2 py-0.5 text-warning">{syncSummary.changed} modifiés</span>}
                {syncSummary.conflicts > 0 && <span className="rounded-full bg-danger/15 px-2 py-0.5 text-danger">{syncSummary.conflicts} conflits</span>}
              </div>
            )}
            {syncUsers.length === 0 ? (
              <TableEmpty title="Aucun utilisateur découvert" description="Aucun utilisateur de l'hôtel ne correspond aux critères de découverte." />
            ) : (
              <div className="overflow-hidden rounded-lg border border-line">
                <table className="w-full text-left text-[12.5px]">
                  <thead className="bg-base text-fg-3">
                    <tr><Th>Identifiant</Th><Th>Nom</Th><Th>Service</Th><Th>Rôle</Th><Th>Statut</Th></tr>
                  </thead>
                  <tbody>
                    {syncUsers.map((u, i) => (
                      <Tr key={i}>
                        <Td className="font-mono">{u.username}</Td>
                        <Td>{u.name}</Td>
                        <Td>{u.department || '—'}</Td>
                        <Td>{u.permissions?.role === 1 ? 'Admin' : 'Gestionnaire'}</Td>
                        <Td><StatusBadge status={u.status} /></Td>
                      </Tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </Modal>

      <Modal open={!!generatedKey} title="Clé privée de l'instance" size="md"
        onClose={() => setGeneratedKey(null)}
        footer={<Button variant="primary" size="sm" onClick={() => setGeneratedKey(null)}>J'ai bien noté la clé</Button>}
      >
        <div className="rounded-lg border border-warning/40 bg-warning/10 p-3">
          <p className="text-[13px] font-medium text-warning">Enregistrez cette clé privée — elle ne sera PLUS jamais affichée.</p>
          <p className="mt-1 text-[12px] text-fg-2">Définissez-la dans la variable d'environnement <code className="rounded bg-base px-1 py-0.5 font-mono text-[11px]">CLIENT_PRIVATE_KEY</code> de l'instance.</p>
        </div>
        <div className="mt-3 flex items-center gap-2 rounded-lg border border-line bg-base px-3 py-2.5">
          <code className="flex-1 break-all font-mono text-[12px] text-accent-fg">{generatedKey?.private_key}</code>
        </div>
        <div className="mt-3 flex gap-2">
          <Button variant={copied ? 'primary' : 'secondary'} size="sm" onClick={handleCopyKey}>
            {copied ? <Check className="mr-1.5 h-3.5 w-3.5" /> : <Copy className="mr-1.5 h-3.5 w-3.5" />}
            {copied ? 'Copié !' : 'Copier'}
          </Button>
          <Button variant="secondary" size="sm" onClick={() => downloadText(`client-${id}-private-key.txt`, generatedKey?.private_key || '')}>
            <Download className="mr-1.5 h-3.5 w-3.5" /> Exporter en fichier
          </Button>
        </div>
      </Modal>

      <Modal open={showKeyPassword} title={client?.public_key ? "Régénérer les clés d'instance ?" : "Générer les clés d'instance ?"} size="sm"
        onClose={() => { if (!keyBusy) { setShowKeyPassword(false); setKeyPassword(''); } }}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => { setShowKeyPassword(false); setKeyPassword(''); }} disabled={keyBusy}>Annuler</Button>
            <Button variant={client?.public_key ? 'danger' : 'primary'} size="sm" disabled={!keyPassword || keyBusy} onClick={async () => {
              setKeyBusy(true);
              try {
                const keys = await adminService.generateKeys(id, keyPassword);
                client.public_key = keys.public_key;
                setGeneratedKey(keys);
                setShowKeyPassword(false);
                setKeyPassword('');
              } catch (e) { fail(e); }
              setKeyBusy(false);
            }}>
              {keyBusy ? 'Génération…' : (client?.public_key ? 'Régénérer' : 'Générer')}
            </Button>
          </>
        }
      >
        <p className="text-[13px] text-fg-2">
          {client?.public_key
            ? `Cela créera une nouvelle paire de clés. L'actuelle clé privée dans le .env de l'instance deviendra invalide.`
            : `Cela générera une nouvelle paire de clés Ed25519. La clé publique sera enregistrée automatiquement.`}
        </p>
        <p className="mt-2 text-[12px] text-fg-3">Saisissez votre mot de passe administrateur pour confirmer.</p>
        {keyBusy ? (
          <p className="mt-3 text-[12px] text-fg-3">Génération de la paire de clés…</p>
        ) : (
          <TextInput className="mt-3 w-full" type="password" value={keyPassword} onChange={(e) => setKeyPassword(e.target.value)} placeholder="Votre mot de passe administrateur" />
        )}
      </Modal>
    </div>
  );
}