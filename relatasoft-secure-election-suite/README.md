# Voto Eletrônico — Suite Segura (standalone)

Pacote PHP autónomo: domínio criptográfico (ElGamal, Feldman/Shamir, Schnorr, E3),
persistência em ficheiros por nó, e **interface HTTP** para operador e eleitor.

**Um processo = um nó = um modo E3.** Um cliente típico = **três sítios** isolados
(três processos + três árvores de dados). Não há sincronização automática entre sítios.

### Testes e demonstração vs produção

| Contexto | Topologia correcta |
|----------|-------------------|
| **Testes / demonstração** | Os três modos no **mesmo** anfitrião (três processos, três `VE_DATA`, portas distintas; `ve-http` em background ou três terminais). Adequado a lab, PoC e ensaio operacional. |
| **Produção** | Cada modo num **servidor independente e segregado**, preferencialmente em **nuvens distintas**, com **administradores de sistemas distintos** (preferencialmente que nem se conheçam), **contratações independentes** e, preferencialmente, **gestores de contrato independentes**, respondendo todos à **autoridade eleitoral superior** (preferencialmente **colegiada**). |

Três processos no mesmo host **não** substituem o isolamento organizacional de produção.

Modos (`VE_MODE` / `RSES_MODE`):

| Modo | Função |
|------|--------|
| `key_authority` | Gerar chave ElGamal e parcelas Shamir; publicar no courier |
| `voting` | Cadastro (`.rsv`), jornada `/voto`, exportar material de voto |
| `tallying` | Importar material + parcelas; apurar e certificar |

## Requisitos

- PHP **8.2+** com **gmp**, **json**, **mbstring**, **openssl**
- Composer 2
- Linux recomendado em piloto/produção

## Activar (do zero)

### 1. Instalar dependências

Na pasta do pacote (`relatasoft-secure-election-suite/`):

```bash
composer install --no-dev   # produção
# ou
composer install            # desenvolvimento + testes
```

### 2. Criar dados do nó

Cada nó precisa do **seu** directorio (nunca partilhar entre modos/sítios):

```bash
mkdir -p /var/lib/ve/{ka,voting,tallying,courier}
```

### 3. Arrancar a interface HTTP

Script recomendado (`bin/ve-http` — default `10.42.0.1:8888`).

**Testes / demonstração** (um anfitrião, três processos — configuração correcta para lab):

```bash
mkdir -p "$HOME/ve-data"/{ka,voting,tallying,courier}

php bin/ve-http --mode=key_authority --data="$HOME/ve-data/ka" \
  --host=10.42.0.1 --port=8888 &
php bin/ve-http --mode=voting --data="$HOME/ve-data/voting" \
  --host=10.42.0.1 --port=8889 &
php bin/ve-http --mode=tallying --data="$HOME/ve-data/tallying" \
  --host=10.42.0.1 --port=8890
```

Confirmar: `ss -ltnp | grep -E '8888|8889|8890'`.  
URLs: `http://10.42.0.1:8888/login`, `:8889/login`, `:8890/login`.

**Produção:** um `ve-http` (ou serviço systemd + proxy TLS) **por servidor**, cada um com o seu `VE_DATA` e o seu modo; material entre sítios só via courier / canal auditável — ver `docs/operacao-standalone.md`.

Equivalente com o servidor embutido do PHP:

```bash
export VE_MODE=voting
export VE_DATA=/var/lib/ve/voting
export VE_PUBLIC_BASE=http://10.42.0.1:8889
php -S 10.42.0.1:8889 index.php
```

| Variável / flag | Função | Omissão |
|-----------------|--------|---------|
| `--mode` / `VE_MODE` | Modo E3 do processo | *(obrigatório)* |
| `--data` / `VE_DATA` | Raiz de dados do nó | *(obrigatório)* |
| `--host` / `--port` | Escuta do `ve-http` | `10.42.0.1` / `8888` |
| `VE_PUBLIC_BASE` | URL pública (proxy TLS) | definida pelo `ve-http` |
| `VE_ADMIN_LOGIN` | Login do 1.º administrador | `admin` |
| `VE_ADMIN_PASS` | Senha do 1.º administrador | `AdminPoC1!` |

