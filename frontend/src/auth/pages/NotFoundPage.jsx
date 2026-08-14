import { Link } from 'react-router-dom';
import Button from '../../shared/components/ui/Button';
import Card from '../../shared/components/ui/Card';

export default function NotFoundPage() {
  return (
    <Card className="w-full max-w-md p-8 text-center">
      <p className="text-5xl font-semibold text-accent-fg">404</p>
      <h2 className="mt-3 text-xl font-semibold tracking-tight text-fg">Page introuvable</h2>
      <p className="mt-1 text-[13px] text-fg-3">La page que vous recherchez n'existe pas ou a été déplacée.</p>
      <Link to="/signin" className="mt-6 inline-block">
        <Button variant="primary">Retour à l'accueil</Button>
      </Link>
    </Card>
  );
}
