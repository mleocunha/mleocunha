const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const LOWER = 'abcdefghjkmnpqrstuvwxyz';
const DIGITS = '23456789';
const SYMBOLS = '!@#$%&*';
const ALL = UPPER + LOWER + DIGITS + SYMBOLS;

/**
 * Fallback password only when WordPress does not auto-fill the reset form.
 * Prefer the WP-generated value on the reset screen in normal PoC flow.
 * Includes upper/lower/digit/symbol so common host policies accept it.
 */
export function generateSecurePassword(length = 16) {
  const size = Math.max(16, length);
  const bytes = new Uint8Array(size);
  crypto.getRandomValues(bytes);

  const chars = [
    UPPER[bytes[0] % UPPER.length],
    LOWER[bytes[1] % LOWER.length],
    DIGITS[bytes[2] % DIGITS.length],
    SYMBOLS[bytes[3] % SYMBOLS.length],
  ];

  for (let i = 4; i < size; i += 1) {
    chars.push(ALL[bytes[i] % ALL.length]);
  }

  const shuffleBytes = new Uint8Array(size);
  crypto.getRandomValues(shuffleBytes);
  for (let i = size - 1; i > 0; i -= 1) {
    const j = shuffleBytes[i] % (i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }

  return chars.join('');
}
