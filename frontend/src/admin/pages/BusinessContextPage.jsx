import { useEffect, useMemo, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { Pencil, Trash2 } from 'lucide-react';
import { adminService } from '../services/adminService';
import Modal from '../../shared/components/ui/Modal';
import PageHeader from '../../shared/components/ui/PageHeader';
import ConfirmDialog from '../../shared/components/ui/ConfirmDialog';
import Button from '../../shared/components/ui/Button';
import Toggle from '../../shared/components/ui/Toggle';
import Field, { TextInput, TextArea, Select } from '../../shared/components/ui/Field';
import StatusBadge from '../../shared/components/ui/StatusBadge';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import { LoadingState } from '../../shared/components/ui/Spinner';

const emptyForm = {
  title: '',
  content: '',
  scope_table: '',
  scope_column: '',
  is_active: true,
};

// Fallbacks until /api/admin/business-context/config resolves. The served
// values are the source of truth (kept in sync with
// laravel/config/business_context.php and agent/core.py::_format_business_context).
const DEFAULT_LIMITS = { title_max: 200, content_max: 5000, total_max: 6000 };
const HEADER_LEN = 18;

const renderLen = (title, content) => {
  const t = (title || '').trim();
  const c = (content || '').trim();
  if (!t || !c) return 0;
  return `- ${t}: ${c}`.length + 1;
};

export default function BusinessContextPage() {
  const { notify } = useOutletContext();
  const [entries, setEntries] = useState([]);
  const [tables, setTables] = useState([]);
  const [columns, setColumns] = useState([]);
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [expandedId, setExpandedId] = useState(null);
  const [limits, setLimits] = useState(DEFAULT_LIMITS);

  const load = () => {
    setLoading(true);
    adminService.listBusinessContext()
      .then(setEntries)
      .catch((e) => setStatus('Erreur : ' + e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  useEffect(() => {
    adminService.getBusinessContextConfig()
      .then(setLimits)
      .catch(() => {});
  }, []);

  useEffect(() => {
    adminService.listMetadata({ type: 'table' })
      .then((d) => setTables((d.metadata || []).map((m) => m.table_name)))
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (!form.scope_table) { setColumns([]); return; }
    adminService.listMetadata({ type: 'column', table_name: form.scope_table })
      .then((d) => setColumns((d.metadata || []).map((m) => m.column_name)))
      .catch(() => {});
  }, [form.scope_table]);

  const fail = (e) => notify({ variant: 'error', message: e.message });

  const openCreate = () => {
    setEditing({});
    setForm({ ...emptyForm });
  };
  const openEdit = (v) => {
    setEditing(v);
    setForm({
      title: v.title,
      content: v.content,
      scope_table: v.scope_table || '',
      scope_column: v.scope_column || '',
      is_active: v.is_active,
    });
  };
  const save = async () => {
    setStatus('Enregistrement…');
    try {
      const payload = {
        title: form.title,
        content: form.content,
        scope_table: form.scope_table || null,
        scope_column: form.scope_column || null,
        is_active: form.is_active,
      };
      if (editing.id) {
        await adminService.updateBusinessContext(editing.id, payload);
      } else {
        await adminService.createBusinessContext(payload);
      }
      setEditing(null);
      load();
      setStatus('');
      notify({ variant: 'success', message: editing.id ? 'Entrée mise à jour' : 'Entrée créée' });
    } catch (e) { setStatus(''); fail(e); }
  };
  const remove = async (v) => {
    try { await adminService.deleteBusinessContext(v.id); load(); notify({ variant: 'success', message: 'Entrée supprimée' }); }
    catch (e) { fail(e); }
    finally { setDeleting(null); }
  };

  const preview = useMemo(() => {
    if (!form.title.trim() || !form.content.trim()) return null;
    let text = `- ${form.title.trim()}: ${form.content.trim()}`;
    if (form.scope_table) text += ` [applies to: ${form.scope_table}${form.scope_column ? '.' + form.scope_column : ''}]`;
    return text;
  }, [form.title, form.content, form.scope_table, form.scope_column]);

  const globalTotal = useMemo(() => {
    const other = (entries || [])
      .filter((e) => e.is_active && !e.scope_table && e.id !== editing?.id)
      .reduce((s, e) => s + renderLen(e.title, e.content), 0);
    return HEADER_LEN + other + renderLen(form.title, form.content);
  }, [entries, editing, form.title, form.content]);

  const overTotal = globalTotal > limits.total_max;

  if (loading && !entries.length) return <LoadingState label="Chargement du contexte métier…" />;

  return (
    <div>
      <PageHeader
        description="Règles métier, termes du glossaire et notes qui rendent l'agent attentif au métier. Les entrées sans portée sont toujours visibles ; les entrées liées à une table apparaissent lorsque cette table est interrogée."
        actions={<Button variant="primary" size="sm" onClick={openCreate}>Nouvelle entrée</Button>}
      />
      <div className="overflow-hidden rounded-xl border border-line bg-raised">
        {!entries.length ? (
          <TableEmpty title="Aucun contexte métier" description="Ajoutez des règles et des termes de glossaire pour rendre l'agent attentif au métier." />
        ) : (
          <Table>
            <thead><tr><Th>Titre</Th><Th>Contenu</Th><Th>Portée</Th><Th>Active</Th><Th>Actions</Th></tr></thead>
            <tbody>
              {entries.map((v) => (
                <Tr key={v.id}>
                  <Td><span className="font-mono text-[12.5px]">{v.title}</span></Td>
                  <Td>
                    <button
                      type="button"
                      role="button"
                      tabIndex={0}
                      className={`block max-w-[420px] truncate text-left text-[12.5px] leading-relaxed text-fg-3 hover:text-fg-2 ${expandedId === v.id ? 'whitespace-normal' : ''}`}
                      title={expandedId === v.id ? undefined : v.content}
                      onClick={() => setExpandedId(expandedId === v.id ? null : v.id)}
                      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setExpandedId(expandedId === v.id ? null : v.id); } }}
                    >
                      {v.content}
                    </button>
                  </Td>
                  <Td className="text-xs text-fg-3">
                    {v.scope_table ? <span className="font-mono">{v.scope_table}{v.scope_column ? '.' + v.scope_column : ''}</span> : <span>— global —</span>}
                  </Td>
                  <Td><StatusBadge status={v.is_active ? 'active' : 'inactive'} /></Td>
                  <Td>
                    <div className="flex items-center gap-1.5">
                      <Button variant="ghost" size="sm" onClick={() => openEdit(v)} title="Modifier" aria-label="Modifier"><Pencil className="h-3.5 w-3.5" /></Button>
                      <Button variant="danger" size="sm" onClick={() => setDeleting(v)} title="Supprimer" aria-label="Supprimer"><Trash2 className="h-3.5 w-3.5" /></Button>
                    </div>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
      </div>

      <Modal open={!!editing} title={editing?.id ? 'Modifier l\u2019entrée' : 'Nouvelle entrée'} onClose={() => setEditing(null)} size="lg"
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>Annuler</Button>
            <Button variant="primary" size="sm" onClick={save} disabled={overTotal}>Enregistrer</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Titre" htmlFor="bc-title" hint={`${form.title.length} / ${limits.title_max} caractères`}>
            <TextInput id="bc-title" maxLength={limits.title_max} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="ex. : Politique no-show" required />
          </Field>
          <Field label="Contenu" htmlFor="bc-content" hint={`${form.content.length} / ${limits.content_max} caractères — rédigez-le comme vous l'expliqueriez à un collègue`}>
            <TextArea id="bc-content" rows={4} maxLength={limits.content_max} value={form.content} onChange={(e) => setForm({ ...form, content: e.target.value })} placeholder="Écrivez librement : les règles métier, les conventions et les particularités que l'agent doit connaître..." required />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="S'applique à la table" htmlFor="bc-table" hint="(optionnel — laissez vide pour une note générale)">
              <Select id="bc-table" value={form.scope_table} onChange={(e) => setForm({ ...form, scope_table: e.target.value, scope_column: '' })}>
                <option value="">— Aucune (note générale) —</option>
                {tables.map((t) => <option key={t} value={t}>{t}</option>)}
              </Select>
            </Field>
            <Field label="Colonne" htmlFor="bc-column" hint="(optionnel)">
              <Select id="bc-column" value={form.scope_column} onChange={(e) => setForm({ ...form, scope_column: e.target.value })} disabled={!form.scope_table}>
                <option value="">— Toutes les colonnes —</option>
                {columns.map((c) => <option key={c} value={c}>{c}</option>)}
              </Select>
            </Field>
          </div>
          <div className="flex items-center gap-2.5">
            <Toggle checked={form.is_active} onChange={(checked) => setForm({ ...form, is_active: checked })} label="Active" />
          </div>
          <div className={`flex items-center gap-1.5 text-[11px] ${overTotal ? 'text-danger' : 'text-fg-3'}`}>
            <span>Contexte global (toutes les notes générales actives) :</span>
            <span className="font-mono">{globalTotal} / {limits.total_max}</span>
            {overTotal && <span>— dépassement du budget, réduisez ou désactivez des entrées</span>}
          </div>
          {preview && (
            <div className="rounded-lg border border-line bg-base p-3">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-fg-3">Ce que l'agent lira :</p>
              <pre className="mt-2 overflow-x-auto font-mono text-[12px] leading-relaxed text-fg-2">{`[BUSINESS CONTEXT]\n${preview}\n[/BUSINESS CONTEXT]`}</pre>
            </div>
          )}
          {status && <p className="text-xs text-fg-3">{status}</p>}
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        title="Supprimer l\u2019entrée"
        message={`Supprimer l\u2019entrée de contexte métier « ${deleting?.title} » ? Cette action est irréversible.`}
        confirmLabel="Supprimer"
        danger
        onConfirm={() => deleting && remove(deleting)}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
