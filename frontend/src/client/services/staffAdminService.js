import { apiFetchJson, apiFetch, toQueryString } from '../../shared/services/apiClient';

export async function listStaff(params = {}) {
  return apiFetchJson(`/api/client/staff${toQueryString({ ...params })}`);
}

async function setDeactivated(id, state) {
  const response = await apiFetch(`/api/client/staff/${id}/${state}`, { method: 'POST' });
  if (!response.ok) throw new Error('Échec de la mise à jour de l\u2019utilisateur');
  return response.json();
}

export function deactivateStaff(id) {
  return setDeactivated(id, 'deactivate');
}

export function activateStaff(id) {
  return setDeactivated(id, 'activate');
}