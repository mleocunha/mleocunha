const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const LOWER = 'abcdefghjkmnpqrstuvwxyz';
const DIGITS = '23456789';
const ALL = UPPER + LOWER + DIGITS;

/**
 * Secure 8-char password: upper + lower + digit, no ambiguous glyphs (0Oo1lIi).
 */
export function generateSecurePassword(length = 8) {
  const size = Math.max(8, length);
  const bytes = new Uint8Array(size);
  crypto.getRandomValues(bytes);

  const chars = [
    UPPER[bytes[0] % UPPER.length],
    LOWER[bytes[1] % LOWER.length],
    DIGITS[bytes[2] % DIGITS.length],
  ];

  for (let i = 3; i < size; i += 1) {
    chars.push(ALL[bytes[i] % ALL.length]);
  }

  // Shuffle with fresh entropy.
  const shuffleBytes = new Uint8Array(size);
  crypto.getRandomValues(shuffleBytes);
  for (let i = size - 1; i > 0; i -= 1) {
    const j = shuffleBytes[i] % (i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }

  return chars.join('');
}
