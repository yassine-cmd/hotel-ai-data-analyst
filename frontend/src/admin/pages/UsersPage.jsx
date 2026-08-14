import { useEffect, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { Pencil, Trash2 } from 'lucide-react';
import { adminService } from '../services/adminService';
import { useAuth } from '../../auth/contexts/AuthContext';
import Modal from '../../shared/components/ui/Modal';
import PageHeader from '../../shared/components/ui/PageHeader';
import ConfirmDialog from '../../shared/components/ui/ConfirmDialog';
import Button from '../../shared/components/ui/Button';
import Field, { TextInput } from '../../shared/components/ui/Field';
import TableEmpty from '../../shared/components/ui/TableEmpty';
import { Table, Td, Th, Tr } from '../../shared/components/ui/Table';
import { LoadingState } from '../../shared/components/ui/Spinner';

export default function UsersPage() {
  const { user } = useAuth();
  const { notify } = useOutletContext();
  const [admins, setAdmins] = useState([]);
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [form, setForm] = useState({ name: '', username: '', password: '' });
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    adminService.listUsers('admin').then(setAdmins).catch((e) => setStatus('Error: ' + e.message)).finally(() => setLoading(false));
  };
  useEffect(load, []);

  const fail = (e) => notify({ variant: 'error', message: e.message });

  const openCreate = () => {
    setEditing({});
    setForm({ name: '', username: '', password: '' });
  };
  const openEdit = (u) => {
    setEditing(u);
    setForm({ name: u.name, username: u.username, password: '' });
  };
  const save = async () => {
    setStatus('Enregistrement…');
    try {
      const payload = { name: form.name, username: form.username };
      if (form.password) payload.password = form.password;
      if (!editing.id) payload.is_admin = true;
      if (editing.id) {
        await adminService.updateUser(editing.id, payload);
      } else {
        await adminService.createUser(payload);
      }
      setEditing(null);
      load();
      setStatus('');
      notify({ variant: 'success', message: editing.id ? 'Administrateur mis à jour' : 'Administrateur créé' });
    } catch (e) { setStatus(''); fail(e); }
  };
  const remove = async (u) => {
    if (u.id === user?.id) return;
    try { await adminService.deleteUser(u.id); load(); notify({ variant: 'success', message: 'Administrateur supprimé' }); }
    catch (e) { fail(e); }
    finally { setDeleting(null); }
  };

  if (loading && !admins.length) return <LoadingState label="Chargement des administrateurs…" />;

  return (
    <div>
      <PageHeader
        description="Gérer les comptes administrateurs."
        actions={<Button variant="primary" size="sm" onClick={openCreate}>Ajouter un administrateur</Button>}
      />
      <div className="overflow-hidden rounded-xl border border-line bg-raised">
        {!admins.length ? (
          <TableEmpty title="Aucun administrateur" description="Ajoutez le premier administrateur pour gérer la console." />
        ) : (
          <Table>
            <thead><tr><Th>Identifiant</Th><Th>Nom</Th><Th>Créé</Th><Th>Actions</Th></tr></thead>
            <tbody>
              {admins.map((u) => (
                <Tr key={u.id}>
                  <Td><span className="font-mono text-[12.5px]">{u.username}</span></Td>
                  <Td>{u.name}</Td>
                  <Td className="text-fg-3">{u.created_at ? new Date(u.created_at).toLocaleDateString() : '—'}</Td>
                  <Td>
                    <div className="flex items-center gap-1.5">
                      <Button variant="ghost" size="sm" onClick={() => openEdit(u)} title="Modifier" aria-label="Modifier"><Pencil className="h-3.5 w-3.5" /></Button>
                      <Button variant="danger" size="sm" onClick={() => setDeleting(u)} disabled={u.id === user?.id} title={u.id === user?.id ? 'Vous ne pouvez pas supprimer votre propre compte' : 'Supprimer'} aria-label="Supprimer"><Trash2 className="h-3.5 w-3.5" /></Button>
                    </div>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
      </div>

      <Modal open={!!editing} title={editing?.id ? 'Modifier l\u2019administrateur' : 'Ajouter un administrateur'} onClose={() => setEditing(null)} size="md"
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>Annuler</Button>
            <Button variant="primary" size="sm" onClick={save}>Enregistrer</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Nom" htmlFor="user-name">
            <TextInput id="user-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </Field>
          <Field label="Identifiant" htmlFor="user-username">
            <TextInput id="user-username" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} required />
          </Field>
          <Field label="Mot de passe" htmlFor="user-password" hint={editing?.id ? 'Laisser vide pour conserver' : undefined}>
            <TextInput id="user-password" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder={editing?.id ? 'Laisser vide pour conserver' : ''} required={!editing?.id} />
          </Field>
          {status && <p className="text-xs text-fg-3">{status}</p>}
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        title="Supprimer l\u2019administrateur"
        message={`Supprimer l\u2019administrateur « ${deleting?.username} » ? Cette action est irréversible.`}
        confirmLabel="Supprimer"
        danger
        onConfirm={() => deleting && remove(deleting)}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
