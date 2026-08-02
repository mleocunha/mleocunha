/**
 * Must match plugin catalog translations of "Elector Password Reset".
 */
export const ELECTOR_PASSWORD_RESET_SUBJECTS = {
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
  return ELECTOR_PASSWORD_RESET_SUBJECTS[key] || ELECTOR_PASSWORD_RESET_SUBJECTS.en_US;
}
