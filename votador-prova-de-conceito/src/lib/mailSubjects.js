/**
 * Must match plugin catalog translations of "Elector Password Reset".
 */
export const ELECTOR_PASSWORD_RESET_SUBJECTS = {
  en_US: 'Elector Password Reset',
  en: 'Elector Password Reset',
  pt_BR: 'Redefinição de Senha Eleitoral',
  /** Legacy subject still present in older mailboxes / plugin installs. */
  pt_BR_legacy: 'Redefinição de Senha Eleitora',
  pt_PT: 'Redefinição de Palavra-passe de Eleitor',
  fr_FR: 'Réinitialisation du mot de passe électeur',
  es_ES: 'Restablecimiento de contraseña de elector',
  de_DE: 'Zurücksetzung des Wählerpassworts',
  nl_NL: 'Herstel van kiezerswachtwoord',
  ru_RU: 'Сброс пароля избирателя',
  zh_CN: '选民密码重置',
  ar: 'إعادة تعيين كلمة مرور الناخب',
  he_IL: 'איפוס סיסמת בוחר',
  ca: 'Restabliment de contrasenya d\u2019elector',
};

export function subjectForLocale(locale) {
  const key = String(locale || 'en_US').replace('-', '_');
  return ELECTOR_PASSWORD_RESET_SUBJECTS[key] || ELECTOR_PASSWORD_RESET_SUBJECTS.en_US;
}

/**
 * Subjects to try when scanning the mailbox (preferred locale first, then commons).
 * @param {string} [locale]
 * @returns {string[]}
 */
export function subjectsToMatch(locale) {
  const preferred = subjectForLocale(locale);
  const extras = [
    ELECTOR_PASSWORD_RESET_SUBJECTS.en_US,
    ELECTOR_PASSWORD_RESET_SUBJECTS.pt_BR,
    ELECTOR_PASSWORD_RESET_SUBJECTS.pt_BR_legacy,
    ELECTOR_PASSWORD_RESET_SUBJECTS.pt_PT,
    'Password Reset',
    'Redefinição de senha',
    'Reset Password',
  ];
  const out = [];
  for (const s of [preferred, ...extras]) {
    if (s && !out.includes(s)) {
      out.push(s);
    }
  }
  return out;
}

/**
 * Loose subject matcher for WP / RSES reset mails.
 * @param {string} text
 * @returns {boolean}
 */
export function looksLikeResetSubject(text) {
  const t = String(text || '');
  if (!t.trim()) {
    return false;
  }
  if (/elector password reset/i.test(t)) {
    return true;
  }
  if (/redefini[cç][aã]o.*(senha|palavra[- ]passe).*(eleitoral|eleitora|eleitor)/i.test(t)) {
    return true;
  }
  if (/password\s*reset/i.test(t) || /redefini[cç][aã]o de senha/i.test(t)) {
    return true;
  }
  if (/restablecimiento.*contrase[nñ]a/i.test(t) || /réinitialisation.*passe/i.test(t)) {
    return true;
  }
  return false;
}
