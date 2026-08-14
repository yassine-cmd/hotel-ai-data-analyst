import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { LogOut } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import Button from '../../shared/components/ui/Button';
import Card from '../../shared/components/ui/Card';

export default function LogoutPage() {
  const { signOut } = useAuth();

  useEffect(() => {
    signOut();
  }, [signOut]);

  return (
    <Card className="w-full max-w-md p-10 text-center">
      <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-accent/10">
        <LogOut className="h-6 w-6 text-accent" aria-hidden />
      </div>
      <h1 className="mt-5 text-xl font-semibold tracking-tight text-fg">Vous avez été déconnecté</h1>
      <p className="mt-1 text-[13px] text-fg-3">Votre session a été fermée. À bientôt !</p>
      <div className="mt-6 flex items-center justify-center gap-2.5">
        <Link to="/signin" id="logout-signin-link"><Button variant="primary">Se reconnecter</Button></Link>
        <Link to="/" id="logout-home-link"><Button variant="ghost">Retour à l'accueil</Button></Link>
      </div>
    </Card>
  );
}
