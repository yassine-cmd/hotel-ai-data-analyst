import { useEffect, useRef } from 'react';
import { ChevronDown } from 'lucide-react';
import MessageBubble from './MessageBubble';
import QuestionCard from './QuestionCard';
import Markdown from '../../shared/components/Markdown';
import ChartRenderer from '../../shared/components/ChartRenderer';
import EmptyState from '../../shared/components/ui/EmptyState';
import { Spinner } from '../../shared/components/ui/Spinner';
import { BRAND } from '../../shared/brand';

function ThinkingStrip({ message, isStreaming, onTogglePanel, active }) {
  const blocks = message?.blocks || [];
  const runningTool = [...blocks].reverse().find((b) => b.type === 'tool' && b.status === 'running');
  const steps = message?.steps;
  const hasSteps = blocks.some((b) => b.type === 'thinking' || b.type === 'tool');

  if (!isStreaming && !hasSteps) return null;

  const toolDesc = runningTool?.description;
  let labelText;
  if (isStreaming) {
    labelText = toolDesc || 'Réflexion…';
  } else if (steps != null) {
    labelText = `A réfléchi pendant ${steps} étape${steps !== 1 ? 's' : ''}`;
  } else {
    labelText = 'Processus de réflexion';
  }

  return (
    <button
      type="button"
      className={`group inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition-all duration-200 select-none ${
        active
          ? 'border-accent/50 bg-accent/10 text-accent shadow-xs'
          : 'border-line/60 bg-surface/80 text-fg-2 hover:border-line-strong hover:bg-raised hover:text-fg'
      }`}
      onClick={() => onTogglePanel(message)}
      aria-label={active ? 'Fermer le panneau de réflexion' : 'Ouvrir le panneau de réflexion'}
      aria-expanded={active}
      aria-controls="agent-loop-panel"
    >
      {/* Live / Status Indicator */}
      <span className="relative flex h-2 w-2 shrink-0 items-center justify-center">
        {isStreaming ? (
          <>
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent/60 opacity-75" />
            <span className="relative inline-flex h-2 w-2 rounded-full bg-accent" />
          </>
        ) : (
          <span className={`h-2 w-2 rounded-full transition-colors ${active ? 'bg-accent' : 'bg-fg-3 group-hover:bg-fg-2'}`} />
        )}
      </span>

      {/* Dynamic Text Label */}
      <span className="truncate max-w-[16rem] font-medium">
        {labelText}
      </span>

      {/* Chevron Collapse/Expand Indicator */}
      <ChevronDown
        className={`h-3.5 w-3.5 shrink-0 transition-transform duration-200 ${
          active ? 'rotate-180 text-accent' : 'text-fg-3 group-hover:text-fg'
        }`}
      />
    </button>
  );
}

function RestoringChat() {
  return (
    <div className="mx-auto flex w-full max-w-[560px] flex-1 flex-col items-center justify-center gap-4 px-6 text-center" role="status" aria-live="polite">
      <div className="grid h-16 w-16 place-items-center rounded-2xl bg-[#ecfaff]">
        <Spinner className="h-7 w-7 text-[#43A7BA]" />
      </div>
      <div>
        <p className="text-sm font-medium text-[#30364D]">Restauration de la conversation…</p>
        <p className="mt-1 text-[11px] text-[#777B85]">Chargement de l'historique de session depuis le disque.</p>
      </div>
    </div>
  );
}

function EmptyChat({ onSend }) {
  return (
    <EmptyState
      title="Comment puis-je vous aider ?"
      description="Posez une question pour lancer une analyse."
      className="mx-auto w-full max-w-[560px] flex-1"
    >
      <p className="text-[11px] text-fg-3">{BRAND.name} · {BRAND.tagline}</p>
    </EmptyState>
  );
}

const TOKEN_RE = /\[CHART_[\w-]+\]/g;

function AnswerFlow({ block, specByToken, header }) {
  const content = block.content || '';
  const parts = content.split(TOKEN_RE);
  const tokens = content.match(TOKEN_RE) || [];
  const stripped = content.replace(TOKEN_RE, '').trim();
  const children = [];
  parts.forEach((seg, i) => {
    if (seg.trim()) children.push(<div key={`t${i}`}><Markdown>{seg}</Markdown></div>);
    if (i < tokens.length) {
      const chartBlock = specByToken.get(tokens[i]);
      if (chartBlock) children.push(<ChartRenderer key={`c${i}`} spec={chartBlock.result.chart_spec} />);
    }
  });
  if (!children.length) return null;
  return (
    <MessageBubble message={{ role: 'assistant' }} copyText={stripped || null}>
      <div className="flex flex-col gap-3">
        {header}
        {children}
      </div>
    </MessageBubble>
  );
}

