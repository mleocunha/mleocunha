# Deploy — Ubuntu 22.04

## Usuário e diretórios

```bash
sudo useradd --system --home /var/lib/relatasoft-openclaw --shell /usr/sbin/nologin relatasoft-openclaw
sudo mkdir -p /opt/relatasoft-openclaw /var/lib/relatasoft-openclaw /var/log/relatasoft-openclaw /etc/relatasoft-openclaw
sudo chown -R relatasoft-openclaw:relatasoft-openclaw /var/lib/relatasoft-openclaw /var/log/relatasoft-openclaw
```

## Aplicação

Copiar build para `/opt/relatasoft-openclaw`, instalar com pnpm, configurar `/etc/relatasoft-openclaw/gateway.env` (sem secrets em git).

## systemd

Instalar `deployment/systemd/relatasoft-openclaw-gateway.service` e habilitar:

```bash
sudo systemctl enable --now relatasoft-openclaw-gateway
```

Bind em `127.0.0.1:8788`.

## Nginx

Usar `deployment/nginx/openclaw-api.relatasoft.com.br.conf` com certificado TLS (Let's Encrypt / Virtualmin).

## Rollback

1. `systemctl stop relatasoft-openclaw-gateway`
2. Restaurar release anterior em `/opt/relatasoft-openclaw`
3. `systemctl start relatasoft-openclaw-gateway`
