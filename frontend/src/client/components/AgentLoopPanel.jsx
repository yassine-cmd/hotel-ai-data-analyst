import { useEffect, useMemo, useRef, useState } from 'react';
import {
  BarChart3, Brain, Check, ChevronRight, Code2, Copy, Database,
  FoldVertical, MessageCircleQuestion, Table as TableIcon,
  UnfoldVertical, Wrench, X,
} from 'lucide-react';
import Markdown from '../../shared/components/Markdown';
import EmptyState from '../../shared/components/ui/EmptyState';

/* ------------------------------- status tones ------------------------------ */

const STATUS_NODE = {
  error: 'bg-rose-500',
  partial: 'bg-amber-500',
  running: 'bg-amber-500',
  success: 'bg-emerald-500',
};

const STATUS_CHIP = {
  error: 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
  partial: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
  running: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
  success: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

const STATUS_PILL = {
  error: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
  partial: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
  running: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20 animate-pulse',
  success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
};

const STATUS_LABEL = { error: 'Erreur', partial: 'Partiel', running: 'En cours…', success: 'Réussi' };

/* -------------------------------- tool icons ------------------------------- */

const TOOL_ICONS = {
  describe_table: TableIcon,
  execute_sql: Database,
  run_python: Code2,
  create_chart_spec: BarChart3,
  question: MessageCircleQuestion,
};

const toolIconFor = (name) => TOOL_ICONS[name] || Wrench;

/* --------------------------- timeline construction -------------------------- */

/**
 * Parses streaming blocks into a flat, chronological timeline sequence.
 * Thoughts and tools become independent timeline nodes; a tool consumes the
 * result block that immediately follows it. Step numbers come from each
 * block's `n`, falling back to a counter advanced by thought blocks.
 */
function buildTimelineItems(blocks) {
  const items = [];
  let fallbackStep = 0;

  const stepFor = (block, isThought) => {
    if (block.n != null) return block.n;
    return isThought ? ++fallbackStep : (fallbackStep > 0 ? fallbackStep : ++fallbackStep);
  };

  for (let i = 0; i < blocks.length; i++) {
    const block = blocks[i];

    if (block.type === 'thinking') {
      items.push({
        id: block.id || `thought_${i}`,
        type: 'thought',
        stepNum: stepFor(block, true),
        content: block.content || '',
      });
      continue;
    }

    if (block.type === 'result') {
      if (blocks[i - 1] && blocks[i - 1].type === 'tool') continue; // handled with its tool
      const resStatus = block.result?.status;
      const failed = resStatus === 'error' || resStatus === 'partial';
      items.push({
        id: block.id || `result_${i}`,
        type: 'orphan_result',
        stepNum: stepFor(block, false),
        tool: block.tool || null,
        result: block.result || {},
        status: failed ? (resStatus === 'partial' ? 'partial' : 'error') : 'success',
      });
      continue;
    }

    if (block.type !== 'tool') continue; // text/questions/etc. render in the chat body, not the drawer

    const args = block.args || {};
    const sqls = Array.isArray(args.queries) ? args.queries.map((q) => q?.sql).filter(Boolean) : [];
    const toolItem = {
      id: block.id || `tool_${i}`,
      type: 'tool',
      stepNum: stepFor(block, false),
      action: block.tool || 'tool_call',
      query: args.query || args.sql || args.command || args.code || (sqls.length ? sqls.join('\n\n---\n\n') : ''),
      args,
      result: block.result || null,
      description: block.description || '',
      error: block.error || null,
      status: block.status || 'running',
    };

    // Attach an immediately-following result block, if any
    const nextBlock = blocks[i + 1];
    if (nextBlock && nextBlock.type === 'result') {
      const res = nextBlock.result || {};
      toolItem.result = res;
      const resStatus = res.status;
      if (resStatus === 'error' || nextBlock.error) {
        toolItem.status = 'error';
        toolItem.error = nextBlock.error || res.error || res.message || null;
      } else if (resStatus === 'partial') {
        toolItem.status = 'partial';
      } else if (toolItem.status !== 'running') {
        toolItem.status = 'success';
      }
    } else if (toolItem.status !== 'running' && !toolItem.error) {
      if (toolItem.result?.status === 'partial') {
        toolItem.status = 'partial';
      } else if (toolItem.result?.status === 'error') {
        toolItem.status = 'error';
      } else if (toolItem.status === 'complete' || toolItem.result) {
        toolItem.status = 'success';
      }
    }

    items.push(toolItem);
  }

  return items;
}

/* -------------------------------- primitives -------------------------------- */

function TimelinePoint({ type, status }) {
  if (type === 'thought') {
    return (
      <span className="relative z-10 grid h-5 w-5 place-items-center">
        <span className="h-2.5 w-2.5 rounded-full bg-slate-400 ring-4 ring-surface dark:bg-slate-500" />
      </span>
    );
  }
  return (
    <span className="relative z-10 grid h-5 w-5 place-items-center">
      {status === 'running' && (
        <span className="absolute h-4 w-4 animate-ping rounded-full bg-amber-500/40" />
      )}
      <span className={`h-3 w-3 rounded-full ${STATUS_NODE[status] || 'bg-emerald-500'} ring-4 ring-surface shadow-2xs`} />
    </span>
  );
}

function StatusPill({ status }) {
  if (status === 'success') return null; // success is implied by the green node/chip
  return (
    <span className={`rounded-full border px-2 py-0.5 font-mono text-[10px] font-semibold tracking-wider uppercase ${STATUS_PILL[status]}`}>
      {STATUS_LABEL[status]}
    </span>
  );
}

function IconChip({ icon: Icon, status }) {
  return (
    <span className={`grid h-6 w-6 shrink-0 place-items-center rounded-md border ${STATUS_CHIP[status] || STATUS_CHIP.success}`}>
      <Icon className="h-3.5 w-3.5" />
    </span>
  );
}

function CopyBtn({ text, label = 'Copier' }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    if (typeof navigator === 'undefined' || !navigator.clipboard) return;
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      /* clipboard disabled */
    }
  };
  return (
    <button
      type="button"
      aria-label={label}
      onClick={copy}
      className="grid h-5 w-5 shrink-0 place-items-center rounded text-fg-3 transition-colors hover:bg-overlay-weak hover:text-fg"
    >
      {copied ? <Check className="h-3 w-3 text-emerald-500" /> : <Copy className="h-3 w-3" />}
    </button>
  );
}

