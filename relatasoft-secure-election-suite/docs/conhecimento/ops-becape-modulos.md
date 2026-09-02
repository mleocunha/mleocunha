# Ops — becape, módulos e autoteste (C3)

## Becape e restauração

- Painel → **Becape e Restauração** (`rses-system-becape`).
- Gera ZIP com manifesto `ve-becape-v1`, `database.sql` e árvore de arquivos.
- Restaurar exige a frase de confirmação `RESTAURAR`.
- Arquivos guardados fora da web (`uploads/ve-becape/` + `.htaccess` Deny).

## Módulos ZIP

- Painel → **Módulos do Sistema**.
- Instalar/atualizar ZIP com `overwrite_package` (sem `plugins.php` clássico — 404 via máscara C2).
- **Não** é possível remover o núcleo `relatasoft-secure-election-suite/`.

## Autoteste criptográfico

- Painel → **Autoteste Criptográfico**.
- Exercita GMP, ElGamal, tally homomórfico, Shamir e mini-eleição.
- Também: `php tests/crypto-acceptance.php` (requer boot do sítio) e PHPUnit Domain Crypto em CI.
