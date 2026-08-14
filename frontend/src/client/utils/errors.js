// Central mapping of backend/transport error codes to client-safe, friendly
// copy. The backend already sends safe `message` text for most failures; this
// map is the single source of truth for the cases we own on the client side
// (transport, auth, generic HTTP) and the fallback when a code is unknown.
//
// Keep this file boring: one code, one message. Add a new entry when a new
// backend error code is introduced, never inline a raw string elsewhere.

const COPY = {
  AUTH_EXPIRED: {
    message: 'Votre session a expiré. Veuillez vous reconnecter.',
    retryable: false,
    redirectToSignin: true,
  },
  ACCOUNT_DISABLED: {
    message: 'Votre compte a été désactivé. Veuillez contacter un administrateur.',
    retryable: false,
  },
  QUOTA_EXCEEDED: {
    message: 'Votre budget mensuel a été atteint. Veuillez contacter votre administrateur.',
    retryable: false,
  },
  PYTHON_UNREACHABLE: {
    message: "Impossible de joindre le service d'analyse. Réessayez dans un instant.",
    retryable: true,
  },
  PYTHON_UPSTREAM: {
    message: 'Un problème est survenu lors de la connexion au service d\u2019analyse. Réessayez dans un instant.',
    retryable: true,
  },
  PROXY_TIMEOUT: {
    message: "L'analyse a pris trop de temps et a été interrompue. Réessayez ou posez une question plus courte.",
    retryable: true,
  },
  PROXY_ERROR: {
    message: "Une erreur est survenue lors du traitement de votre question. Veuillez réessayer.",
    retryable: true,
  },
  PROVIDER_AUTH: {
    message: "Le service d'IA n'est pas correctement configuré. Veuillez contacter le support.",
    retryable: false,
  },
  PROVIDER_RATE_LIMIT: {
    message: "Le service d'IA est occupé en ce moment. Réessayez dans un instant.",
    retryable: true,
  },
  PROVIDER_TIMEOUT: {
    message: "Le service d'IA met trop de temps à répondre. Veuillez réessayer.",
    retryable: true,
  },
  PROVIDER_CONTEXT: {
    message: 'Cette question est trop longue pour le service d\u2019IA. Posez une question plus courte.',
    retryable: false,
  },
  PROVIDER_ERROR: {
    message: "Le service d'IA a rencontré un problème temporaire. Veuillez réessayer.",
    retryable: true,
  },
  STREAM_INTERRUPTED: {
    message: "La connexion a été interrompue avant la fin de la réponse. Veuillez réessayer.",
    retryable: true,
  },
  REQUEST_TIMEOUT: {
    message: "Le démarrage de la requête prend trop de temps. Veuillez réessayer.",
    retryable: true,
  },
  NETWORK: {
    message: "Impossible de se connecter. Vérifiez votre connexion Internet et réessayez.",
    retryable: true,
  },
};

// Translate a known status code into a code we can map. Backend messages like
// "Unauthenticated." (401) and "Quota exceeded..." (429) are replaced by our
// own copy below, so the client never shows raw Laravel strings.
const STATUS_TO_CODE = {
  401: 'AUTH_EXPIRED',
  403: 'ACCOUNT_DISABLED',
  429: 'QUOTA_EXCEEDED',
};

/**
 * Normalize an error into { message, retryable, redirectToSignin } for display.
 *
 * @param {object} raw  { status?, code?, message?, retryable? }
 * @returns {{ message: string, retryable: boolean, redirectToSignin: boolean, code: string }}
 */
export function toUserError(raw = {}) {
  const statusCode = STATUS_TO_CODE[raw.status] || null;
  const code = raw.code || statusCode || 'UNKNOWN';
  const known = COPY[code] || null;

  if (known) {
    return {
      code,
      message: known.message,
      retryable: known.retryable,
      redirectToSignin: !!known.redirectToSignin,
    };
  }

  // Unknown code: prefer the (already client-safe) backend message, else a
  // neutral fallback. Never leak raw technical strings here.
  const message =
    (typeof raw.message === 'string' && raw.message.trim() && raw.message) ||
    'Une erreur est survenue. Veuillez réessayer.';
  return {
    code,
    message,
    retryable: raw.retryable !== false,
    redirectToSignin: code === 'AUTH_EXPIRED',
  };
}
