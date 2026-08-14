import { apiFetchJson } from '../../shared/services/apiClient';

export async function getProfile() {
  return apiFetchJson('/api/client/profile');
}

export async function getTokenUsage(page = 1, perPage = 10, usersPage = 1, usersPerPage = 10) {
  return apiFetchJson(`/api/client/usage?page=${page}&per_page=${perPage}&users_page=${usersPage}&users_per_page=${usersPerPage}`);
}
