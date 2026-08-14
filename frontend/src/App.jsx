import { Navigate, Route, Routes } from 'react-router-dom';
import AppLayout from './client/layouts/AppLayout';
import AuthLayout from './auth/layouts/AuthLayout';
import SignInPage from './auth/pages/SignInPage';
import LogoutPage from './auth/pages/LogoutPage';
import ChatPage from './client/pages/ChatPage';
import HistoryPage from './client/pages/HistoryPage';
import ProfilePage from './client/pages/ProfilePage';
import UsersAccessPage from './client/pages/UsersAccessPage';
import NotFoundPage from './auth/pages/NotFoundPage';
import AdminLayout from './admin/AdminLayout';
import AdminDashboardPage from './admin/pages/AdminDashboardPage';
import AdminLogsPage from './admin/pages/AdminLogsPage';
import SchemaPage from './admin/pages/SchemaPage';
import ClientsPage from './admin/pages/ClientsPage';
import ClientDashboardPage from './admin/pages/ClientDashboardPage';
import BusinessContextPage from './admin/pages/BusinessContextPage';
import PermissionTokensPage from './admin/pages/PermissionTokensPage';
import UsersPage from './admin/pages/UsersPage';
import { AuthProvider } from './auth/contexts/AuthContext';
import RequireAuth from './auth/routes/RequireAuth';

export default function App() {
  return <AuthProvider><Routes>
    <Route path="/" element={<Navigate to="/signin" replace />} />
    <Route element={<AuthLayout />}>
      <Route path="/signin" element={<SignInPage />} />
      <Route path="/logout" element={<LogoutPage />} />
    </Route>
    <Route element={<RequireAuth role="client"><AppLayout /></RequireAuth>}>
      <Route path="/chat" element={<ChatPage />} />
      <Route path="/history" element={<HistoryPage />} />
      <Route path="/profile" element={<ProfilePage />} />
      <Route path="/staff" element={<UsersAccessPage />} />
    </Route>
      <Route path="/admin" element={<RequireAuth role="admin"><AdminLayout /></RequireAuth>}>
        <Route index element={<AdminDashboardPage />} />
        <Route path="logs" element={<AdminLogsPage />} />
        <Route path="schema" element={<SchemaPage />} />
      <Route path="clients" element={<ClientsPage />} />
      <Route path="clients/:id" element={<ClientDashboardPage />} />
      <Route path="business-context" element={<BusinessContextPage />} />
      <Route path="vocabulary" element={<Navigate to="/admin/business-context" replace />} />
      <Route path="permissions" element={<PermissionTokensPage />} />
      <Route path="users" element={<UsersPage />} />
    </Route>
    <Route path="*" element={<NotFoundPage />} />
  </Routes></AuthProvider>;
}
