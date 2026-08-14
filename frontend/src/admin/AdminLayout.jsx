import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../auth/contexts/AuthContext';
import AdminSidebar from './components/AdminSidebar';
import { ToastProvider, useToast } from '../shared/components/ui/Toast';
import Avatar from '../shared/components/ui/Avatar';

const TITLES = [
  ['/admin/schema', 'Schéma'],
  ['/admin/business-context', 'Contexte métier'],
  ['/admin/permissions', 'Permissions'],
  ['/admin/users', 'Administrateurs'],
  ['/admin/clients', 'Clients'],
  ['/admin/logs', 'Journaux'],
  ['/admin', 'Tableau de bord'],
];

function pageTitle(pathname) {
  return TITLES.find(([path]) => pathname === path || pathname.startsWith(path + '/'))?.[1] || 'Administration';
}

function AdminShell() {
  const { user } = useAuth();
  const location = useLocation();
  const toast = useToast();
  const title = pageTitle(location.pathname);
  const [collapsed, setCollapsed] = useState(false);

  const notify = ({ variant = 'info', message }) => {
    if (message) toast({ type: variant === 'error' ? 'error' : variant, message });
  };

  return (
    <div className="flex h-screen bg-[#F1F3F7]">
      <AdminSidebar collapsed={collapsed} onToggleCollapse={() => setCollapsed((c) => !c)} />
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-[64px] shrink-0 items-center justify-between border-b border-[#e8ecf2] bg-white px-5 shadow-[0_2px_8px_rgba(0,0,0,0.08)]">
          <h1 className="text-[15px] font-semibold tracking-tight text-[#30364d]">{title}</h1>
          <div className="flex items-center gap-3">
            <span className="text-xs text-[#777b85]">{user?.name || user?.username || 'Administrateur'}</span>
            <Avatar name={user?.name || user?.username || 'Administrateur'} size="sm" />
          </div>
        </header>
        <main className="min-w-0 flex-1 overflow-y-auto bg-[#F1F3F7]">
          <div className="p-6 md:p-8">
            <Outlet context={{ notify }} />
          </div>
        </main>
      </div>
    </div>
  );
}

export default function AdminLayout() {
  return (
    <ToastProvider>
      <AdminShell />
    </ToastProvider>
  );
}
