import { Navigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { LoadingState } from '../../shared/components/ui/Spinner';

/**
 * Route guard that enforces backend-aware access rules:
 *
 *   role="any"    — any authenticated user (existing ProtectedRoute behavior)
 *   role="client" — user must belong to a client (client_id non-null);
 *                    admins without client_id are redirected to /admin
 *   role="admin"  — user must be an admin (is_admin === true);
 *                    non-admins are redirected to /chat
 */
export default function RequireAuth({ children, role = 'any' }) {
  const { user, loading } = useAuth();

  if (loading) {
    return <LoadingState center label="Chargement…" />;
  }

  if (!user) {
    return <Navigate to="/signin" replace />;
  }

  if (role === 'admin' && !user.is_admin) {
    return <Navigate to={user.client_id ? '/chat' : '/signin'} replace />;
  }

  if (role === 'client' && !user.client_id) {
    return user.is_admin ? <Navigate to="/admin" replace /> : <Navigate to="/signin" replace />;
  }

  return children;
}
