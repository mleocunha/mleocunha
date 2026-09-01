# Activar o Voto Eletrônico (standalone)

Guia de activação **do zero**: um processo PHP por nó, sem CMS hospedeiro.

## Pré-requisitos

1. PHP 8.2 ou superior.
2. Extensões: `gmp`, `json`, `mbstring`, `openssl`.
3. Composer 2 no PATH.
4. Utilizador com escrita no directorio de dados.

```bash
php -v
php -m | grep -E 'gmp|json|mbstring|openssl'
```

## Passo a passo

### A. Pacote

Trabalhar dentro de `relatasoft-secure-election-suite/` (onde estão `index.php` e `composer.json`).

### B. Dependências

```bash
composer install --no-dev --optimize-autoloader   # produção
# desenvolvimento:
composer install && ./vendor/bin/phpunit -c phpunit.xml
```

### C. Directorios de dados

Três nós + courier partilhado (irmãos sob a mesma raiz):

```bash
sudo mkdir -p /var/lib/ve/{ka,voting,tallying,courier}
sudo chown -R ve-operador:ve-operador /var/lib/ve
```

Com `--data=/var/lib/ve/ka`, o courier por omissão é `/var/lib/ve/courier`.

### D. Credenciais iniciais

| Variável | Omissão |
|----------|---------|
| `VE_ADMIN_LOGIN` | `admin` |
| `VE_ADMIN_PASS` | `AdminPoC1!` |

Produção: definir senha forte **antes** do primeiro arranque HTTP de cada nó
(cada nó tem o seu `identity.json`).

### E. Arrancar

```bash
cd /opt/voto-eletronico/relatasoft-secure-election-suite   # exemplo

php bin/ve-http --mode=key_authority --data=/var/lib/ve/ka --host=127.0.0.1 --port=8881
php bin/ve-http --mode=voting        --data=/var/lib/ve/voting --host=127.0.0.1 --port=8882
php bin/ve-http --mode=tallying      --data=/var/lib/ve/tallying --host=127.0.0.1 --port=8883
```

`bin/ve-http` define `VE_MODE`, `VE_DATA` e `VE_PUBLIC_BASE` e lança `php -S` com `index.php`.

Modo directo:

```bash
export VE_MODE=voting
export VE_DATA=/var/lib/ve/voting
export VE_PUBLIC_BASE=https://voto.exemplo.gov.br
php -S 127.0.0.1:8882 index.php
```

Sem `VE_MODE` ou `VE_DATA` válidos, `index.php` responde 500 com mensagem clara.

### F. Confirmar

| URL | Esperado |
|-----|----------|
| `/login` | Formulário de operador |
| `/painel` | Home do modo (após login) |
| `/voto` | Só no modo `voting` (jornada) |
| `/assets/painel/css/shell.css` | Folha de estilo |

Admin bootstrap: credenciais de `VE_ADMIN_*`.

### G. systemd (exemplo — nó de votação)

```ini
[Unit]
Description=Voto Eletronico voting
After=network.target

[Service]
Type=simple
User=ve-operador
WorkingDirectory=/opt/voto-eletronico/relatasoft-secure-election-suite
Environment=VE_MODE=voting
Environment=VE_DATA=/var/lib/ve/voting
Environment=VE_PUBLIC_BASE=https://voto.exemplo.gov.br
EnvironmentFile=-/etc/ve/voting.env
ExecStart=/usr/bin/php bin/ve-http --mode=voting --data=/var/lib/ve/voting --host=127.0.0.1 --port=8882
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Colocar TLS no nginx à frente; PHP só em loopback. Ver `docs/operacao-standalone.md`.

## O que *não* fazer

- Não activar como plugin de CMS.
- Não reutilizar o mesmo `VE_DATA` em dois processos.
- Não expor a porta do PHP sem TLS / controlo de acesso.
- Não deixar `AdminPoC1!` acessível em rede.

## Próximos passos

- `docs/operacao-standalone.md` — courier, becape, três sítios  
- `docs/piloto-3-nos.md` — guião de piloto  
- `docs/verificacao-http-standalone.md` — checklist  
