import { useEffect, useState } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import { ChevronDown, LogOut, User, Users } from 'lucide-react';
import { useAuth } from '../../auth/contexts/AuthContext';
import { useSession } from '../hooks/useSession';
import ConversationsSidebar from '../components/ConversationsSidebar';
import Avatar from '../../shared/components/ui/Avatar';
import Dropdown from '../../shared/components/ui/Dropdown';

function useMediaQuery(query) {
  const [matches, setMatches] = useState(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return false;
    return window.matchMedia(query).matches;
  });

  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return;
    const mq = window.matchMedia(query);
    const onChange = () => setMatches(mq.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, [query]);

  return matches;
}

export default function AppLayout() {
  const { user, signOut } = useAuth();
  const session = useSession(user);
  const navigate = useNavigate();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const isDesktop = useMediaQuery('(min-width: 768px)');

  const logout = () => { signOut(); navigate('/'); };
  const handleNew = () => { session.create(); navigate('/chat'); setSidebarOpen(false); };

  const roleLabel = user?.is_admin ? 'Admin' : 'Personnel';
  const isClientAdmin = !user?.is_admin && (user?.role ?? 0) === 1;

  const handleToggleSidebar = () => {
    if (isDesktop) setCollapsed((c) => !c);
    else setSidebarOpen((o) => !o);
  };

  const userMenu = (
    <Dropdown
      open={profileOpen}
      onToggle={setProfileOpen}
      align="right"
      direction="down"
      trigger={
        <button
          className="flex items-center gap-2 rounded-xl px-2.5 py-1.5 text-left transition-colors hover:bg-[#e3e7ee]"
          aria-label="Menu utilisateur"
          aria-expanded={profileOpen}
        >
          <Avatar name={user?.name || 'Utilisateur'} size="sm" />
          <span className="hidden max-w-[140px] sm:block">
            <span className="block truncate text-xs font-semibold text-[#30364d]">{user?.name || 'Utilisateur'}</span>
            <span className="block text-[10px] uppercase tracking-[0.18em] text-[#777b85]">{roleLabel}</span>
          </span>
          <ChevronDown className={`h-3.5 w-3.5 text-[#777b85] transition-transform ${profileOpen ? 'rotate-180' : ''}`} />
        </button>
      }
      items={[
        ...(isClientAdmin
          ? [{ label: 'Utilisateurs et accès', icon: <Users className="h-3.5 w-3.5" />, onClick: () => navigate('/staff') }]
          : []),
        { label: 'Profil', icon: <User className="h-3.5 w-3.5" />, onClick: () => navigate('/profile') },
        { label: 'Déconnexion', icon: <LogOut className="h-3.5 w-3.5" />, danger: true, onClick: logout },
      ]}
    />
  );

  return <div className="flex h-screen overflow-hidden bg-[#EEF1F5] text-[#30364d]">
    <ConversationsSidebar
      session={session}
      onNew={handleNew}
      open={sidebarOpen}
      onClose={() => setSidebarOpen(false)}
      collapsed={collapsed}
      onToggleCollapse={handleToggleSidebar}
      onExpand={() => setCollapsed(false)}
    />
    <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
      <header className="z-20 flex h-16 shrink-0 items-center justify-end px-4">
        {userMenu}
      </header>
      <main className="flex min-w-0 flex-1 flex-col overflow-hidden bg-[#EEF1F5]">
        <Outlet context={{ session, user, logout }} />
      </main>
    </div>
  </div>;
}
