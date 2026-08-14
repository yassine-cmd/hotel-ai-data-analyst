import { useEffect, useState, useCallback } from 'react';
import { useOutletContext } from 'react-router-dom';
import { Search, RotateCw, ClipboardList, Copy, Lock, X, Pencil } from 'lucide-react';
import { adminService } from '../services/adminService';
import Badge from '../../shared/components/ui/Badge';
import Modal from '../../shared/components/ui/Modal';
import Button from '../../shared/components/ui/Button';
import IconButton from '../../shared/components/ui/IconButton';
import EmptyState from '../../shared/components/ui/EmptyState';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import Field, { TextInput, TextArea } from '../../shared/components/ui/Field';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import { LoadingState } from '../../shared/components/ui/Spinner';

const inputSm = 'rounded-md border border-line bg-input px-2 py-1.5 text-[12.5px] text-fg placeholder:text-fg-3 outline-none transition-colors focus:border-accent';

function sourceBadge(source) {
  if (source === 'manual') return <Badge variant="success" dot>Manuelle</Badge>;
  if (source === 'auto') return <Badge variant="accent" dot>Automatique</Badge>;
  return <Badge variant="neutral" dot>Non définie</Badge>;
}

function parseAiResponse(text) {
  const cleaned = (text || '').replace(/^```(?:json)?\s*/i, '').replace(/```\s*$/, '').trim();
  let data;
  try { data = JSON.parse(cleaned); } catch { return null; }
  if (!Array.isArray(data)) {
    for (const key of ['descriptions', 'entries', 'data']) {
      if (Array.isArray(data?.[key])) { data = data[key]; break; }
    }
  }
  if (!Array.isArray(data)) return null;
  return data
    .map((raw) => {
      if (!raw || typeof raw !== 'object') return null;
      let { table, column, description } = raw;
      if (!table) {
        for (const key of ['table.column', 'table_column']) {
          if (typeof raw[key] === 'string' && raw[key].includes('.')) {
            const idx = raw[key].indexOf('.');
            table = raw[key].slice(0, idx);
            column = raw[key].slice(idx + 1);
            break;
          }
        }
      }
      if (!column && typeof table === 'string' && table.includes('.')) {
        const idx = table.indexOf('.');
        column = table.slice(idx + 1);
        table = table.slice(0, idx);
      }
      if (typeof table !== 'string' || !table.trim()) return null;
      if (typeof description !== 'string' || !description.trim()) return null;
      return { table: table.trim(), column: column && typeof column === 'string' ? column.trim() : null, description: description.trim() };
    })
    .filter(Boolean);
}

function VmBuilder({ colName, values, onChange }) {
  const [entries, setEntries] = useState(values && typeof values === 'object' ? Object.entries(values) : []);

  const notify = useCallback((updated) => {
    setEntries(updated);
    const vm = updated.reduce((acc, [k, v]) => { if (k.trim()) acc[k.trim()] = v.trim(); return acc; }, {});
    onChange(colName, vm, Object.keys(vm).length > 0);
  }, [colName, onChange]);

  const update = (i, field, val) => {
    const next = entries.map((e, idx) => idx === i ? [field === 'code' ? val : e[0], field === 'label' ? val : e[1]] : e);
    notify(next);
  };
  const add = () => { const next = [...entries, ['', '']]; notify(next); };
  const remove = (i) => { const next = entries.filter((_, idx) => idx !== i); notify(next); };

  return (
    <div className="mt-2 space-y-1.5" data-col={colName}>
      {entries.length === 0 && <EmptyState compact title="Aucun mapping de valeur" />}
      {entries.map(([k, v], i) => (
        <div key={i} className="flex items-center gap-1.5">
          <input className={`${inputSm} w-28 font-mono`} placeholder="code" value={k} onChange={(e) => update(i, 'code', e.target.value)} />
          <input className={`${inputSm} flex-1`} placeholder="libellé" value={v} onChange={(e) => update(i, 'label', e.target.value)} />
          <IconButton label="Supprimer le mapping" variant="danger" size="sm" onClick={() => remove(i)} type="button"><X className="h-3.5 w-3.5" /></IconButton>
        </div>
      ))}
      <Button variant="ghost" size="sm" onClick={add} type="button">+ Ajouter un mapping</Button>
    </div>
  );
}

