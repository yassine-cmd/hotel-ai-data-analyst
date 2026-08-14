import { useEffect, useMemo, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { adminService } from '../services/adminService';
import Modal from '../../shared/components/ui/Modal';
import PageHeader from '../../shared/components/ui/PageHeader';
import ConfirmDialog from '../../shared/components/ui/ConfirmDialog';
import Button from '../../shared/components/ui/Button';
import Toggle from '../../shared/components/ui/Toggle';
import Field, { TextInput, TextArea } from '../../shared/components/ui/Field';
import StatusBadge from '../../shared/components/ui/StatusBadge';
import { Search, CheckSquare, Square, Lock, Pencil, Trash2 } from 'lucide-react';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import EmptyState from '../../shared/components/ui/EmptyState';
import { LoadingState } from '../../shared/components/ui/Spinner';

const emptyForm = { code: '', name: '', description: '', is_active: true };

const list = (v, k) => (v && Array.isArray(v[k]) ? v[k] : []);

export default function PermissionTokensPage() {
  const { notify } = useOutletContext();
  const [tokens, setTokens] = useState([]);
  const [tables, setTables] = useState([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('');
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState(emptyForm);

  const [grants, setGrants] = useState({});
  const [search, setSearch] = useState('');
  const [sensitiveTables, setSensitiveTables] = useState([]);
  const [columnsByTable, setColumnsByTable] = useState({});
  const [colsExpanded, setColsExpanded] = useState({});

  const fail = (e) => notify({ variant: 'error', message: e?.message || 'Une erreur est survenue' });
  const success = (m) => notify({ variant: 'success', message: m });

  const load = () => {
    setLoading(true);
    adminService.listPermissionTokens()
      .then((d) => setTokens(list(d, 'data')))
      .catch((e) => setStatus('Erreur : ' + e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  useEffect(() => {
    adminService.listMetadata({ type: 'table' })
      .then((d) => {
        const rows = list(d, 'metadata');
        setTables(rows.map((m) => m.table_name));
        setSensitiveTables(rows.filter((m) => m.is_sensitive).map((m) => m.table_name));
      })
      .catch(() => {});
    adminService.listMetadata({ type: 'column' })
      .then((d) => {
        const rows = list(d, 'metadata');
        const grouped = {};
        rows.forEach((m) => {
          const t = m.table_name;
          if (!grouped[t]) grouped[t] = [];
          grouped[t].push({ name: m.column_name, sensitive: !!m.is_sensitive });
        });
        setColumnsByTable(grouped);
      })
      .catch(() => {});
  }, []);

  const q = search.trim().toLowerCase();
  const filtered = useMemo(
    () => tables.filter((t) => t.toLowerCase().includes(q)),
    [tables, q],
  );
  const grantedCount = Object.keys(grants).length;

  const isSensitive = (t) => sensitiveTables.some((s) => s.toLowerCase() === t.toLowerCase());

  // Non-sensitive columns currently granted for a table: all of them when the
  // table is granted as "*", or the explicit list when granted as {columns:[...]}.
  const grantedCols = (t) => {
    const all = (columnsByTable[t] || []).filter((c) => !c.sensitive).map((c) => c.name);
    const g = grants[t];
    if (g === '*' || g === undefined) return all;
    if (g && Array.isArray(g.columns)) {
      const set = new Set(g.columns.map((c) => String(c).toLowerCase()));
      return all.filter((c) => set.has(String(c).toLowerCase()));
    }
    return [];
  };

  const toggleColumn = (t, col) => {
    setGrants((g) => {
      const next = { ...g };
      const all = (columnsByTable[t] || []).filter((c) => !c.sensitive).map((c) => c.name);
      const cur = next[t];
      let selected = cur === '*' ? [...all] : (cur && Array.isArray(cur.columns) ? [...cur.columns] : []);
      const lower = selected.map((c) => String(c).toLowerCase());
      if (lower.includes(String(col).toLowerCase())) {
        selected = selected.filter((c) => String(c).toLowerCase() !== String(col).toLowerCase());
      } else {
        selected = [...selected, col];
      }
      if (selected.length === all.length) next[t] = '*';
      else if (selected.length === 0) delete next[t];
      else next[t] = { columns: selected };
      return next;
    });
  };

  const toggleColumnsExpanded = (t) => setColsExpanded((e) => ({ ...e, [t]: !e[t] }));

  const openCreate = () => {
    setEditing({});
    setForm({ ...emptyForm });
    setGrants({});
  };
  const openEdit = (v) => {
    setEditing(v);
    setForm({
      code: v.code,
      name: v.name,
      description: v.description || '',
      is_active: v.is_active,
    });
    setGrants(JSON.parse(JSON.stringify(v.grants?.tables || {})));
  };

  const toggleTable = (t) => {
    if (isSensitive(t)) return;
    setGrants((g) => {
      const next = { ...g };
      if (next[t]) delete next[t];
      else next[t] = '*';
      return next;
    });
  };

  const selectAllFiltered = () =>
    setGrants((g) => {
      const next = { ...g };
      filtered.forEach((t) => { if (!isSensitive(t) && !next[t]) next[t] = '*'; });
      return next;
    });
  const clearFiltered = () =>
    setGrants((g) => {
      const next = { ...g };
      filtered.forEach((t) => delete next[t]);
      return next;
    });

  const save = async () => {
    const code = form.code.trim();
    if (!code || !form.name.trim()) { fail('Le code et le nom sont requis.'); return; }
    if (!/^[A-Za-z0-9_]+$/.test(code)) { fail('Le code ne peut contenir que des lettres, des chiffres et des tirets bas.'); return; }
    setSaving(true);
    setStatus('Enregistrement…');
    try {
      const payload = {
        code,
        name: form.name.trim(),
        description: form.description?.trim() || null,
        is_active: form.is_active,
        grants: { tables: grants },
      };
      const editingId = editing?.id;
      const res = editingId
        ? await adminService.updatePermissionToken(editingId, payload)
        : await adminService.createPermissionToken(payload);
      if (!res || !res.data) throw new Error((res && (res.message || res.error)) || 'Échec de l\u2019enregistrement');
      setEditing(null);
      load();
      success(editingId ? 'Jeton de permission mis à jour' : 'Jeton de permission créé');
    } catch (e) {
      fail(e);
    } finally {
      setSaving(false);
      setStatus('');
    }
  };

  const remove = async (v) => {
    try {
      const res = await adminService.deletePermissionToken(v.id);
      if (res && (res.error || res.message)) throw new Error(res.error || res.message);
      load();
      success('Jeton de permission supprimé');
    } catch (e) { fail(e); }
    finally { setDeleting(null); }
  };

  if (loading && !tokens.length) return <LoadingState label="Chargement des jetons de permission…" />;

  return (
    <div>
      <PageHeader
        title="Jetons de permission"
        description="Définissez les tables auxquelles chaque jeton de permission peut accéder. Un utilisateur détenant un jeton hérite immédiatement de ses autorisations — recherchez une table, cochez-la, enregistrez."
        actions={<Button variant="primary" size="sm" onClick={openCreate}>Nouveau jeton</Button>}
      />
      <div className="overflow-hidden rounded-xl border border-line bg-raised">
        {!tokens.length ? (
          <TableEmpty title="Aucun jeton de permission" description="Créez-en un pour commencer à accorder l'accès aux tables." />
        ) : (
          <Table>
            <thead><tr><Th>Code</Th><Th>Nom</Th><Th>Description</Th><Th>Tables accordées</Th><Th>Active</Th><Th>Actions</Th></tr></thead>
            <tbody>
              {tokens.map((v) => {
                const count = Object.keys(v.grants?.tables || {}).length;
                const restrictedCount = Object.values(v.grants?.tables || {}).filter(
                  (g) => g && typeof g === 'object' && Array.isArray(g.columns)
                ).length;
                return (
                  <Tr key={v.id}>
                    <Td><span className="font-mono text-[12.5px]">{v.code}</span></Td>
                    <Td>{v.name}</Td>
                    <Td>
                      {v.description
                        ? <span className="block max-w-[380px] truncate text-[12.5px] text-fg-3" title={v.description}>{v.description}</span>
                        : <span className="text-fg-3">—</span>}
                    </Td>
                    <Td>
                      <span className="inline-flex items-center gap-1.5 text-[12.5px] text-fg-2">
                        <span className="rounded-full bg-[#43A7BA]/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-[#43A7BA]">{count}</span>
                        <span>{count ? 'tables' : 'aucune'}</span>
                        {restrictedCount > 0 && (
                          <span className="font-mono text-[11px] font-semibold text-[#43A7BA]">· {restrictedCount} avec colonnes restreintes</span>
                        )}
                      </span>
                    </Td>
                    <Td><StatusBadge status={v.is_active ? 'active' : 'inactive'} /></Td>
                    <Td>
                      <div className="flex items-center gap-1.5">
                        <Button variant="ghost" size="sm" onClick={() => openEdit(v)} title="Modifier" aria-label="Modifier"><Pencil className="h-3.5 w-3.5" /></Button>
                        <Button variant="danger" size="sm" onClick={() => setDeleting(v)} title="Supprimer" aria-label="Supprimer"><Trash2 className="h-3.5 w-3.5" /></Button>
                      </div>
                    </Td>
                  </Tr>
                );
              })}
            </tbody>
          </Table>
        )}
      </div>
      {status && <p className="mt-2 text-xs text-fg-3">{status}</p>}

      <Modal
        open={!!editing}
        title={editing?.id ? 'Modifier le jeton' : 'Nouveau jeton'}
        size="lg"
        onClose={() => setEditing(null)}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>Annuler</Button>
            <Button variant="primary" size="sm" onClick={save} disabled={saving}>{saving ? 'Enregistrement…' : 'Enregistrer'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Code" htmlFor="pt-code" hint="Clé unique, p. ex. RECEPTION">
              <TextInput id="pt-code" className="font-mono uppercase" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} placeholder="RECEPTION" required />
            </Field>
            <Field label="Nom" htmlFor="pt-name" hint="Libellé affiché pour ce jeton">
              <TextInput id="pt-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Réception" required />
            </Field>
          </div>
          <Field label="Description" htmlFor="pt-desc" hint="(optionnel)">
            <TextArea id="pt-desc" rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Quel rôle ce jeton représente-t-il ?" />
          </Field>
          <div><Toggle checked={form.is_active} onChange={(checked) => setForm({ ...form, is_active: checked })} label="Active" /></div>

          <div className="rounded-xl border border-line bg-base p-3.5">
            <div className="flex items-center justify-between gap-3">
              <p className="text-[12px] font-semibold text-fg">Accorder des tables à ce jeton</p>
              <span className="rounded-full bg-fg-3/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-fg-3">{grantedCount} accordée{grantedCount === 1 ? '' : 's'}</span>
            </div>
            <div className="mt-3 flex items-center gap-2">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-fg-3" />
                <input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Rechercher des tables…"
                  className="w-full rounded-[10px] border border-line bg-white py-2 pl-8 pr-3 text-[13px] outline-none focus:border-[#43A7BA] focus:ring-2 focus:ring-[#43A7BA]/10"
                />
              </div>
              <button type="button" onClick={selectAllFiltered} className="flex items-center gap-1 rounded-[10px] border border-line bg-white px-2.5 py-2 text-[12px] font-medium text-fg-2 hover:bg-fg-3/5"><CheckSquare className="h-3.5 w-3.5" />Tout</button>
              <button type="button" onClick={clearFiltered} className="flex items-center gap-1 rounded-[10px] border border-line bg-white px-2.5 py-2 text-[12px] font-medium text-fg-2 hover:bg-fg-3/5"><Square className="h-3.5 w-3.5" />Effacer</button>
            </div>

            <div className="mt-3 max-h-[280px] overflow-y-auto rounded-lg border border-line bg-white">
              {filtered.map((t) => {
                const sens = isSensitive(t);
                const checked = !!grants[t];
                const cols = columnsByTable[t] || [];
                const granted = grantedCols(t);
                const restricted = checked && !sens && grants[t] !== '*';
                return (
                  <div key={t} className={`border-b border-line/60 last:border-b-0 ${sens ? 'bg-fg-3/5' : ''}`}>
                    <div className="flex items-center gap-2 px-3 py-1.5 hover:bg-fg-3/5">
                      <input
                        type="checkbox"
                        checked={checked}
                        disabled={sens}
                        onChange={() => toggleTable(t)}
                        title={sens ? 'Données réglementées — ne peuvent pas être accordées' : undefined}
                        className="h-3.5 w-3.5 accent-[#43A7BA] disabled:cursor-not-allowed disabled:opacity-40"
                      />
                      <span className={`flex-1 truncate font-mono text-[12.5px] ${sens ? 'text-fg-3/70' : 'text-fg-2'}`}>{t}</span>
                      {sens && (
                        <span title="Données personnelles réglementées — bloquées pour tout utilisateur, y compris les administrateurs. Ne peuvent pas être accordées." className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#f04444]/10 px-2 py-0.5 text-[10.5px] font-semibold text-[#f04444]">
                          <Lock className="h-2.5 w-2.5" />SENSIBLE
                        </span>
                      )}
                      {checked && !sens && (
                        <button
                          type="button"
                          onClick={() => toggleColumnsExpanded(t)}
                          className="shrink-0 rounded-full bg-[#43A7BA]/10 px-2 py-0.5 font-mono text-[10.5px] font-semibold text-[#43A7BA] hover:bg-[#43A7BA]/20"
                          title={restricted ? 'Certaines colonnes sont masquées' : 'Toutes les colonnes accordées'}
                        >
                          {restricted ? `${granted.length} col.` : 'toutes les col.'}
                        </button>
                      )}
                    </div>
                    {checked && !sens && colsExpanded[t] && (
                      <div className="ml-6 border-l border-line/60 py-1 pr-1 max-h-[180px] overflow-y-auto">
                        {cols.length === 0 && (
                          <p className="px-1 py-1 text-[11px] text-fg-3">Aucune métadonnée de colonne disponible.</p>
                        )}
                        {cols.map((c) => {
                          const colChecked = !c.sensitive && granted.includes(c.name);
                          return (
                            <label key={c.name} className="flex items-center gap-2 px-1 py-0.5 text-[12px] hover:bg-fg-3/5">
                              <input
                                type="checkbox"
                                checked={colChecked}
                                disabled={c.sensitive}
                                onChange={() => toggleColumn(t, c.name)}
                                className="h-3 w-3 accent-[#43A7BA] disabled:cursor-not-allowed disabled:opacity-40"
                              />
                              <span className={`flex-1 truncate font-mono ${c.sensitive ? 'text-fg-3/70' : 'text-fg-2'}`}>{c.name}</span>
                              {c.sensitive && (
                                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#f04444]/10 px-1.5 py-0.5 text-[10px] font-semibold text-[#f04444]">
                                  <Lock className="h-2 w-2" />SENS
                                </span>
                              )}
                            </label>
                          );
                        })}
                      </div>
                    )}
                  </div>
                );
              })}
              {!filtered.length && <EmptyState compact title={`Aucune table ne correspond à « ${search} »`} />}
            </div>
            <p className="mt-2 text-[11px] text-fg-3">Cochez une table pour l'accorder, puis développez-la pour restreindre à des colonnes précises. Les tables marquées <span className="font-semibold text-[#f04444]">SENSIBLE</span> contiennent des données personnelles réglementées et sont bloquées pour tout utilisateur — elles ne peuvent pas être accordées.</p>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        title="Supprimer le jeton"
        message={`Supprimer le jeton de permission « ${deleting?.code} » ? Les utilisateurs qui le détiennent perdront immédiatement ses autorisations. Cette action est irréversible.`}
        confirmLabel="Supprimer"
        danger
        onConfirm={() => deleting && remove(deleting)}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
