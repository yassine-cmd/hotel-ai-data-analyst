import { useEffect } from 'react';
import { Link, useOutletContext } from 'react-router-dom';
import { ArrowLeft, MessageSquare } from 'lucide-react';
import Button from '../../shared/components/ui/Button';
import Card from '../../shared/components/ui/Card';
import EmptyState from '../../shared/components/ui/EmptyState';
import PageHeader from '../../shared/components/ui/PageHeader';
import { LoadingState } from '../../shared/components/ui/Spinner';

export default function HistoryPage() {
  const { session } = useOutletContext();

  useEffect(() => {
    session.load().catch((err) => console.warn('Session load failed', err));
  }, [session.load]);

  if (session.loading) {
    return <LoadingState className="flex-1 justify-center" label="Chargement de l'historique…" />;
  }

  if (session.error) {
    return (
      <div className="flex flex-1 items-center justify-center">
        <p className="text-sm text-danger">Échec du chargement de l'historique.</p>
      </div>
    );
  }

  return (
    <div className="page-shell flex-1 overflow-y-auto">
      <div className="mx-auto w-full max-w-[960px] p-6 md:p-8">
        <PageHeader
          title="Historique des conversations"
          description="Questions posées dans vos conversations enregistrées."
          actions={<Link to="/chat"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-3.5 w-3.5" /> Retour</Button></Link>}
        />
        <Card className="overflow-hidden">
          {session.history.length ? (
            <ul className="divide-y divide-line">
              {session.history.map((turn, index) => (
                <li key={index} className="flex items-start gap-3 px-5 py-4">
                  <MessageSquare className="mt-0.5 h-4 w-4 shrink-0 text-fg-3" />
                  <span className="text-[13.5px] leading-relaxed text-fg-2">{turn.query}</span>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState
              title="Aucun message enregistré"
              description="Posez une question à l'assistant pour commencer."
            />
          )}
        </Card>
      </div>
    </div>
  );
}