export default function ChatWindow({ messages, isStreaming, onSend, onTogglePanel, panelOpen, activeMessageId, loadingHistory, sessionError, onDismissSessionError }) {
  const end = useRef(null);
  const pinned = useRef(true);
  useEffect(() => { if (pinned.current) end.current?.scrollIntoView?.({ block: 'end' }); }, [messages]);

  return <div className="chat-container flex min-w-0 flex-1 flex-col overflow-y-auto px-4 py-8 md:px-6" onScroll={(event) => {
    const node = event.currentTarget;
    pinned.current = node.scrollHeight - node.scrollTop - node.clientHeight < 120;
  }}>
    {messages.length === 0 ? (
      loadingHistory ? <RestoringChat /> : <EmptyChat onSend={onSend} />
    ) : <div className="mx-auto flex w-full max-w-[820px] flex-col gap-7">
      {sessionError && (
        <div className="flex flex-col gap-2 rounded-lg border border-[#F04444]/25 bg-[#fef2f2] px-4 py-3 text-[13px] text-[#F04444]">
          <span className="font-medium">La dernière analyse ne s'est pas terminée.</span>
          <span>{sessionError.message}</span>
          <div className="flex gap-2">
            {sessionError.retryable !== false && sessionError.query && (
              <button
                type="button"
                onClick={() => { onSend(sessionError.query); onDismissSessionError?.(); }}
                className="rounded-md bg-[#fef2f2] px-3 py-1 text-xs font-medium text-[#F04444] transition-colors hover:bg-[#fde7e7]"
              >
                 Réessayer
              </button>
            )}
            {onDismissSessionError && (
              <button
                type="button"
                onClick={onDismissSessionError}
                className="rounded-md px-3 py-1 text-xs font-medium text-[#777B85] transition-colors hover:bg-[#e9eef4]"
              >
                 Ignorer
              </button>
            )}
          </div>
        </div>
      )}
      {messages.flatMap((message) => {
        if (message.content) {
          return [
            <MessageBubble key={message.id} message={message}>
              <div className="bubble-content whitespace-pre-wrap">{message.content}</div>
            </MessageBubble>,
          ];
        }

        const blocks = message.blocks || [];
        const answers = blocks.filter((block) => block.type === 'text');
        const questions = blocks.filter((block) => block.type === 'questions');
        const chartBlocks = blocks.filter((block) => block.type === 'result' && block.result?.chart_spec);
        const imageBlocks = blocks.filter((block) => block.type === 'result' && block.result?.image_urls?.length);
        const showStrip = (isStreaming && message.streaming) || blocks.some((b) => b.type === 'thinking' || b.type === 'tool');
        const strip = showStrip && (
          <ThinkingStrip message={message} isStreaming={isStreaming && message.streaming} onTogglePanel={onTogglePanel} active={panelOpen && activeMessageId === message.id} />
        );

        const specByToken = new Map();
        chartBlocks.forEach((block) => {
          const spec = block.result.chart_spec;
          const token = spec.token || (spec.chart_id ? `[${spec.chart_id}]` : null);
          if (token) specByToken.set(token, block);
        });
        const referenced = new Set();
        answers.forEach((block) => {
          (block.content || '').match(TOKEN_RE)?.forEach((token) => {
            const chartBlock = specByToken.get(token);
            if (chartBlock) referenced.add(chartBlock.id);
          });
        });
        const orphanCharts = message.streaming ? [] : chartBlocks.filter((block) => !referenced.has(block.id));
        const answerFlow = answers.map((block, i) => <AnswerFlow key={block.id} block={block} specByToken={specByToken} header={i === 0 ? strip : null} />);
        const standaloneStrip = answers.length === 0 && strip && (
          <MessageBubble key={`strip-${message.id}`} message={{ role: 'assistant' }}>
            <div className="flex justify-start">{strip}</div>
          </MessageBubble>
        );

        return [
          standaloneStrip,
          ...answerFlow,
          ...orphanCharts.map((block) => <ChartRenderer key={block.id} spec={block.result.chart_spec} />),
          ...imageBlocks.flatMap((block) => (block.result.image_urls || []).map((url, i) => <ChartRenderer key={`${block.id}-img-${i}`} imageUrl={url} />)),
          ...questions.map((block) => <QuestionCard key={block.id} questions={block.questions} onSend={onSend} />),
          message.error && (
            <div key={`${message.id}-error`} className="flex flex-col gap-2 rounded-lg border border-[#F04444]/25 bg-[#fef2f2] px-4 py-3 text-[13px] text-[#F04444]">
              <span>{message.error.message}</span>
              {!isStreaming && message.error.retryable !== false && (() => {
                const idx = messages.findIndex((m) => m.id === message.id);
                let prevContent = null;
                for (let j = idx - 1; j >= 0; j--) {
                  if (messages[j].role === 'user' && messages[j].content) {
                    prevContent = messages[j].content;
                    break;
                  }
                }
                return prevContent ? (
                  <button
                    type="button"
                    onClick={() => onSend(prevContent)}
                    className="self-start rounded-md bg-[#fef2f2] px-3 py-1 text-xs font-medium text-[#F04444] transition-colors hover:bg-[#fde7e7]"
                  >
                     Réessayer
                   </button>
                ) : null;
              })()}
            </div>
          ),
        ].filter(Boolean);
      })}
    </div>}
    <div ref={end} />
  </div>;
}