# Verificação — UI HTTP mínima (Adapter #2)

## Âmbito desta fatia

| Requisito | Estado |
|-----------|--------|
| Entrada `index.php` na raiz do pacote | Sim |
| Default `10.42.0.1:8888` via `bin/ve-http` | Sim |
| 1 processo = 1 modo E3 (`VE_MODE` + `VE_DATA`) | Sim |
| Login simples + identity **durável** (`identity.json`) | Sim |
| Painel operador mínimo nos **3 modos** | Sim |
| Importação `.rsv` (domínio `RsvImporter`) | Sim |
| Jornada `/voto`, `/voto/cabina`, `/voto/obrigado` | Sim |
| Courier manual entre nós | Sim |
| Multilíngue via catálogos JSON + Accept-Language | Sim |
| Reutilizar CSS do Painel (`/assets/…`) | Sim |
| URLs estáveis para nginx / Votador | Sim (`/login`, `/painel`, `/voto`) |

## Arranque (3 nós)

```bash
# Autoridade de chaves
php bin/ve-http --mode=key_authority --data=/tmp/ve/ka --port=8888

# Votação (outro host/porta atrás do nginx, ou outra máquina)
php bin/ve-http --mode=voting --data=/tmp/ve/voting --port=8889

# Apuração
php bin/ve-http --mode=tallying --data=/tmp/ve/tallying --port=8890
```

Admin inicial: `VE_ADMIN_LOGIN` / `VE_ADMIN_PASS` (default `admin` / `AdminPoC1!`).

Courier partilhado: pasta `dirname(VE_DATA)/courier` (ex.: `/tmp/ve/courier` se os três data dirs forem irmãos).

## Ciclo E3 (manual HTTP)

1. KA → `/painel/keygen` → gera chave + escreve `public-key.json` e `parcela-*.json` no courier  
2. Voting → `/painel/courier` (confirma ficheiros) → `/painel/cadastro` importa `.rsv` → `/voto`  
3. Tallying → `/painel/importar` / `/painel/certificar` + parcelas no courier  
4. Piloto completo criptográfico continua disponível: `php bin/ve-node pilot --root=/tmp/ve-piloto`

## Testes

```bash
./vendor/bin/phpunit --filter 'StandaloneHttpTest|DurablePersistenceTest|ThreeNodePilotTest|RsvFormatTest'
```

## Residual

- Jobs async HTTP ainda InMemory (import RSV é síncrono nesta v1)  
- Cabina HTTP: voto homomórfico mínimo (0/1), não o boletim completo do Adapter #1  
- Certificação HTTP: registo de artefacto; reconstrução completa via piloto/courier  
- SQL multi-writer — fora de âmbito  

**Veredicto:** PASS (superfície HTTP standalone nos 3 modos)
