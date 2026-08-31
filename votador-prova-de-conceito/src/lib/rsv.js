import fs from 'node:fs';
import { parse } from 'node:path';

/**
 * Formato .rsv do Cadastro Eleitoral — espelho de
 * RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat.
 *
 * Separadores (contrato de produto):
 * - `:` separa campos
 * - `;` separa itens de série (ex.: vários e-mails ou celulares)
 * - `,` é texto livre (ex.: endereço), nunca separador de coluna
 * - linhas separadas por newline
 *
 * Cabeçalho canónico:
 * login:numerodeidentificacaocivil:numerodeidentificacaoeleitoral:
 * regiaoeleitoralampla:regiaoeleitoralespecifica:nomecompleto:
 * celular:email:endereco:papel:senha
 */

export const RSV_FIELD_SEP = ':';
export const RSV_SERIES_SEP = ';';

/** @type {readonly string[]} */
export const RSV_HEADERS = Object.freeze([
  'login',
  'numerodeidentificacaocivil',
  'numerodeidentificacaoeleitoral',
  'regiaoeleitoralampla',
  'regiaoeleitoralespecifica',
  'nomecompleto',
  'celular',
  'email',
  'endereco',
  'papel',
  'senha',
]);

/** Papéis que votam na urna (resto do cadastro é ignorado pelo Votador). */
const VOTER_PAPEIS = new Set(['eleitor', 'subscriber']);

/**
 * Linha de metadados (cabeçalho) idêntica à do plugin.
 * @returns {string}
 */
export function rsvHeaderLine() {
  return RSV_HEADERS.join(RSV_FIELD_SEP);
}

/**
 * Remover BOM UTF-8 e espaços laterais de uma célula.
 * @param {string} value
 * @returns {string}
 */
export function normalizeRsvCell(value) {
  let s = String(value ?? '');
  s = s.replace(/^\uFEFF/, '');
  s = s.replace(/\u00A0/g, ' ');
  return s.trim();
}

/**
 * Aproximação de WP sanitize_user( $login, true ).
 * @param {string} login
 * @returns {string}
 */
export function sanitizeUserLogin(login) {
  return String(login || '')
    .replace(/[^a-zA-Z0-9 _.\-@]/g, '')
    .trim();
}

/**
 * Partir uma linha .rsv em exactamente {@link RSV_HEADERS.length} campos.
 * @param {string} line
 * @returns {string[]|null} null se a contagem de campos for inválida ou a linha for vazia
 */
export function parseRsvLine(line) {
  let raw = String(line ?? '').replace(/^\uFEFF/, '');
  raw = raw.replace(/\r$/, '');
  if (!raw.trim()) {
    return null;
  }
  const parts = raw.split(RSV_FIELD_SEP);
  if (parts.length !== RSV_HEADERS.length) {
    return null;
  }
  return parts.map((p) => normalizeRsvCell(p));
}

/**
 * Associar campos posicionais aos nomes do cabeçalho.
 * @param {string[]} fields
 * @returns {Record<string, string>}
 */
export function associateRsvFields(fields) {
  /** @type {Record<string, string>} */
  const out = {};
  for (let i = 0; i < RSV_HEADERS.length; i += 1) {
    out[RSV_HEADERS[i]] = String(fields[i] ?? '');
  }
  return out;
}

/**
 * Partir série (`a;b;c`) em itens não vazios.
 * @param {string} value
 * @returns {string[]}
 */
export function splitRsvSeries(value) {
  if (!value) {
    return [];
  }
  return String(value)
    .split(RSV_SERIES_SEP)
    .map((p) => normalizeRsvCell(p))
    .filter(Boolean);
}

/**
 * O papel desta linha corresponde a um eleitor votante?
 * Aceita `eleitor` (canónico) e `subscriber` (legado WP no campo papel).
 * @param {string} papel
 * @returns {boolean}
 */
export function isVoterPapel(papel) {
  return VOTER_PAPEIS.has(String(papel || '').toLowerCase());
}

/**
 * Validar que a primeira linha é o cabeçalho canónico (ordem e nomes exactos).
 * @param {string[]} fields
 * @returns {boolean}
 */
export function isRsvHeaderRow(fields) {
  if (!fields || fields.length !== RSV_HEADERS.length) {
    return false;
  }
  return RSV_HEADERS.every((h, i) => normalizeRsvCell(fields[i]).toLowerCase() === h);
}

/**
 * Carregar eleitores a partir de um ficheiro `.rsv` de cadastro eleitoral.
 *
 * Só inclui linhas com `papel` eleitor (ou subscriber legado).
 * Expõe `user_login` / `password` / `user_email` para o resto do Votador
 * (login WP e PoC de troca de senha).
 *
 * @param {string} filePath
 * @returns {{
 *   electors: Array<{
 *     user_login: string,
 *     password: string,
 *     password_len: number,
 *     user_email: string,
 *     papel: string,
 *     nomecompleto: string,
 *     row: number,
 *     record: Record<string, string>,
 *   }>,
 *   headers: string[],
 *   source: string,
 *   skippedNonVoters: number,
 * }}
 */
export function loadElectorsFromRsv(filePath) {
  const text = fs.readFileSync(filePath, 'utf8').replace(/^\uFEFF/, '');
  const lines = text.split(/\n/);
  if (!lines.length || !lines.some((l) => l.trim() !== '')) {
    throw new Error('Arquivo RSV vazio.');
  }

  let headerFields = null;
  let headerLineNo = 0;
  let dataStart = 0;
  for (let i = 0; i < lines.length; i += 1) {
    const fields = parseRsvLine(lines[i]);
    if (!fields) {
      continue;
    }
    if (!isRsvHeaderRow(fields)) {
      throw new Error(
        `RSV inválido na linha ${i + 1}: esperado cabeçalho canónico ` +
          `"${rsvHeaderLine()}".`
      );
    }
    headerFields = fields;
    headerLineNo = i + 1;
    dataStart = i + 1;
    break;
  }

  if (!headerFields) {
    throw new Error('RSV sem linha de cabeçalho válida.');
  }

  const electors = [];
  let skippedNonVoters = 0;

  for (let i = dataStart; i < lines.length; i += 1) {
    const lineNo = i + 1;
    const trimmed = lines[i].replace(/\r$/, '');
    if (!trimmed.trim()) {
      continue;
    }
    const fields = parseRsvLine(trimmed);
    if (!fields) {
      throw new Error(
        `RSV linha ${lineNo}: esperado exactamente ${RSV_HEADERS.length} campos separados por ':'.`
      );
    }
    const record = associateRsvFields(fields);
    const papel = record.papel.toLowerCase();
    if (!isVoterPapel(papel)) {
      skippedNonVoters += 1;
      continue;
    }

    const login = sanitizeUserLogin(record.login);
    const password = record.senha;
    if (!login || !password) {
      continue;
    }

    // PoC Roundcube usa o primeiro e-mail da série.
    const emails = splitRsvSeries(record.email);
    electors.push({
      user_login: login,
      password,
      password_len: password.length,
      user_email: emails[0] || '',
      papel,
      nomecompleto: record.nomecompleto,
      row: lineNo,
      record,
    });
  }

  if (!electors.length) {
    throw new Error(
      `Nenhum eleitor válido (papel=eleitor) encontrado no RSV` +
        (headerLineNo ? ` (cabeçalho na linha ${headerLineNo})` : '') +
        '.'
    );
  }

  return {
    electors,
    headers: [...RSV_HEADERS],
    source: parse(filePath).base,
    skippedNonVoters,
  };
}
