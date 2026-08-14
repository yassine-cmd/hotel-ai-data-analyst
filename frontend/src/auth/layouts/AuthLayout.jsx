import { Outlet, Link, Navigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import Logo from '../../shared/components/ui/Logo';
import { LoadingState } from '../../shared/components/ui/Spinner';
import { BRAND } from '../../shared/brand';

export default function AuthLayout() {
  const { user, loading } = useAuth();
  if (loading) {
    return <LoadingState center label="Chargement…" />;
  }
  if (user) return <Navigate to={user.is_admin ? '/admin' : '/chat'} replace />;

  return (
    <div className="relative flex min-h-screen flex-col bg-base">
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 top-0 h-[420px]"
        style={{ background: 'radial-gradient(ellipse 70% 60% at 50% -10%, rgba(99,102,241,0.18), transparent)' }}
      />
      <header className="relative z-10 flex h-16 items-center justify-between px-6">
        <Link to="/signin" className="flex items-center gap-2.5">
          <Logo size="sm" />
          <span className="text-[15px] font-semibold tracking-tight text-fg">{BRAND.name}</span>
        </Link>
      </header>
      <main className="relative z-10 flex flex-1 items-center justify-center px-6 pb-16">
        <Outlet />
      </main>
    </div>
  );
}
