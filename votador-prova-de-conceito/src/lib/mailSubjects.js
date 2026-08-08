/**
 * Must match plugin catalog translations of "Electoral Password Reset".
 * Legacy subjects remain as match aliases so older inbox mail is still
 * recognized when selecting the single newest reset message.
 */
export const ELECTORAL_PASSWORD_RESET_SUBJECTS = {
  en_US: 'Electoral Password Reset',
  en: 'Electoral Password Reset',
  pt_BR: 'Redefinição de Senha Eleitoral',
  pt_PT: 'Redefinição de Palavra-passe Eleitoral',
  fr_FR: 'Réinitialisation du mot de passe électoral',
  es_ES: 'Restablecimiento de contraseña electoral',
  de_DE: 'Zurücksetzung des elektoralen Passworts',
  nl_NL: 'Herstel van electoraal wachtwoord',
  ru_RU: 'Сброс электорального пароля',
  zh_CN: '选举密码重置',
  ar: 'إعادة تعيين كلمة المرور الانتخابية',
  he_IL: 'איפוס סיסמה אלקטורלית',
  ca: 'Restabliment de contrasenya electoral',
};

/** @deprecated Use ELECTORAL_PASSWORD_RESET_SUBJECTS — kept for import compatibility. */
export const ELECTOR_PASSWORD_RESET_SUBJECTS = ELECTORAL_PASSWORD_RESET_SUBJECTS;

/** Older plugin wording still present in some inboxes. */
export const LEGACY_PASSWORD_RESET_SUBJECTS = {
  en_US: 'Elector Password Reset',
  en: 'Elector Password Reset',
  pt_BR: 'Redefinição de Senha Eleitora',
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
  return ELECTORAL_PASSWORD_RESET_SUBJECTS[key] || ELECTORAL_PASSWORD_RESET_SUBJECTS.en_US;
}

/** All subjects (current + legacy) for inbox matching. */
export function allResetSubjects() {
  const seen = new Set();
  const out = [];
  for (const map of [ELECTORAL_PASSWORD_RESET_SUBJECTS, LEGACY_PASSWORD_RESET_SUBJECTS]) {
    for (const s of Object.values(map)) {
      const key = String(s || '').toLowerCase();
      if (!key || seen.has(key)) continue;
      seen.add(key);
      out.push(s);
    }
  }
  return out;
}
