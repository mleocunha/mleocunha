/**
 * @param {string} base
 * @param {string} pathOrUrl
 */
export function joinUrl(base, pathOrUrl) {
  if (/^https?:\/\//i.test(pathOrUrl)) {
    return pathOrUrl;
  }
  const root = String(base || '').replace(/\/+$/, '');
  const path = String(pathOrUrl || '').startsWith('/')
    ? pathOrUrl
    : `/${pathOrUrl || ''}`;
  return `${root}${path}`;
}

/**
 * Resolve login URL from form settings.
 * @param {{ platformUrl: string, loginPath: string, loginPathCustom?: string }} cfg
 */
export function resolveLoginUrl(cfg) {
  const path =
    cfg.loginPath === 'custom'
      ? String(cfg.loginPathCustom || '').trim() || '/wp-login.php'
      : cfg.loginPath || '/wp-login.php';
  return joinUrl(cfg.platformUrl, path);
}

/**
 * @param {string} boothUrl
 * @param {number|string} electionId
 * @param {number|string} roundId
 */
export function boothUrlFor(boothUrl, electionId, roundId) {
  const u = new URL(boothUrl);
  u.searchParams.set('election_id', String(electionId));
  u.searchParams.set('round_id', String(roundId));
  return u.toString();
}
