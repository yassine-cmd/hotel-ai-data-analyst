import { useCallback, useEffect, useRef, useState } from 'react';
import { MessageSquarePlus, MoreHorizontal, PanelLeft, Pencil, Search, Trash2 } from 'lucide-react';
import { sessionLabel } from '../utils/smartTitle';
import Logo from '../../shared/components/ui/Logo';
import { BRAND } from '../../shared/brand';

function timeAgo(date) {
  if (!date) return '';
  const seconds = Math.floor((Date.now() - new Date(date).getTime()) / 1000);
  if (seconds < 60) return 'à l\'instant';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `il y a ${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `il y a ${hours} h`;
  const days = Math.floor(hours / 24);
  return `il y a ${days} j`;
}

export default function ConversationsSidebar({ session, onNew, open, onClose, collapsed, onToggleCollapse }) {
  const activeId = session.sessionId;
  const sessions = session.sessions || [];
  const [query, setQuery] = useState('');
  const [menuOpenId, setMenuOpenId] = useState(null);
  const [menuUp, setMenuUp] = useState(false);
  const [renameId, setRenameId] = useState(null);
  const [renameValue, setRenameValue] = useState('');
  const [renameError, setRenameError] = useState('');
  const [confirmDeleteId, setConfirmDeleteId] = useState(null);
  const [deletingId, setDeletingId] = useState(null);

  const filtered = sessions.filter((s) => (s.name || '').toLowerCase().includes(query.trim().toLowerCase()));

  const menuRef = useRef(null);
  const renameInputRef = useRef(null);
  const focusIndexRef = useRef(0);
  const searchInputRef = useRef(null);

  const closeAll = useCallback(() => {
    setMenuOpenId(null);
    setRenameId(null);
    setRenameError('');
    setConfirmDeleteId(null);
  }, []);

  useEffect(() => {
    if (!menuOpenId) return;
    const handler = (e) => {
      if (menuRef.current && !menuRef.current.contains(e.target)) closeAll();
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [menuOpenId, closeAll]);

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') { if (menuOpenId) closeAll(); if (open) onClose(); }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [menuOpenId, closeAll, open, onClose]);

  useEffect(() => {
    if (renameInputRef.current) { renameInputRef.current.focus(); renameInputRef.current.select(); }
  }, [renameId]);

  const handleSelect = (id) => {
    if (menuOpenId === id) closeAll();
    session.select(id);
    onClose();
  };

  const handleDots = (id, e) => {
    e.stopPropagation();
    if (menuOpenId === id) { closeAll(); return; }
    closeAll();
    setMenuOpenId(id);
    setMenuUp(false);
    focusIndexRef.current = 0;
    requestAnimationFrame(() => {
      const btn = document.querySelector(`[data-menu-btn="${id}"]`);
      const list = document.getElementById('session-sidebar');
      if (btn && list) {
        const btnRect = btn.getBoundingClientRect();
        const listRect = list.getBoundingClientRect();
        setMenuUp(btnRect.bottom + 140 > listRect.bottom);
      }
    });
  };

  const handleMenuKeyDown = (e, items) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); focusIndexRef.current = Math.min(focusIndexRef.current + 1, items.length - 1); document.querySelector(`[data-menu-item="${menuOpenId}-${focusIndexRef.current}"]`)?.focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); focusIndexRef.current = Math.max(focusIndexRef.current - 1, 0); document.querySelector(`[data-menu-item="${menuOpenId}-${focusIndexRef.current}"]`)?.focus(); }
    if (e.key === 'Home') { e.preventDefault(); focusIndexRef.current = 0; document.querySelector(`[data-menu-item="${menuOpenId}-${focusIndexRef.current}"]`)?.focus(); }
    if (e.key === 'End') { e.preventDefault(); focusIndexRef.current = items.length - 1; document.querySelector(`[data-menu-item="${menuOpenId}-${focusIndexRef.current}"]`)?.focus(); }
  };

  const startRename = (s) => {
    setRenameValue(s.name || '');
    setRenameId(s.session_id);
    setConfirmDeleteId(null);
    setRenameError('');
  };

  const commitRename = async (id) => {
    if (!renameValue.trim()) { setRenameId(null); setRenameError(''); return; }
    try {
      await session.rename(id, renameValue.trim());
      setRenameId(null);
      setRenameError('');
    } catch (err) { setRenameError(err.message || 'Échec du renommage'); }
  };

  const handleDelete = async (id) => {
    setDeletingId(id);
    await new Promise((r) => setTimeout(r, 180));
    try { await session.remove(id); } catch {}
    setDeletingId(null);
    closeAll();
  };

  const menuItemClass = (danger = false) =>
    `flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-xs font-medium transition-colors ${
      danger ? 'text-[#f04444] hover:bg-[#fef2f2]' : 'text-[#C5C9D3] hover:bg-white/10 hover:text-white'
    }`;

  return (
    <>
      {open && <div className="fixed inset-0 z-40 bg-black/50 md:hidden" onClick={onClose} aria-hidden="true" />}
      <aside
        id="session-sidebar"
        aria-label="Liste des conversations"
        className={`session-sidebar absolute inset-y-0 left-0 z-50 flex w-[280px] shrink-0 flex-col overflow-hidden border-r border-white/10 bg-[#343B50] text-white transition-transform duration-300 md:static md:transition-[width] md:duration-300 ${
          collapsed ? 'md:w-16' : 'md:w-[280px]'
        } ${open ? 'translate-x-0' : '-translate-x-full md:translate-x-0'}`}
      >
        <div className={`flex h-16 shrink-0 items-center border-b border-white/10 transition-all duration-300 ${collapsed ? 'gap-1 px-0 md:justify-center' : 'gap-2.5 px-4'}`}>
          <Logo size="sm" className={collapsed ? 'md:hidden' : 'md:animate-fadeIn'} />
          <span className={`truncate text-[13.5px] font-semibold tracking-tight text-white ${collapsed ? 'md:hidden' : 'md:animate-fadeIn'}`}>{BRAND.name}</span>
          <span className={`rounded-full bg-[#2D3447] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-white ${collapsed ? 'md:hidden' : 'md:animate-fadeIn'}`}>Client</span>
          <button
            className={`grid shrink-0 place-items-center rounded-lg text-[#C5C9D3] transition-all duration-300 hover:bg-white/10 hover:text-white ${collapsed ? 'md:mx-auto md:h-8 md:w-8' : 'ml-auto h-8 w-8'}`}
            onClick={onToggleCollapse}
            aria-label={collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale'}
            aria-expanded={!collapsed}
            title={collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale'}
          >
            <PanelLeft className="h-4 w-4" />
          </button>
        </div>
        <div className="flex flex-col gap-3 border-b border-white/10 p-3">
          <button
            className={`flex h-9 items-center justify-center gap-2 rounded-[12px] bg-[#43A7BA] text-[13px] font-semibold text-white transition-all duration-300 hover:bg-[#2f8a99] ${collapsed ? 'md:mx-auto md:w-9 md:px-0' : ''}`}
            onClick={onNew}
            title="Nouvelle conversation"
          >
            <MessageSquarePlus className="h-4 w-4" />
            <span className={collapsed ? 'md:hidden' : 'md:animate-fadeIn'}>Nouvelle conversation</span>
          </button>
          <div
            className={`flex items-center gap-2 rounded-[12px] border border-white/10 bg-[#2D3447] px-2.5 transition-all duration-300 ${collapsed ? 'md:mx-auto md:w-9 md:justify-center md:px-0 md:cursor-pointer' : ''}`}
            onClick={() => {
              if (collapsed) { onExpand(); requestAnimationFrame(() => searchInputRef.current?.focus()); }
            }}
            title="Rechercher des conversations"
          >
            <Search className="h-3.5 w-3.5 shrink-0 text-[#C5C9D3]" />
            <input
              ref={searchInputRef}
              className={`h-8 w-full min-w-0 bg-transparent text-xs text-white outline-none placeholder:text-[#C5C9D3] ${collapsed ? 'md:hidden' : 'md:animate-fadeIn'}`}
              placeholder="Rechercher des conversations…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
          </div>
        </div>

        <div className={`flex flex-1 flex-col gap-0.5 overflow-y-auto p-1.5 ${collapsed ? 'md:hidden' : 'md:animate-fadeIn'}`}>
          {filtered.length === 0 && (
            <div className="py-10 text-center text-xs text-[#C5C9D3]">
              {sessions.length === 0 ? 'Aucune conversation' : 'Aucun résultat'}
            </div>
          )}
          {filtered.map((s) => (
            <div
              key={s.session_id}
              className={`session-item group relative flex cursor-pointer items-center gap-2 rounded-[12px] px-2.5 transition-[max-height,opacity,background-color] duration-[180ms] ${
                s.session_id === activeId ? 'active bg-[#2D3447] text-white' : 'hover:bg-white/10'
              } ${deletingId === s.session_id ? 'pointer-events-none max-h-0 overflow-hidden py-0 opacity-0' : 'max-h-16 py-2'}`}
              role="button"
              tabIndex={0}
              onClick={() => { handleSelect(s.session_id); }}
              onKeyDown={(e) => { if (e.key === 'Enter') handleSelect(s.session_id); }}
            >
              <div className="min-w-0 flex-1">
                {renameId === s.session_id ? (
                  <input
                    ref={renameInputRef}
                    className="input-sm w-full rounded-md border border-accent bg-input px-2 py-1 text-xs text-fg outline-none"
                    value={renameValue}
                    onChange={(e) => setRenameValue(e.target.value)}
                    onKeyDown={(e) => { e.stopPropagation(); if (e.key === 'Enter') commitRename(s.session_id); if (e.key === 'Escape') setRenameId(null); }}
                    onBlur={() => commitRename(s.session_id)}
                    onClick={(e) => e.stopPropagation()}
                  />
                ) : (
                  <div className={`truncate text-[13px] font-medium ${s.session_id === activeId ? 'text-white' : 'text-[#E8EBF3]'}`}>
                    {sessionLabel(s)}
                  </div>
                )}
                {renameError && renameId === s.session_id && <div className="text-[10px] text-[#f04444]">{renameError}</div>}
                <div className="mt-0.5 text-[11px] text-[#C5C9D3]">
                  {timeAgo(s.created_at)}
                </div>
              </div>
              <button
                data-menu-btn={s.session_id}
                className={`grid h-7 w-7 shrink-0 place-items-center rounded-md text-[#C5C9D3] transition-colors hover:bg-white/10 hover:text-white ${
                  menuOpenId === s.session_id ? 'bg-white/10 text-white' : ''
                }`}
                onClick={(e) => handleDots(s.session_id, e)}
                aria-label="Options de la conversation"
                aria-haspopup="menu"
                aria-expanded={menuOpenId === s.session_id}
                aria-controls={`menu-${s.session_id}`}
              >
                <MoreHorizontal className="h-4 w-4" />
              </button>

              {menuOpenId === s.session_id && !confirmDeleteId && !renameId && (
                <div
                  ref={menuRef}
                  role="menu"
                  id={`menu-${s.session_id}`}
                  onKeyDown={(e) => handleMenuKeyDown(e, ['rename', 'delete'])}
                  className={`absolute right-2 z-50 flex min-w-[130px] animate-fadeIn flex-col gap-0.5 rounded-[12px] border border-white/10 bg-[#2D3447] p-1.5 shadow-lg ${menuUp ? 'bottom-9' : 'top-9'}`}
                >
                  <button role="menuitem" data-menu-item={`${s.session_id}-0`} tabIndex={-1} className={menuItemClass()} onClick={(e) => { e.stopPropagation(); startRename(s); }} onFocus={() => focusIndexRef.current = 0}>
                    <Pencil className="h-3.5 w-3.5" />
                    Renommer
                  </button>
                  <button role="menuitem" data-menu-item={`${s.session_id}-1`} tabIndex={-1} className={menuItemClass(true)} onClick={(e) => { e.stopPropagation(); setConfirmDeleteId(s.session_id); }} onFocus={() => focusIndexRef.current = 1}>
                    <Trash2 className="h-3.5 w-3.5" />
                    Supprimer
                  </button>
                </div>
              )}

              {menuOpenId === s.session_id && confirmDeleteId === s.session_id && (
                <div ref={menuRef} role="menu" className={`absolute right-2 z-50 flex min-w-[130px] animate-fadeIn flex-col gap-1 rounded-[12px] border border-white/10 bg-[#2D3447] p-2 shadow-lg ${menuUp ? 'bottom-9' : 'top-9'}`}>
                  <div className="px-1 pb-1 text-xs font-medium text-[#E8EBF3]">Supprimer cette session ?</div>
                  <div className="flex gap-1.5">
                    <button role="menuitem" tabIndex={-1} className="h-7 flex-1 rounded-md bg-[#f04444] px-2 text-[11px] font-semibold text-white hover:bg-[#d93636]" onClick={(e) => { e.stopPropagation(); handleDelete(s.session_id); }}>Supprimer</button>
                    <button role="menuitem" tabIndex={-1} className="h-7 flex-1 rounded-md border border-white/10 px-2 text-[11px] font-medium text-[#E8EBF3] hover:bg-white/10" onClick={(e) => { e.stopPropagation(); setConfirmDeleteId(null); }}>Annuler</button>
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      </aside>
    </>
  );
}