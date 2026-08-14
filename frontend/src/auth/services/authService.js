import { apiFetch, apiFetchJson } from '../../shared/services/apiClient';

export async function signIn({ username, password }) {
  const data = await apiFetchJson('/api/auth/login', {
    method: 'POST',
    body: JSON.stringify({ username, password }),
  });
  return data.user || data;
}

export async function signOut() {
  await apiFetch('/api/auth/logout', { method: 'POST' }).catch(() => {});
}

export async function getCurrentUser() {
  try {
    return await apiFetchJson('/api/auth/user');
  } catch {
    return null;
  }
}
