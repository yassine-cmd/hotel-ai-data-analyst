import { apiFetch } from '../../shared/services/apiClient';

const sid = (sessionId) => `/api/client/sessions/${encodeURIComponent(sessionId)}`;

export async function listSessions() {
  const response = await apiFetch('/api/client/sessions');
  if (!response.ok) throw new Error('Échec du chargement des conversations');
  const data = await response.json();
  return data.sessions || data || [];
}

export async function createSession(name) {
  const response = await apiFetch('/api/client/sessions', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: name || undefined }),
  });
  if (!response.ok) throw new Error('Échec de la création de la conversation');
  return response.json();
}

export async function renameSession(sessionId, name) {
  const response = await apiFetch(sid(sessionId), {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  });
  if (!response.ok) throw new Error('Échec du renommage de la conversation');
  return response.json();
}

export async function getHistory(sessionId) {
  const response = await apiFetch(`${sid(sessionId)}/history`);
  if (!response.ok) throw new Error('Impossible de charger cette conversation.');
  return response.json();
}

export async function deleteSession(sessionId) {
  const response = await apiFetch(sid(sessionId), { method: 'DELETE' });
  if (!response.ok) throw new Error('Impossible de supprimer cette conversation.');
  return response.json();
}

export const downloadUrl = (sessionId, name) => `${sid(sessionId)}/artifacts/${encodeURIComponent(name)}/download`;
