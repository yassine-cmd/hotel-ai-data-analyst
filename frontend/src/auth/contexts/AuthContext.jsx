import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { signIn as signInRequest, signOut as signOutRequest, getCurrentUser } from '../services/authService';

const AuthContext = createContext(null);

const SESSION_TTL = 120 * 60 * 1000;
const LS_KEY = 'fms_session_ts';

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const ts = localStorage.getItem(LS_KEY);
    if (!ts || Date.now() - Number(ts) > SESSION_TTL) {
      localStorage.removeItem(LS_KEY);
      setLoading(false);
      return;
    }
    getCurrentUser().then((u) => {
      if (u) setUser(u);
      else localStorage.removeItem(LS_KEY);
    }).finally(() => setLoading(false));
  }, []);

  const signIn = async ({ username, password }) => {
    const u = await signInRequest({ username, password });
    localStorage.setItem(LS_KEY, Date.now().toString());
    setUser(u);
    return u;
  };

  const signOut = async () => {
    await signOutRequest();
    localStorage.removeItem(LS_KEY);
    setUser(null);
  };

  const value = useMemo(() => ({ user, loading, signIn, signOut, setUser }), [user, loading]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() { return useContext(AuthContext); }
