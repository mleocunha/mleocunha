const SECRET_PATTERNS: RegExp[] = [
  /\b(sk-[A-Za-z0-9]{20,})\b/g,
  /\b(Bearer\s+[A-Za-z0-9\-._~+/]+=*)\b/gi,
  /\b(password\s*[:=]\s*\S+)\b/gi,
  /\b(api[_-]?key\s*[:=]\s*\S+)\b/gi,
  /\b(token\s*[:=]\s*\S+)\b/gi,
];

const PHONE_PATTERN = /(\+?\d{2,3})[\s\-.]?(\d{2})[\s\-.]?(\d{4,5})[\s\-.]?(\d{4})/g;

export function sanitizeText(input: string): string {
  let out = input;
  for (const pattern of SECRET_PATTERNS) {
    out = out.replace(pattern, "[REDACTED]");
  }
  out = out.replace(PHONE_PATTERN, (_m, cc: string, _a: string, _b: string, last: string) => {
    return `${cc}••••${last.slice(-4)}`;
  });
  return out;
}

export function maskPhone(value: string): string {
  const digits = value.replace(/\D/g, "");
  if (digits.length < 4) {
    return "••••";
  }
  return `+${digits.slice(0, Math.min(2, digits.length - 4))}••••${digits.slice(-4)}`;
}

export function stripSensitivePaths(text: string): string {
  return text
    .replace(/\/Users\/[^/\s]+/g, "/Users/[REDACTED]")
    .replace(/\/home\/[^/\s]+/g, "/home/[REDACTED]")
    .replace(/[A-Z]:\\Users\\[^\\\s]+/gi, "C:\\Users\\[REDACTED]");
}

export function sanitizeForGpt(text: string): string {
  return stripSensitivePaths(sanitizeText(text));
}