Na **primeira** execução HTTP, se ainda não existir administrador em `identity.json`,
o sistema cria um com `VE_ADMIN_LOGIN` / `VE_ADMIN_PASS`. **Alterar a omissão** antes
de exposição em rede.

### 4. Abrir no navegador

- Login operador: `/login`
- Painel: `/painel` (e sub-rotas conforme o modo)
- Eleitor / cabine: `/voto`, `/voto/cabina`, `/voto/obrigado`
- CSS: `/assets/…`

Courier entre nós: `dirname(VE_DATA)/courier` (ex.: `/var/lib/ve/courier` se os três
`data` forem irmãos sob `/var/lib/ve/`).

### 5. Proxy nginx (opcional)

Manter os mesmos caminhos públicos (`/login`, `/painel`, `/voto`, `/api/…` se usados):

```nginx
location / {
    proxy_pass http://127.0.0.1:8889;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Definir `VE_PUBLIC_BASE=https://voto.exemplo.gov.br` no processo PHP.

Documentação: [`docs/activar-standalone.md`](docs/activar-standalone.md),
[`docs/operacao-standalone.md`](docs/operacao-standalone.md).

## Ciclo operador (resumo)

1. **KA** — cadastrar autoridades em `/painel/autoridades` → `/painel/keygen` (seleccionar exactamente *n* autoridades) → chave + parcelas atribuídas + courier.
2. **Voting** — cadastro `.rsv` em `/painel/cadastro` → `/voto` → courier (material).
3. **Tallying** — `/painel/importar` + parcelas → `/painel/certificar`.

Sem autoridades eleitorais cadastradas no nó KA, a geração/atribuição de parcelas Shamir **não** avança.

Piloto criptográfico CLI (sem browser): `php bin/ve-node pilot --root=/tmp/ve-piloto`.

## Formato RSV

```text
# comentario
identificador;nome;papel
```

Papéis: `voter` | `candidate` | `both`. Extensão recomendada: `.rsv`.

## Testes

```bash
composer install
./vendor/bin/phpunit -c phpunit.xml
```

## Estrutura

```text
index.php                 # front controller HTTP
bin/ve-http               # servidor embutido (um modo por processo)
bin/ve-node               # piloto / utilitários de nó
src/Domain/               # criptografia, eleição, cadastro, material
src/Adapters/Standalone/  # ficheiros, HTTP, identidade, sessões
assets/                   # CSS da UI
docs/                     # activação, operação, verificação
```

## Segurança

- Não partilhar `VE_DATA` nem segredos entre nós.
- Não registar parcelas em claro em auditoria.
- Ver [`SECURITY.md`](SECURITY.md).

## Documentação

| Documento | Conteúdo |
|-----------|----------|
| [`docs/activar-standalone.md`](docs/activar-standalone.md) | Instalação e primeiro arranque |
| [`docs/operacao-standalone.md`](docs/operacao-standalone.md) | Três nós, courier, becape, proxy |
| [`docs/verificacao-http-standalone.md`](docs/verificacao-http-standalone.md) | Checklist HTTP |
| [`docs/piloto-3-nos.md`](docs/piloto-3-nos.md) | Guião de piloto |
| [`docs/roadmap-independencia-adapter.md`](docs/roadmap-independencia-adapter.md) | Roadmap técnico |

Documentos que descrevem *plugin WordPress*, *wp-admin* ou *Adapter #1* são **legado
histórico** (migração). O caminho suportado para operação nova é o **standalone HTTP**.

## Aviso

Não utilizar em eleições vinculativas sem revisão criptográfica independente,
testes de penetração e certificação eleitoral/legal aplicável.

## Licença

GPL-2.0-or-later