export default function SchemaPage() {
  const { notify } = useOutletContext();
  const [metaData, setMetaData] = useState([]);
  const [discovery, setDiscovery] = useState(null);
  const [clients, setClients] = useState([]);
  const [filter, setFilter] = useState('all');
  const [search, setSearch] = useState('');
  const [tables, setTables] = useState({});
  const [metadataRows, setMetadataRows] = useState({});
  const [currentTable, setCurrentTable] = useState(null);
  const [dirty, setDirty] = useState(false);
  const [detailStatus, setDetailStatus] = useState('');
  const [discoverClient, setDiscoverClient] = useState('');
  const [discoverStatus, setDiscoverStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [jsonEditor, setJsonEditor] = useState(false);
  const [jsonPrompt, setJsonPrompt] = useState('');
  const [jsonResponse, setJsonResponse] = useState('');
  const [jsonOverwrite, setJsonOverwrite] = useState(false);
  const [jsonImportStatus, setJsonImportStatus] = useState('');

  const loadSchema = useCallback(() => {
    setLoading(true);
    adminService.listMetadata({ include_archived: 'true' })
      .then((data) => {
        const rows = {};
        const tbls = {};
        for (const row of data.metadata) {
          const key = row.column_name ? `${row.table_name}.${row.column_name}` : row.table_name;
          rows[key] = row;
          if (row.metadata_type === 'table') {
            if (!tbls[row.table_name]) tbls[row.table_name] = { description: row.description, row_count: row.row_count, is_archived: row.is_archived, virtual_foreign_keys: row.virtual_foreign_keys || [], virtual_foreign_keys_source: row.virtual_foreign_keys_source || 'none', columns: [] };
          }
        }
        for (const row of data.metadata) {
          if (row.metadata_type === 'column' && tbls[row.table_name]) {
            tbls[row.table_name].columns.push({
              name: row.column_name,
              type: row.data_type,
              key: row.column_key || '',
              description: row.description || null,
              values: row.value_mappings || null,
              is_sensitive: row.is_sensitive || false,
              id: row.id,
              row_version: row.row_version,
            });
          }
        }
        setMetaData(data.metadata);
        setMetadataRows(rows);
        setTables(tbls);
        setDiscovery(data.discovery || null);
      })
      .catch((e) => setDetailStatus('Erreur : ' + e.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { loadSchema(); }, [loadSchema]);
  useEffect(() => { adminService.listClients().then(setClients).catch(() => {}); }, []);

  const totalTables = Object.keys(tables).filter((k) => !k.startsWith('*')).length;
  const withDesc = Object.values(tables).filter((t) => t.description).length;

  const matchesFilter = (name, info) => {
    if (search && !name.toLowerCase().includes(search.toLowerCase())) return false;
    if (filter === 'all') return true;
    if (filter === 'enriched') return !!info.description || info.columns.some((c) => c.description || c.values);
    if (filter === 'sensitive') return metadataRows[name]?.is_sensitive || info.columns.some((c) => c.is_sensitive);
    if (filter === 'empty') return !info.description;
    if (filter === 'archived') return info.is_archived;
    return true;
  };

  const openDetail = (name) => {
    const info = tables[name];
    if (!info) return;
    setCurrentTable({ name, ...info, is_sensitive: !!metadataRows[name]?.is_sensitive, _cols: info.columns.map((c) => ({ ...c })), _virtual_fks: (info.virtual_foreign_keys || []).map((f) => ({ ...f })) });
    setDirty(false);
    setDetailStatus('');
  };

  const closeDetail = () => {
    if (!dirty || confirm('Ignorer les modifications non enregistrées ?')) {
      setCurrentTable(null);
      setDirty(false);
    }
  };

  const updateColumnField = (colName, field, value) => {
    if (!currentTable) return;
    const cols = currentTable._cols.map((c) => c.name === colName ? { ...c, [field]: value } : c);
    setCurrentTable({ ...currentTable, _cols: cols });
    setDirty(true);
    setDetailStatus('Modifications non enregistrées');
  };

  const updateVirtualFk = (i, field, value) => {
    if (!currentTable) return;
    const vfks = (currentTable._virtual_fks || []).map((f, idx) => idx === i ? { ...f, [field]: value } : f);
    setCurrentTable({ ...currentTable, _virtual_fks: vfks });
    setDirty(true);
    setDetailStatus('Modifications non enregistrées');
  };

  const addVirtualFk = () => {
    if (!currentTable) return;
    const firstCol = (currentTable._cols || [])[0]?.name || '';
    const firstTable = Object.keys(tables).filter((k) => !k.startsWith('*')).sort()[0] || '';
    const firstRefCol = tables[firstTable]?.columns?.[0]?.name || '';
    setCurrentTable({ ...currentTable, _virtual_fks: [...(currentTable._virtual_fks || []), { column: firstCol, ref_table: firstTable, ref_col: firstRefCol }] });
    setDirty(true);
    setDetailStatus('Modifications non enregistrées');
  };

  const removeVirtualFk = (i) => {
    if (!currentTable) return;
    const vfks = (currentTable._virtual_fks || []).filter((_, idx) => idx !== i);
    setCurrentTable({ ...currentTable, _virtual_fks: vfks });
    setDirty(true);
    setDetailStatus('Modifications non enregistrées');
  };

  const saveDetail = async () => {
    if (!currentTable) return;
    setDetailStatus('Enregistrement…');
    try {
      const tableMeta = metadataRows[currentTable.name];
      if (tableMeta && tableMeta.id) {
        const payload = { row_version: tableMeta.row_version };
        if (currentTable.description) payload.description = currentTable.description;
        payload.is_sensitive = currentTable.is_sensitive;
        payload.virtual_foreign_keys = currentTable._virtual_fks || [];
        const res = await adminService.updateMetadata(tableMeta.id, payload);
        if (res.error === 'VERSION_CONFLICT') {
          setDetailStatus('Conflit : rechargez et réessayez.');
          return;
        }
      }
      for (const col of currentTable._cols) {
        const colMeta = metadataRows[`${currentTable.name}.${col.name}`];
        if (colMeta && colMeta.id) {
          const changed = col.description !== (colMeta.description || null) ||
            JSON.stringify(col.values) !== JSON.stringify(colMeta.value_mappings) ||
            col.is_sensitive !== (colMeta.is_sensitive || false);
          if (changed) {
            const payload = { row_version: colMeta.row_version };
            if (col.description) payload.description = col.description;
            if (col.values) payload.value_mappings = col.values;
            payload.is_sensitive = col.is_sensitive;
            const res = await adminService.updateMetadata(colMeta.id, payload);
            if (res.error === 'VERSION_CONFLICT') {
              setDetailStatus('Conflit sur la colonne ' + col.name + '. Rechargez et réessayez.');
              return;
            }
          }
        }
      }
      await loadSchema();
      setDirty(false);
      setDetailStatus('Enregistré avec succès');
      notify({ variant: 'success', message: 'Schéma enregistré' });
    } catch (e) {
      setDetailStatus('Erreur : ' + e.message);
      notify({ variant: 'error', message: e.message });
    }
  };

  const runDiscovery = async () => {
    if (!discoverClient) return;
    setDiscoverStatus('Découverte en cours…');
    try {
      const result = await adminService.discover(discoverClient);
      setDiscoverStatus(result.data?.status === 'completed' ? 'Découverte terminée' : 'Échec de la découverte');
      notify({ variant: result.data?.status === 'completed' ? 'success' : 'error', message: result.data?.status === 'completed' ? 'Découverte terminée' : 'Échec de la découverte' });
      loadSchema();
    } catch (e) {
      setDiscoverStatus('Erreur : ' + e.message);
    }
  };

  const openJsonEditor = () => {
    const entries = metaData.map((m) => ({
      table: m.table_name,
      column: m.column_name || null,
      description: m.description || '',
    }));
    const instructions = [
      'Rédigez des descriptions concises et adaptées au métier pour le schéma de base de données ci-dessous.',
      'Renvoie UNIQUEMENT un tableau JSON valide. Chaque élément doit être un objet :',
      '[{"table": "nom_exact_de_la_table", "column": "nom_exact_de_la_colonne ou null pour une table", "description": "une ou deux phrases"}]',
      'Conservez exactement les noms de tables et de colonnes tels qu\'indiqués. Utilisez "column": null pour les descriptions de niveau table.',
      'Incluez chaque entrée de la liste ci-dessous.',
    ].join('\n');
    const list = entries
      .map((e) => `- ${e.table}${e.column ? '.' + e.column : ''}: ${e.description || '(aucune description pour le moment)'}`)
      .join('\n');
    setJsonPrompt(`${instructions}\n\n${list}`);
    setJsonResponse('');
    setJsonImportStatus('');
    setJsonEditor(true);
  };

  const copyText = async (text) => {
    try {
      await navigator.clipboard.writeText(text);
      notify({ variant: 'success', message: 'Copié dans le presse-papiers' });
    } catch {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        notify({ variant: 'success', message: 'Copié dans le presse-papiers' });
      } catch {
        notify({ variant: 'error', message: 'Échec de la copie — sélectionnez et copiez manuellement' });
      }
      document.body.removeChild(ta);
    }
  };

  const importJson = async () => {
    const entries = parseAiResponse(jsonResponse);
    if (!entries) {
      setJsonImportStatus('JSON invalide — un tableau d\'objets {table, column, description} est attendu.');
      return;
    }
    setJsonImportStatus('Import en cours…');
    try {
      const result = await adminService.importDescriptions(entries, jsonOverwrite);
      setJsonImportStatus(`Mis à jour : ${result.updated}, Ignorés : ${result.skipped}, Introuvables : ${result.not_found}`);
      loadSchema();
    } catch (e) {
      setJsonImportStatus('Erreur : ' + e.message);
    }
  };

  const filterBtns = ['all', 'enriched', 'sensitive', 'empty', 'archived'];
  const FILTER_LABELS = { all: 'Tout', enriched: 'Enrichies', sensitive: 'Sensibles', empty: 'Vides', archived: 'Archivées' };
  const entries = Object.entries(tables).filter(([k]) => !k.startsWith('*')).filter(([n, i]) => matchesFilter(n, i)).sort(([a], [b]) => a.localeCompare(b));

  if (loading && !Object.keys(tables).length) return <LoadingState label="Chargement du schéma…" />;

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-[22px] font-semibold tracking-tight text-fg">Éditeur de schéma</h1>
          <p className="mt-1 text-[13px] text-fg-3">
            {discovery?.last_discovered_at ? `Découvert le : ${new Date(discovery.last_discovered_at).toLocaleString()}` : 'Pas encore découvert'} · {withDesc}/{totalTables} décrites ({totalTables ? Math.round(withDesc / totalTables * 100) : 0}%)
          </p>
        </div>
        <div className="flex items-center gap-2">
          {clients.length > 0 && (
            <select className={`${inputSm} cursor-pointer`} value={discoverClient} onChange={(e) => setDiscoverClient(e.target.value)}>
              <option value="">Sélectionner un client…</option>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          )}
          <Button variant="primary" size="sm" onClick={runDiscovery} disabled={!discoverClient}><Search className="h-3.5 w-3.5" /> Découvrir</Button>
          <Button variant="ghost" size="sm" onClick={openJsonEditor}><ClipboardList className="h-3.5 w-3.5" /> JSON</Button>
        </div>
      </div>
      {discoverStatus && <p className="mb-4 text-xs text-fg-3">{discoverStatus}</p>}

      <div className="overflow-hidden rounded-xl border border-line bg-raised">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
          <label className="flex h-8 w-64 items-center gap-2 rounded-md border border-line bg-input px-2.5">
            <Search className="h-3.5 w-3.5 text-fg-3" />
            <input placeholder="Rechercher des tables…" value={search} onChange={(e) => setSearch(e.target.value)} className="min-w-0 flex-1 bg-transparent text-[12.5px] text-fg placeholder:text-fg-3 outline-none" />
          </label>
          <div className="flex flex-wrap items-center gap-1">
            {filterBtns.map((f) => (
              <button
                key={f}
                type="button"
                className={`rounded-md px-2.5 py-1 text-[12px] transition-colors ${filter === f ? 'bg-accent/15 font-semibold text-accent-fg' : 'text-fg-3 hover:bg-white/5 hover:text-fg-2'}`}
                onClick={() => setFilter(f)}
              >
                {FILTER_LABELS[f]}
              </button>
            ))}
          </div>
        </div>
        {entries.length === 0 ? (
          <TableEmpty title="Aucune table ne correspond à votre filtre" />
        ) : (
          <>
            <Table>
              <thead><tr><Th>Nom</Th><Th>Description</Th><Th>Statut</Th><Th /></tr></thead>
              <tbody>
                {entries.map(([name, info]) => (
                  <Tr key={name} className="cursor-pointer" onClick={() => openDetail(name)}>
                    <Td className="font-medium text-fg">{name}</Td>
                    <Td className="max-w-[380px] text-fg-3">{info.description || <span className="text-fg-3/60">—</span>}</Td>
                    <Td>
                      <div className="flex flex-wrap items-center gap-1.5">
                        {((metadataRows[name]?.description_source) || (info.description ? 'auto' : 'none')) === 'auto' && <Badge variant="accent" dot>Automatique</Badge>}
                        {info.columns.some((c) => c.values) && <Badge variant="success" dot>Valeurs</Badge>}
                        {info.virtual_foreign_keys?.length > 0 && <Badge variant="success" dot>VFk</Badge>}
                        {info.is_archived && <Badge variant="warning">Archivée</Badge>}
                        {(metadataRows[name]?.is_sensitive || info.columns.some((c) => c.is_sensitive)) && <Badge variant="danger"><Lock className="h-2.5 w-2.5" /> Sensible</Badge>}
                      </div>
                    </Td>
                    <Td>
                      <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); openDetail(name); }} title="Modifier" aria-label="Modifier"><Pencil className="h-3.5 w-3.5" /></Button>
                    </Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
            <div className="border-t border-line px-5 py-3 text-xs text-fg-3">{entries.length} sur {totalTables} tables</div>
          </>
        )}
      </div>

      <Modal open={!!currentTable} title={currentTable?.name} size="lg" onClose={closeDetail}
        footer={
          <>
            <span className="mr-auto text-xs text-fg-3">{detailStatus}</span>
            <Button variant="ghost" size="sm" onClick={closeDetail}>Annuler</Button>
            <Button variant="primary" size="sm" onClick={saveDetail}>Enregistrer</Button>
          </>
        }
      >
        <div className="space-y-5">
          <div>
            <div className="mb-1.5 flex items-center gap-2">
              <span className="text-[11px] font-semibold uppercase tracking-wider text-fg-3">Description</span>
              {currentTable && sourceBadge(metadataRows[currentTable.name]?.description_source || (currentTable.description ? 'auto' : 'none'))}
            </div>
            <TextArea rows={2} value={currentTable?.description || ''} onChange={(e) => { setCurrentTable((t) => ({ ...t, description: e.target.value })); setDirty(true); setDetailStatus('Modifications non enregistrées'); }} placeholder="Description de la table…" />
            <label className="mt-2 flex w-fit cursor-pointer items-center gap-2 text-[12.5px] text-fg-2">
              <input type="checkbox" checked={!!currentTable?.is_sensitive} onChange={(e) => { setCurrentTable((t) => ({ ...t, is_sensitive: e.target.checked })); setDirty(true); setDetailStatus('Modifications non enregistrées'); }} className="h-3.5 w-3.5 accent-[var(--color-accent)]" /> Sensible (toute la table)
            </label>
          </div>
          <div className="space-y-4">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-fg-3">Colonnes</p>
            {(currentTable?._cols || []).length === 0 ? (
              <EmptyState compact title="Aucune colonne" description="Cette table n'a pas de colonnes." />
            ) : (
              (currentTable?._cols || []).map((col) => (
                <div key={col.name} className="rounded-lg border border-line bg-base/50 p-3">
                  <p className="text-[13px] font-medium text-fg">{col.name} <span className="font-mono text-[11px] text-fg-3">{col.type}{col.key === 'PRI' ? ' PK' : ''}</span></p>
                  <TextArea className="mt-2" rows={1} placeholder="Description…" value={col.description || ''} onChange={(e) => updateColumnField(col.name, 'description', e.target.value)} />
                  <VmBuilder colName={col.name} values={col.values} onChange={(cn, vm, hasValues) => updateColumnField(cn, 'values', hasValues ? vm : null)} />
                  <label className="mt-2 flex w-fit cursor-pointer items-center gap-2 text-[12.5px] text-fg-2">
                    <input type="checkbox" checked={col.is_sensitive} onChange={(e) => updateColumnField(col.name, 'is_sensitive', e.target.checked)} className="h-3.5 w-3.5 accent-[var(--color-accent)]" /> Sensitive
                  </label>
                </div>
              ))
            )}
          </div>
          <div>
            <div className="mb-1.5 flex items-center gap-2">
              <span className="text-[11px] font-semibold uppercase tracking-wider text-fg-3">Clés étrangères virtuelles</span>
              {currentTable && sourceBadge(metadataRows[currentTable.name]?.virtual_foreign_keys_source || ((currentTable._virtual_fks?.length ? 'manual' : 'none')))}
            </div>
            <div className="space-y-1.5">
              {(!currentTable?._virtual_fks || currentTable._virtual_fks.length === 0) && <EmptyState compact title="Aucune clé étrangère virtuelle définie" />}
              {(currentTable?._virtual_fks || []).map((fk, i) => {
                const colOpts = (currentTable?._cols || []).map((c) => <option key={c.name} value={c.name}>{c.name}</option>);
                const refTableOpts = Object.keys(tables).filter((k) => !k.startsWith('*')).sort().map((t) => <option key={t} value={t}>{t}</option>);
                const refCols = tables[fk.ref_table]?.columns || [];
                const refColOpts = refCols.map((c) => <option key={c.name} value={c.name}>{c.name}</option>);
                return (
                  <div key={i} className="flex items-center gap-1.5">
                    <select className={`${inputSm} w-36 cursor-pointer`} value={fk.column || ''} onChange={(e) => updateVirtualFk(i, 'column', e.target.value)}>{colOpts}</select>
                    <span className="text-fg-3">→</span>
                    <select className={`${inputSm} w-36 cursor-pointer`} value={fk.ref_table || ''} onChange={(e) => updateVirtualFk(i, 'ref_table', e.target.value)}>{refTableOpts}</select>
                    <span className="text-fg-3">.</span>
                    <select className={`${inputSm} min-w-0 flex-1 cursor-pointer`} value={fk.ref_col || ''} onChange={(e) => updateVirtualFk(i, 'ref_col', e.target.value)}>{refColOpts}</select>
                    <IconButton label="Supprimer la clé étrangère virtuelle" variant="danger" size="sm" onClick={() => removeVirtualFk(i)} type="button"><X className="h-3.5 w-3.5" /></IconButton>
                  </div>
                );
              })}
            </div>
            <Button variant="ghost" size="sm" onClick={addVirtualFk} type="button" className="mt-1.5">+ Ajouter une clé étrangère virtuelle</Button>
          </div>
        </div>
      </Modal>

      <Modal open={jsonEditor} title="Éditeur JSON du schéma" size="lg" onClose={() => setJsonEditor(false)}>
        <div className="space-y-4">
          <Field label="Invite (modifiez puis copiez vers l'IA)">
            <TextArea className="font-mono text-[12px]" rows={8} value={jsonPrompt} onChange={(e) => setJsonPrompt(e.target.value)} />
          </Field>
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="sm" onClick={() => copyText(jsonPrompt)}><Copy className="h-3.5 w-3.5" /> Copier</Button>
            <Button variant="ghost" size="sm" onClick={openJsonEditor}><RotateCw className="h-3.5 w-3.5" /> Régénérer</Button>
          </div>
          <hr className="border-line" />
          <Field label="Réponse de l'IA">
            <TextArea className="font-mono text-[12px]" rows={6} value={jsonResponse} onChange={(e) => setJsonResponse(e.target.value)} placeholder="Collez ici la réponse JSON de l'IA — un tableau d'objets {&quot;table&quot;, &quot;column&quot;, &quot;description&quot;}." />
          </Field>
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="primary" size="sm" onClick={importJson}>Importer</Button>
            <label className="flex cursor-pointer items-center gap-2 text-[12.5px] text-fg-2">
              <input type="checkbox" checked={jsonOverwrite} onChange={(e) => setJsonOverwrite(e.target.checked)} className="h-3.5 w-3.5 accent-[var(--color-accent)]" /> Écraser l'existant
            </label>
            <span className="text-xs text-fg-3">{jsonImportStatus}</span>
          </div>
        </div>
      </Modal>
    </div>
  );
}
