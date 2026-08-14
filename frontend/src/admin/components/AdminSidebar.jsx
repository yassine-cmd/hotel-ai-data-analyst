import { NavLink, useNavigate } from 'react-router-dom';
import { LayoutDashboard, Database, Building2, BookOpen, ShieldCheck, KeyRound, FileText, LogOut, PanelLeft } from 'lucide-react';
import { useAuth } from '../../auth/contexts/AuthContext';
import Logo from '../../shared/components/ui/Logo';
import { BRAND } from '../../shared/brand';

const items = [
  ['Tableau de bord', '/admin', LayoutDashboard, true],
  ['Clients', '/admin/clients', Building2],
  ['Jetons de permission', '/admin/permissions', KeyRound],
  ['Schéma', '/admin/schema', Database],
  ['Contexte métier', '/admin/business-context', BookOpen],
  ['Administrateurs', '/admin/users', ShieldCheck],
  ['Journaux', '/admin/logs', FileText],
];

export default function AdminSidebar({ collapsed, onToggleCollapse }) {
  const { signOut } = useAuth();
  const navigate = useNavigate();
  const logout = async () => { await signOut(); navigate('/'); };

  return (
    <aside
      className={`flex shrink-0 flex-col border-r border-white/10 bg-[#343B50] text-white transition-[width] duration-300 ${collapsed ? 'w-16' : 'w-[260px]'}`}
    >
      <div className={`flex h-16 shrink-0 items-center border-b border-white/10 transition-all duration-300 ${collapsed ? 'gap-1 px-0 justify-center' : 'gap-2.5 px-4'}`}>
        <Logo size="sm" className={collapsed ? 'hidden' : 'md:animate-fadeIn'} />
        <div className={`min-w-0 ${collapsed ? 'hidden' : 'md:animate-fadeIn'}`}>
          <div className="truncate text-[13.5px] font-semibold leading-tight tracking-tight text-white">{BRAND.name}</div>
          <div className="mt-0.5 truncate text-[11px] leading-snug text-[#C5C9D3]">Opérations hôtelières</div>
        </div>
        <span className={`rounded-full bg-[#2D3447] px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-white ${collapsed ? 'hidden' : 'md:animate-fadeIn'}`}>Administration</span>
        <button
          className={`grid shrink-0 place-items-center rounded-lg text-[#C5C9D3] transition-all duration-300 hover:bg-white/10 hover:text-white ${collapsed ? 'md:mx-auto h-8 w-8' : 'ml-auto h-8 w-8'}`}
          onClick={onToggleCollapse}
          aria-label={collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale'}
          aria-expanded={!collapsed}
          title={collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale'}
        >
          <PanelLeft className="h-4 w-4" />
        </button>
      </div>
      <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        {items.map(([label, path, Icon, exact]) => (
          <NavLink
            key={path}
            to={path}
            end={exact}
            title={collapsed ? label : undefined}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-[13px] transition-colors ${collapsed ? 'justify-center' : ''} ${isActive ? 'bg-[#2D3447] font-semibold text-white shadow-[inset_0_0_0_1px_rgba(255,255,255,0.06)]' : 'text-[#C5C9D3] hover:bg-white/10 hover:text-white'}`
            }
          >
            <Icon className="h-4 w-4 shrink-0" />
            <span className={collapsed ? 'hidden' : 'md:animate-fadeIn'}>{label}</span>
          </NavLink>
        ))}
      </nav>
      <div className="border-t border-white/10 p-3">
        <button
          type="button"
          onClick={logout}
          title={collapsed ? 'Se déconnecter' : undefined}
          className={`flex w-full items-center gap-3 rounded-[12px] px-3 py-2.5 text-[13px] text-[#C5C9D3] transition-colors hover:bg-white/10 hover:text-white ${collapsed ? 'justify-center' : ''}`}
        >
          <LogOut className="h-4 w-4 shrink-0" />
          <span className={collapsed ? 'hidden' : 'md:animate-fadeIn'}>Se déconnecter</span>
        </button>
      </div>
    </aside>
  );
}