function QueryBlock({ text }) {
  return (
    <pre className="m-0 whitespace-pre-wrap break-all rounded border border-line bg-base/60 p-2 font-mono text-xs text-fg-3">{text}</pre>
  );
}

function Dots() {
  return (
    <span className="inline-flex items-center gap-1" aria-hidden="true">
      {[0, 1, 2].map((i) => (
        <span key={i} className="h-1.5 w-1.5 animate-bounce rounded-full bg-accent/70" style={{ animationDelay: `${i * 150}ms` }} />
      ))}
    </span>
  );
}

function CollapsibleSection({ label, tag, copyText, copyLabel, children }) {
  const [open, setOpen] = useState(true);
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-center justify-between gap-2">
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          className="flex min-w-0 cursor-pointer select-none items-center gap-1.5 text-fg-3 transition-colors hover:text-fg"
        >
          <ChevronRight className={`h-3 w-3 shrink-0 transition-transform duration-200 ${open ? 'rotate-90' : ''}`} />
          <span className="min-w-0 truncate font-mono text-[10px] font-bold tracking-wider">{label}</span>
          {tag && <span className="rounded bg-overlay-weak px-1.5 py-px font-mono text-[10px] text-fg-3">{tag}</span>}
        </button>
        {copyText != null && copyText !== '' && <CopyBtn text={copyText} label={copyLabel || `Copier ${label}`} />}
      </div>
      <div className={`grid transition-[grid-template-rows] duration-200 ${open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}>
        <div className="min-h-0 overflow-hidden">{children}</div>
      </div>
    </div>
  );
}

/* --------------------------------- results --------------------------------- */

const fmtNum = (n) => (typeof n === 'number' ? n.toLocaleString('en-US') : String(n ?? ''));

function ChartSpecBlock({ spec, token }) {
  const fields = [
    spec.title && ['Titre', spec.title],
    spec.x && ['X', spec.x],
    spec.y && ['Y', Array.isArray(spec.y) ? spec.y.join(', ') : spec.y],
    spec.group_by && ['Regrouper par', spec.group_by],
  ].filter(Boolean);

  return (
    <div className="rounded border border-line bg-base/60 p-2">
      <div className="mb-1.5 flex flex-wrap items-center gap-1.5">
        {token && <span className="rounded bg-accent/10 px-1.5 py-0.5 font-mono text-[10px] font-bold text-accent">{token}</span>}
        {spec.chart_type && <span className="rounded bg-overlay-weak px-1.5 py-0.5 font-mono text-[10px] font-bold text-fg">{spec.chart_type}</span>}
        {spec.df_name && <span className="rounded bg-overlay-weak px-1.5 py-0.5 font-mono text-[10px] text-fg-2">{spec.df_name}</span>}
        {spec.render_count != null && (
          <span className="rounded bg-overlay-weak px-1.5 py-0.5 font-mono text-[10px] text-fg-2">
            {fmtNum(spec.true_row_count ?? spec.render_count)} ligne{spec.true_row_count === 1 ? '' : 's'}
            {spec.true_row_count != null && spec.true_row_count !== spec.render_count ? ` (affiché ${fmtNum(spec.render_count)})` : ''}
          </span>
        )}
        {spec.decimated && <span className="rounded bg-amber-500/10 px-1.5 py-0.5 font-mono text-[10px] text-amber-500">décimé</span>}
      </div>
      {fields && fields.length > 0 && (
        <div className="grid gap-0.5 font-mono text-xs text-fg-2">
          {fields.map(([k, v]) => (
            <div key={k} className="flex items-baseline gap-2">
              <span className="shrink-0 text-fg-3">{k}:</span>
              <span className="min-w-0 break-all whitespace-pre-wrap">{v}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function ResultSection({ data, tool, error }) {
  const errorMsg = error || data?.error || null;
  const results = Array.isArray(data?.results) ? data.results : null;
  const outputText = data?.output != null ? (typeof data.output === 'string' ? data.output : JSON.stringify(data.output, null, 2)) : null;
  const hasContent = !!errorMsg || !!results || outputText != null || !!data?.chart_spec || data?.described != null || data?.asked != null || Object.keys(data || {}).length > 0;

  if (!hasContent) return null;

  const label = `Résultat${tool ? ` · ${tool}` : ''}`;

  return (
    <CollapsibleSection label={label} copyText={outputText || null} copyLabel="Copier le résultat">
      <div className="flex flex-col gap-2">
        {errorMsg && (
          <div className="rounded border border-rose-500/30 bg-rose-500/10 px-2.5 py-1.5 font-mono text-xs text-rose-500">
            <pre className="m-0 whitespace-pre-wrap break-all">{errorMsg}</pre>
          </div>
        )}
        {data?.chart_spec && <ChartSpecBlock spec={data.chart_spec} token={data.token || data.chart_spec.token || null} />}
        {results && results.map((r, i) => {
          const rows = r.true_row_count ?? r.shape?.[0];
          const cols = r.columns?.length ?? r.shape?.[1];
          return (
            <div key={`${r.df_name || i}-${i}`} className="flex flex-col gap-1.5 rounded border border-line bg-base/60 p-2">
              <div className="flex flex-wrap items-center gap-1.5">
                <span className="rounded bg-overlay-weak px-1.5 py-0.5 font-mono text-[10px] font-bold text-fg">{r.df_name || 'résultat'}</span>
                <span className="rounded bg-accent/10 px-1.5 py-0.5 font-mono text-[10px] text-accent">
                  {rows != null ? `${fmtNum(rows)} ligne${rows === 1 ? '' : 's'}` : ''}
                  {rows != null && cols != null ? ' · ' : ''}
                  {cols != null ? `${cols} colonne${cols === 1 ? '' : 's'}` : ''}
                </span>
                {r.truncated && <span className="rounded bg-amber-500/10 px-1.5 py-0.5 font-mono text-[10px] text-amber-500">tronqué</span>}
              </div>
              {r.columns?.length > 0 && (
                <div className="flex flex-wrap gap-1">
                  {r.columns.map((c) => (
                    <span key={c} className="rounded bg-overlay-weak px-1.5 py-0.5 font-mono text-[10px] text-fg-2">
                      {c}{r.dtypes?.[c] ? `: ${r.dtypes[c]}` : ''}
                    </span>
                  ))}
                </div>
              )}
            </div>
          );
        })}
        {outputText != null && (
          <pre className="m-0 max-h-40 overflow-auto whitespace-pre-wrap break-all rounded border border-line bg-base/60 p-2 font-mono text-xs text-fg-3">
            {outputText}
          </pre>
        )}
      </div>
    </CollapsibleSection>
  );
}

/* ------------------------------ tool arguments ------------------------------ */

const COVERED_ARGS = {
  execute_sql: ['queries', 'query', 'sql'],
  run_sql: ['queries', 'query', 'sql'],
  run_python: ['code', 'command'],
  describe_table: ['tables', 'table'],
  create_chart_spec: ['chart_type', 'x', 'y', 'title'],
  question: ['questions'],
};

function ArgumentsSection({ item }) {
  const params = item.args || {};
  const covered = new Set(COVERED_ARGS[item.action] || []);
  const leftover = Object.entries(params).filter(([k]) => !covered.has(k) && k !== 'action');
  const paramsText = Object.keys(params).length > 0 ? JSON.stringify(params, null, 2) : '';

  const isSql = item.action === 'execute_sql' || item.action === 'run_sql';
  const isPy = item.action === 'run_python';
  const isDescribe = item.action === 'describe_table';
  const isChart = item.action === 'create_chart_spec';

  const showQuery = (isSql || isPy) && !!item.query;
  const describeTables = isDescribe ? (Array.isArray(params.tables) ? params.tables : params.table ? [params.table] : []) : [];
  const showChart = isChart && [params.chart_type, params.x, params.y, params.title].some((v) => v != null);

  if (!showQuery && describeTables.length === 0 && !showChart && leftover.length === 0) return null;

  const isQuery = isSql || isPy;
  const tag = isPy ? 'Python' : isSql ? 'SQL' : null;
  const copyText = isQuery ? (item.query || paramsText) : paramsText;

  return (
    <CollapsibleSection label={isQuery ? 'Requête' : 'Détails'} tag={tag} copyText={copyText || null} copyLabel={isQuery ? 'Copier la requête' : 'Copier les détails'}>
      <div className="flex flex-col gap-2">
        {showQuery && <QueryBlock text={item.query} />}

        {isDescribe && describeTables.length > 0 && (
          <div className="font-mono text-xs text-fg-2">
            {describeTables.map((t) => (
              <span key={t} className="mr-2 rounded bg-overlay-weak px-1.5 py-0.5 font-medium text-fg">{t}</span>
            ))}
          </div>
        )}

        {showChart && (
          <div className="flex flex-col gap-1 font-mono text-xs text-fg-2">
            {params.chart_type && <div><span className="text-fg-3">Type :</span> {params.chart_type}</div>}
            {params.x && <div><span className="text-fg-3">X :</span> {params.x}</div>}
            {params.y && <div><span className="text-fg-3">Y :</span> {Array.isArray(params.y) ? params.y.join(', ') : params.y}</div>}
            {params.title && <div><span className="text-fg-3">Titre :</span> {params.title}</div>}
          </div>
        )}

        {leftover.length > 0 && (
          <div className="flex flex-col gap-1">
            {leftover.map(([k, v]) => (
              <div key={k} className="flex items-baseline gap-2 font-mono text-xs leading-snug">
                <span className="shrink-0 font-medium text-fg-3">{k}:</span>
                <span className="min-w-0 break-all whitespace-pre-wrap text-fg-2">{typeof v === 'string' ? v : JSON.stringify(v, null, 2)}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </CollapsibleSection>
  );
}

/* --------------------------------- tool card -------------------------------- */

function ToolCard({ item, bulk }) {
  const [collapsed, setCollapsed] = useState(item.status !== 'running');
  const userPinned = useRef(false);
  const wasRunning = useRef(item.status === 'running');

  useEffect(() => {
    if (bulk?.action) {
      setCollapsed(bulk.action === 'collapse');
      userPinned.current = false;
    }
  }, [bulk]);

  useEffect(() => {
    if (item.status === 'running') {
      wasRunning.current = true;
      setCollapsed(false);
    } else if (wasRunning.current && !userPinned.current) {
      wasRunning.current = false;
      setCollapsed(true);
    }
  }, [item.status]);

  const toggle = () => {
    if (item.status === 'running') userPinned.current = true;
    setCollapsed((c) => !c);
  };

  const ToolIcon = toolIconFor(item.action);

  return (
    <div className="overflow-hidden rounded-xl border border-line bg-raised shadow-2xs transition-all hover:border-line-strong/80">
      <button
        type="button"
        onClick={toggle}
        className="flex w-full cursor-pointer select-none items-center justify-between gap-2 px-3 py-2.5 transition-colors hover:bg-overlay-weak"
      >
        <div className="flex min-w-0 items-center gap-2.5">
          <IconChip icon={ToolIcon} status={item.status} />
          <span className="truncate text-xs font-semibold text-fg">{item.description || item.action}</span>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <StatusPill status={item.status} />
          <ChevronRight className={`h-3.5 w-3.5 text-fg-3 transition-transform duration-200 ${collapsed ? '' : 'rotate-90'}`} />
        </div>
      </button>

      <div className={`grid transition-[grid-template-rows] duration-200 ${collapsed ? 'grid-rows-[0fr]' : 'grid-rows-[1fr]'}`}>
        <div className="overflow-hidden">
          <div className="flex flex-col gap-2.5 border-t border-line/60 p-3">
            <ArgumentsSection item={item} />
            <ResultSection data={item.result} tool={item.action} error={item.error} />
          </div>
        </div>
      </div>
    </div>
  );
}

/* --------------------------------- helpers --------------------------------- */

const fmtDur = (ms) => {
  if (ms == null) return '';
  const s = Math.max(0, Math.round(ms / 1000));
  if (s < 60) return `${s}s`;
  return `${Math.floor(s / 60)}m ${s % 60}s`;
};

const MIN_DRAWER_WIDTH = 320;

/**
 * Tracks elapsed time of a live agent run. The clock only starts once items
 * appear and only counts a run that was observed streaming, so historical
 * (already-completed) sessions never show a duration.
 */
function useRunElapsed({ panelOpen, isStreaming, hasItems }) {
  const [now, setNow] = useState(Date.now());
  const runStartRef = useRef(null);
  const finishedRef = useRef(null);
  const observedRef = useRef(false);

  useEffect(() => {
    if (isStreaming) {
      observedRef.current = true;
      runStartRef.current = null;
      finishedRef.current = null;
    } else if (observedRef.current && hasItems && runStartRef.current != null && finishedRef.current == null) {
      finishedRef.current = Date.now();
    }
  }, [isStreaming, hasItems]);

  useEffect(() => {
    if (hasItems && runStartRef.current == null) runStartRef.current = Date.now();
  }, [hasItems]);

  useEffect(() => {
    if (!panelOpen || !isStreaming) return;
    const t = setInterval(() => setNow(Date.now()), 500);
    return () => clearInterval(t);
  }, [panelOpen, isStreaming]);

  const start = runStartRef.current;
  if (start == null) return { elapsed: null, show: false };
  return { elapsed: (finishedRef.current ?? now) - start, show: observedRef.current };
}

/* ---------------------------------- panel ---------------------------------- */

export default function AgentLoopPanel({ blocks, isStreaming, onClose, panelOpen, maxSteps, width = 480, onResize, onResizeStart, onResizeEnd }) {
  const items = useMemo(() => buildTimelineItems(blocks || []), [blocks]);
  const bodyRef = useRef(null);
  const pinnedRef = useRef(true);
  const touchRef = useRef(null);
  const dragRef = useRef(null);
  const [bulk, setBulk] = useState({ action: null, nonce: 0 });
  const { elapsed, show: showDuration } = useRunElapsed({ panelOpen, isStreaming, hasItems: items.length > 0 });

  const bulkAll = (action) => setBulk((b) => ({ action, nonce: b.nonce + 1 }));

  const stepCount = useMemo(() => new Set(items.map((i) => i.stepNum)).size, [items]);
  const toolCount = useMemo(() => items.filter((i) => i.type === 'tool').length, [items]);

  const maxDrawerWidth = () => Math.max(MIN_DRAWER_WIDTH, (typeof window !== 'undefined' ? window.innerWidth : 0) - 360);

  const onResizePointerDown = (e) => {
    if (e.button != null && e.button !== 0) return;
    e.preventDefault();
    dragRef.current = { startX: e.clientX, startWidth: width };
    onResizeStart?.();
    e.currentTarget.setPointerCapture?.(e.pointerId);
  };

  const onResizePointerMove = (e) => {
    if (!dragRef.current) return;
    const next = dragRef.current.startWidth + (dragRef.current.startX - e.clientX);
    onResize?.(Math.min(Math.max(MIN_DRAWER_WIDTH, next), maxDrawerWidth()));
  };

  const onResizePointerUp = (e) => {
    if (!dragRef.current) return;
    dragRef.current = null;
    onResizeEnd?.();
    e.currentTarget.releasePointerCapture?.(e.pointerId);
  };

  let metaText;
  if (items.length === 0) {
    metaText = isStreaming ? 'En attente de l’analyse…' : 'Inactif';
  } else if (isStreaming) {
    metaText = toolCount === 0
      ? 'Réflexion en cours…'
      : `Étape ${items[items.length - 1]?.stepNum || 1}${maxSteps ? ` sur ${maxSteps}` : ''}${showDuration ? ` · ${fmtDur(elapsed)}` : ''}`;
  } else {
    metaText = `${stepCount} étape${stepCount === 1 ? '' : 's'} · ${toolCount} appel${toolCount === 1 ? '' : 's'} d'outil${toolCount === 1 ? '' : 's'}${showDuration ? ` · ${fmtDur(elapsed)}` : ''}`;
  }

  useEffect(() => {
    if (!panelOpen) return;
    pinnedRef.current = true;
    if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [panelOpen]);

  useEffect(() => {
    if (!pinnedRef.current) return;
    if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [items]);

  useEffect(() => {
    if (!panelOpen) return;
    const handler = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [panelOpen, onClose]);

  const onTouchStart = (e) => { touchRef.current = { y: e.touches[0].clientY }; };
  const onTouchMove = (e) => {
    if (!touchRef.current) return;
    const dy = e.touches[0].clientY - touchRef.current.y;
    if (dy > 90 && (bodyRef.current?.scrollTop ?? 0) <= 0) {
      touchRef.current = null;
      onClose();
    }
  };

  return (
    <>
      {/* Mobile Backdrop */}
      {panelOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/40 backdrop-blur-xs transition-opacity md:hidden"
          onClick={onClose}
          aria-hidden="true"
        />
      )}

      <aside
        id="agent-loop-panel"
        aria-label="Chronologie de l'activité de l'agent"
        className={`group fixed inset-y-0 right-0 z-40 flex w-full flex-col border-l border-line bg-surface shadow-2xl transition-transform duration-300 md:w-[var(--drawer-width)] ${
          panelOpen ? 'translate-x-0' : 'translate-x-full'
        }`}
        onTouchStart={onTouchStart}
        onTouchMove={onTouchMove}
        onTouchEnd={() => { touchRef.current = null; }}
      >
        {/* Desktop Horizontal Resize Handle */}
        <div
          role="separator"
          aria-orientation="vertical"
          aria-label="Redimensionner le panneau"
          aria-valuemin={MIN_DRAWER_WIDTH}
          aria-valuemax={maxDrawerWidth()}
          aria-valuenow={width}
          title="Faire glisser pour redimensionner"
          onPointerDown={onResizePointerDown}
          onPointerMove={onResizePointerMove}
          onPointerUp={onResizePointerUp}
          onPointerCancel={onResizePointerUp}
          className="absolute top-0 bottom-0 left-0 z-20 hidden w-2 cursor-ew-resize touch-none select-none md:block"
        >
          <span className="absolute inset-y-0 left-[3px] w-0.5 rounded-full bg-line-strong/60 transition-colors group-hover:bg-accent/70" />
        </div>

        {/* Panel Header */}
        <div className="relative flex h-13 shrink-0 items-center justify-between border-b border-line px-4">
          <span className="absolute top-2 left-1/2 h-1 w-10 -translate-x-1/2 rounded-full bg-overlay-strong md:hidden" aria-hidden="true" />

          <div className="flex items-center gap-2.5">
            <span className="grid h-8 w-8 place-items-center rounded-lg bg-accent/10">
              <Brain className={`h-4 w-4 text-accent ${isStreaming ? 'animate-pulse' : ''}`} />
            </span>
            <div className="flex flex-col leading-snug">
              <span className="text-xs font-bold tracking-tight text-fg">Processus de réflexion et outils</span>
              <span className="font-mono text-[11px] text-fg-3">{metaText}</span>
            </div>
          </div>

          <div className="flex items-center gap-1">
            <button
              type="button"
              aria-label="Déplier les détails de tous les outils"
              title="Déplier tout"
              onClick={() => bulkAll('expand')}
              className="grid h-7 w-7 place-items-center rounded-md text-fg-3 transition-colors hover:bg-overlay-weak hover:text-fg"
            >
              <UnfoldVertical className="h-4 w-4" />
            </button>
            <button
              type="button"
              aria-label="Replier les détails de tous les outils"
              title="Replier tout"
              onClick={() => bulkAll('collapse')}
              className="grid h-7 w-7 place-items-center rounded-md text-fg-3 transition-colors hover:bg-overlay-weak hover:text-fg"
            >
              <FoldVertical className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={onClose}
              aria-label="Fermer le panneau"
              className="grid h-7 w-7 place-items-center rounded-md text-fg-3 transition-colors hover:bg-overlay-weak hover:text-fg"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>

        {/* Streaming Progress Bar */}
        {isStreaming && (
          <div className="h-0.5 w-full overflow-hidden bg-overlay-weak">
            <div className="h-full w-full animate-pulse bg-accent/80" />
          </div>
        )}

        {/* Panel Timeline Stream Body */}
        <div
          ref={bodyRef}
          className="relative flex-1 overflow-y-auto px-4 py-5"
          onScroll={(e) => {
            const node = e.currentTarget;
            pinnedRef.current = node.scrollHeight - node.scrollTop - node.clientHeight < 100;
          }}
        >
          {items.length === 0 && (
            <EmptyState compact title={isStreaming ? 'Analyse de la requête' : 'Aucune analyse en cours'}>
              {isStreaming && <span className="flex items-center gap-2"><Dots /></span>}
            </EmptyState>
          )}

          {items.length > 0 && (
            <div className="relative min-h-full">
              {/* Vertical Continuous Timeline Spine */}
              <div className="absolute top-2 bottom-2 left-[9px] w-0.5 bg-line-strong/30" aria-hidden="true" />

              <div className="flex flex-col gap-5">
                {items.map((item) => {
                  if (item.type === 'thought') {
                    return (
                      <div key={item.id} className="relative flex gap-3">
                        <TimelinePoint type="thought" />
                        <div className="min-w-0 flex-1 pt-0.5">
                          <div className="mb-1 flex items-center gap-2">
                            <span className="font-mono text-[10px] font-semibold tracking-wider text-fg-3 uppercase">
                              Réflexion · Étape {item.stepNum}
                            </span>
                          </div>
                          <div className="text-sm leading-relaxed text-fg-2">
                            {item.content ? (
                              <Markdown>{item.content}</Markdown>
                            ) : (
                              <span className="inline-flex items-center gap-2 italic text-fg-3">
                                Réflexion en cours <Dots />
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                    );
                  }

                  if (item.type === 'tool') {
                    return (
                      <div key={item.id} className="relative flex gap-3">
                        <div className="flex h-11 items-center">
                          <TimelinePoint type="tool" status={item.status} />
                        </div>
                        <div className="min-w-0 flex-1">
                          <ToolCard item={item} bulk={bulk} />
                        </div>
                      </div>
                    );
                  }

                  if (item.type === 'orphan_result') {
                    return (
                      <div key={item.id} className="relative flex gap-3">
                        <div className="flex h-11 items-center">
                          <TimelinePoint type="tool" status={item.status} />
                        </div>
                        <div className="min-w-0 flex-1">
                          <ResultSection data={item.result} tool={item.tool} error={item.error} />
                        </div>
                      </div>
                    );
                  }

                  return null;
                })}
              </div>
            </div>
          )}
        </div>
      </aside>
    </>
  );
}
