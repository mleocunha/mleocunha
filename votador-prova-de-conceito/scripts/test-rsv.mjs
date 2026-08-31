#!/usr/bin/env node
/**
 * Smoke do parser RSV (sem Playwright) — espelha o contrato RsvFormat do plugin.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  loadElectorsFromRsv,
  parseRsvLine,
  rsvHeaderLine,
  splitRsvSeries,
  isVoterPapel,
} from '../src/lib/rsv.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const sample = path.resolve(__dirname, '../samples/exemplo-cadastro.rsv');

assert.equal(
  rsvHeaderLine(),
  'login:numerodeidentificacaocivil:numerodeidentificacaoeleitoral:regiaoeleitoralampla:regiaoeleitoralespecifica:nomecompleto:celular:email:endereco:papel:senha'
);

const addrLine =
  'ana:1:2:Zona:Sec:Ana Silva:+5511;+5522:ana@ex.com;alt@ex.com:Rua X, 10, Recife-PE:eleitor:Senha1';
const fields = parseRsvLine(addrLine);
assert.ok(fields);
assert.equal(fields[8], 'Rua X, 10, Recife-PE');
assert.deepEqual(splitRsvSeries(fields[6]), ['+5511', '+5522']);
assert.deepEqual(splitRsvSeries(fields[7]), ['ana@ex.com', 'alt@ex.com']);
assert.ok(isVoterPapel('eleitor'));
assert.ok(!isVoterPapel('auditor'));

const { electors, skippedNonVoters } = loadElectorsFromRsv(sample);
assert.equal(electors.length, 2);
assert.equal(electors[0].user_login, 'eleitor01');
assert.equal(electors[0].password, 'SenhaPoC01');
assert.equal(electors[0].user_email, 'eleitor01@example.test');
assert.equal(skippedNonVoters, 0);

const tmp = path.join(os.tmpdir(), `votador-rsv-${Date.now()}.rsv`);
fs.writeFileSync(
  tmp,
  `${rsvHeaderLine()}\n` +
    `e1:1:1:z:s:Nome::e1@t.com::eleitor:p1\n` +
    `adm:1:1:z:s:Admin::a@t.com::administrador:secret\n` +
    `e2:2:2:z:s:Nome2::e2@t.com::eleitor:p2\n`,
  'utf8'
);
const mixed = loadElectorsFromRsv(tmp);
assert.equal(mixed.electors.length, 2);
assert.equal(mixed.skippedNonVoters, 1);
fs.unlinkSync(tmp);

assert.equal(parseRsvLine('a:b:c'), null);

let missingHeaderFailed = false;
try {
  const bad = path.join(os.tmpdir(), `votador-rsv-bad-${Date.now()}.rsv`);
  fs.writeFileSync(bad, 'nao:e:cabecalho\n', 'utf8');
  loadElectorsFromRsv(bad);
} catch (err) {
  missingHeaderFailed = /cabeçalho/i.test(String(err.message || err));
}
assert.ok(missingHeaderFailed, 'cabeçalho inválido deve falhar');

console.log('rsv-parser OK');
