import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import Button from '../../shared/components/ui/Button';
import Card from '../../shared/components/ui/Card';
import Field, { TextInput } from '../../shared/components/ui/Field';
import Logo from '../../shared/components/ui/Logo';
import { BRAND } from '../../shared/brand';

export default function SignInPage() {
  const { signIn } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ username: '', password: '' });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError('');
    setLoading(true);
    try {
      const u = await signIn(form);
      navigate(u.is_admin ? '/admin' : '/chat', { replace: true });
    } catch (err) {
      setError(err.errors?.username?.[0] || err.message || 'Échec de la connexion');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen w-full items-center justify-center bg-[#F1F3F7] p-4">
      <Card className="w-full max-w-md border-[#E0E3E8] p-8 shadow-[0_12px_32px_rgba(48,54,77,0.08)]">
        <div className="flex items-center gap-3">
          <div className="flex h-12 w-12 items-center justify-center rounded-full bg-[#EDF4FF]">
            <Logo size="md" />
          </div>
          <div>
            <h2 className="text-xl font-semibold tracking-tight text-[#30364D]">Se connecter</h2>
            <p className="mt-1 text-[13px] text-[#777B85]">Accédez à votre espace {BRAND.name}.</p>
          </div>
        </div>

        <form className="mt-6 space-y-4" onSubmit={handleSubmit} id="signin-form">
          <Field label="Identifiant" htmlFor="signin-username">
            <TextInput
              id="signin-username"
              type="text"
              placeholder="votre-identifiant"
              value={form.username}
              onChange={(e) => setForm({ ...form, username: e.target.value })}
              required
              autoComplete="username"
            />
          </Field>
          <Field label="Mot de passe" htmlFor="signin-password">
            <TextInput
              id="signin-password"
              type="password"
              placeholder="••••••••"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              required
              autoComplete="current-password"
            />
          </Field>

          {error && <p className="text-[12.5px] text-[#F04444]" role="alert">{error}</p>}

          <Button id="signin-submit" variant="primary" className="w-full" type="submit" disabled={loading}>
            {loading ? 'Connexion…' : 'Se connecter'}
          </Button>
        </form>
      </Card>
    </div>
  );
}
