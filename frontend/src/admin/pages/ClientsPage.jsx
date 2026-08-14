import { useState } from 'react';
import { Link, useOutletContext } from 'react-router-dom';
import { ChevronRight, Check, Pencil, Ban, CircleCheck } from 'lucide-react';
import { adminService } from '../services/adminService';
import Modal from '../../shared/components/ui/Modal';
import PageHeader from '../../shared/components/ui/PageHeader';
import ConfirmDialog from '../../shared/components/ui/ConfirmDialog';
import Button from '../../shared/components/ui/Button';
import Field, { TextInput } from '../../shared/components/ui/Field';
import StatusBadge from '../../shared/components/ui/StatusBadge';
import SearchInput from '../../shared/components/ui/SearchInput';
import FilterSelect from '../../shared/components/ui/FilterSelect';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import { LoadingState } from '../../shared/components/ui/Spinner';
import useServerTable from '../../shared/hooks/useServerTable';
import { formatMoney } from '../../shared/utils/money';

export default function ClientsPage() {
  const { notify } = useOutletContext();
  const list = useServerTable({
    fetcher: (params) => adminService.listClients(params),
  });
  const clients = list.data ?? [];
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [deletePassword, setDeletePassword] = useState('');
  const [agentPassword, setAgentPassword] = useState('');
  const [form, setForm] = useState({ name: '', analytics_db_dsn: '', analytics_admin_user: '', analytics_admin_password: '', budget_limit_usd: '' });
  const [status, setStatus] = useState('');
  const [testResult, setTestResult] = useState(null);
  const [testing, setTesting] = useState(false);

  const fail = (e) => notify({ variant: 'error', message: e.message });

  const openCreate = () => {
    setEditing({});
    setForm({ name: '', analytics_db_dsn: '', analytics_admin_user: '', analytics_admin_password: '', budget_limit_usd: '' });
    setTestResult(null);
    setAgentPassword('');
  };
  const openEdit = async (c) => {
    try {
      const client = await adminService.getClient(c.id);
      setEditing(client);
      setForm({ name: client.name, analytics_db_dsn: client.analytics_db_dsn || '', analytics_admin_user: client.analytics_admin_user || '', analytics_admin_password: '', budget_limit_usd: client.budget_limit_usd ?? '' });
      setTestResult(null);
      setAgentPassword('');
    } catch (e) { fail(e); }
  };
  const save = async () => {
    setStatus('Enregistrement…');
    try {
      if (editing.id) {
        const payload = { ...form };
        if (!payload.analytics_admin_password) delete payload.analytics_admin_password;
        payload.budget_limit_usd = payload.budget_limit_usd === '' ? null : payload.budget_limit_usd;
        const result = await adminService.updateClient(editing.id, payload);
        if (result.client) setAgentPassword(result.agent_password || '');
      } else {
        const result = await adminService.createClient({ ...form });
        if (result.agent_password) setAgentPassword(result.agent_password);
      }
      setEditing(null);
      list.reload();
      setStatus('');
      notify({ variant: 'success', message: editing.id ? 'Client mis à jour' : 'Client créé' });
    } catch (e) { setStatus(''); fail(e); }
  };
  const toggleActive = async (c) => {
    try {
      if (c.is_active) {
        await adminService.deactivateClient(c.id);
        notify({ variant: 'success', message: 'Client désactivé' });
      } else {
        await adminService.reactivateClient(c.id);
        notify({ variant: 'success', message: 'Client réactivé' });
      }
      list.reload();
    } catch (e) { fail(e); }
  };
  const remove = async (c) => {
    try {
      await adminService.deleteClient(c.id, deletePassword);
      list.reload(); notify({ variant: 'success', message: 'Client supprimé' });
    }
    catch (e) { fail(e); }
    finally { setDeleting(null); setDeletePassword(''); }
  };
  const testConn = async () => {
    setTesting(true);
    setTestResult(null);
    try {
      if (editing?.id && !form.analytics_admin_password) {
        throw new Error('Saisissez le mot de passe admin de la base pour tester la connexion.');
      }
      const result = await adminService.testConnection(form.analytics_db_dsn, form.analytics_admin_user, form.analytics_admin_password);
      setTestResult(result);
    } catch (e) { setTestResult({ success: false, message: e.message }); }
    setTesting(false);
  };

  if (list.loading && !clients.length) return <LoadingState label="Chargement des clients…" />;

  return (
    <div>
      <PageHeader
        description="Gérer les connexions aux bases de données analytiques des clients."
        actions={<Button variant="primary" size="sm" onClick={openCreate}>Nouveau client</Button>}
      />

      <Modal open={!!agentPassword} title="Utilisateur agent créé" size="sm"
        onClose={() => setAgentPassword('')}
        footer={<Button variant="primary" size="sm" onClick={() => setAgentPassword('')}>J'ai bien noté le mot de passe</Button>}
      >
        <p className="text-[13px] leading-relaxed text-fg-2">L'utilisateur en lecture seule <strong className="text-fg">fms_agent</strong> a été créé sur la base de données analytique. Enregistrez ce mot de passe — il ne sera plus affiché.</p>
        <div className="mt-3 rounded-lg border border-line bg-base px-3 py-2.5">
          <code className="font-mono text-[13px] text-accent-fg">{agentPassword}</code>
        </div>
      </Modal>

      <div className="mb-3 flex flex-wrap items-center gap-3">
        <SearchInput
          value={list.filters.q ?? ''}
          onChange={(v) => list.setFilter('q', v)}
          placeholder="Rechercher par nom de client ou DSN…"
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

      <div className={`overflow-hidden rounded-xl border border-line bg-raised ${list.dimClass}`}>
        {clients.length === 0 ? (
          <TableEmpty title="Aucun client ne correspond à votre recherche" />
        ) : (
          <Table>
            <thead><tr><Th>ID client</Th><Th>Nom</Th><Th>DSN</Th><Th>Statut</Th><Th>Clé d'instance</Th><Th>Utilisateurs</Th><Th>Budget mensuel</Th><Th>Créé</Th><Th>Actions</Th></tr></thead>
            <tbody>
              {clients.map((c) => (
                <Tr key={c.id}>
                  <Td><span className="font-mono text-[12.5px]">{c.id}</span></Td>
                  <Td>
                    <Link to={`/admin/clients/${c.id}`} className="group flex items-center gap-1 font-medium text-fg hover:text-accent-fg">
                      {c.name}
                      <ChevronRight className="h-3.5 w-3.5 text-fg-3 opacity-0 transition-opacity group-hover:opacity-100" />
                    </Link>
                  </Td>
                  <Td className="max-w-[240px] truncate text-xs text-fg-3">{c.analytics_db_dsn || '—'}</Td>
                  <Td><StatusBadge status={c.is_active ? 'active' : 'inactive'} /></Td>
                  <Td>{c.public_key ? <span className="inline-flex items-center gap-1 text-[12px] text-success"><Check className="h-3.5 w-3.5" /> Clé définie</span> : <span className="text-[12px] text-warning">Aucune clé</span>}</Td>
                  <Td>{c.users_count ?? '—'}</Td>
                  <Td className="text-xs text-fg-3">
                    {c.budget_limit_usd != null
                      ? `${formatMoney(c.budget_limit_usd)}${c.month_spend_usd != null ? ` · ${formatMoney(c.month_spend_usd)} utilisés` : ''}`
                      : '—'}
                  </Td>
                  <Td className="text-fg-3">{c.created_at ? new Date(c.created_at).toLocaleDateString() : '—'}</Td>
                  <Td>
                    <div className="flex items-center gap-1.5">
                      <Button variant="ghost" size="sm" onClick={() => openEdit(c)} title="Modifier" aria-label="Modifier"><Pencil className="h-3.5 w-3.5" /></Button>
                      <Button variant={c.is_active ? 'danger' : 'primary'} size="sm" onClick={() => toggleActive(c)} title={c.is_active ? 'Désactiver' : 'Réactiver'} aria-label={c.is_active ? 'Désactiver' : 'Réactiver'}>{c.is_active ? <Ban className="h-3.5 w-3.5" /> : <CircleCheck className="h-3.5 w-3.5" />}</Button>
                    </div>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
      </div>

      <Modal open={!!editing} title={editing?.id ? 'Modifier le client' : 'Nouveau client'} onClose={() => setEditing(null)} size="lg"
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>Annuler</Button>
            <Button variant="primary" size="sm" onClick={save}>{editing?.id ? 'Mettre à jour' : 'Créer'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Nom affiché" htmlFor="client-name">
            <TextInput id="client-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </Field>
          <Field label="DSN de la base analytique" htmlFor="client-dsn" hint="host:3306/nom_bdd ou mysql://user@host:3306/nom_bdd">
            <div className="flex gap-2">
              <TextInput id="client-dsn" value={form.analytics_db_dsn} onChange={(e) => setForm({ ...form, analytics_db_dsn: e.target.value })} className="flex-1" />
              <Button variant="secondary" size="sm" onClick={testConn} disabled={testing}>{testing ? 'Test en cours…' : 'Tester'}</Button>
            </div>
          </Field>
          {testResult && (
            <p className={`text-[12.5px] ${testResult.success ? 'text-success' : 'text-danger'}`}>{testResult.message}</p>
          )}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Utilisateur admin de la base" htmlFor="client-db-user">
              <TextInput id="client-db-user" value={form.analytics_admin_user} onChange={(e) => setForm({ ...form, analytics_admin_user: e.target.value })} />
            </Field>
            <Field label="Mot de passe admin de la base" htmlFor="client-db-pass" hint={editing?.id ? 'Laisser vide pour conserver' : undefined}>
              <TextInput id="client-db-pass" type="password" value={form.analytics_admin_password} onChange={(e) => setForm({ ...form, analytics_admin_password: e.target.value })} placeholder={editing?.id ? 'Laisser vide pour conserver' : ''} />
            </Field>
          </div>
          <Field label="Budget mensuel (USD)" htmlFor="client-budget" hint="Plafond d'utilisation mensuel. Laissez vide pour aucune limite.">
            <TextInput id="client-budget" type="number" min="0" step="0.01" value={form.budget_limit_usd} onChange={(e) => setForm({ ...form, budget_limit_usd: e.target.value })} placeholder="p. ex. 250,00" />
          </Field>

          {editing?.id && (
            <div className="rounded-lg border border-danger/30 bg-danger/5 p-3">
              <Button variant="danger" size="sm" onClick={() => { setDeleting(editing); setDeletePassword(''); }}>
                Supprimer définitivement…
              </Button>
            </div>
          )}
          {status && <p className="text-xs text-fg-3">{status}</p>}
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        title="Supprimer définitivement le client"
        message={`Saisissez votre mot de passe administrateur pour confirmer. Cela supprime "${deleting?.name}", ses utilisateurs, et retire l'utilisateur en lecture seule de la base. Cette action est irréversible.`}
        confirmLabel="Supprimer définitivement"
        danger
        confirmDisabled={!deletePassword}
        onConfirm={() => deleting && remove(deleting)}
        onClose={() => { setDeleting(null); setDeletePassword(''); }}
      >
        <TextInput
          className="mt-3 w-full"
          type="password"
          value={deletePassword}
          onChange={(e) => setDeletePassword(e.target.value)}
          placeholder="Votre mot de passe administrateur"
        />
      </ConfirmDialog>
    </div>
  );
}
