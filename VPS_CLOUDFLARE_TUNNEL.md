# DayFlow on Ubuntu + Docker + Cloudflare Tunnel

## 1. Clone the public repository

```bash
sudo mkdir -p /opt/dayflow
sudo chown -R "$USER":"$USER" /opt/dayflow
git clone https://github.com/faizbenbenyapol/DayFlow_Website.git /opt/dayflow
cd /opt/dayflow
cp .env.example .env
chmod 600 .env
nano .env
```

Set the production values in `.env`:

```env
APP_URL=https://dayflow.benyapol.com
DOMAIN=dayflow.benyapol.com
APP_KEY=<64-character-random-secret>
CRON_TOKEN=<long-random-secret>
GOOGLE_CLIENT_ID=<google-client-id>
MARIADB_DATABASE=dayflow
MARIADB_USER=dayflow_app
MARIADB_PASSWORD=<long-random-password>
MARIADB_ROOT_PASSWORD=<another-long-random-password>
TIMEZONE=Asia/Bangkok
```

Generate secrets on the server without putting them in GitHub:

```bash
openssl rand -hex 32
openssl rand -base64 48
```

## 2. Start DayFlow for Cloudflare Tunnel

This keeps port 8080 bound to localhost only. Do not expose it through the router or firewall.

```bash
cd /opt/dayflow
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml up -d db app
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml exec -T app php scripts/migrate.php
curl -fsS http://127.0.0.1:8080/health
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml ps
```

## 3. Connect Cloudflare Tunnel

In Cloudflare Zero Trust, create a Public Hostname:

```text
Hostname: dayflow.benyapol.com
Service: http://127.0.0.1:8080
```

If `cloudflared` is already installed, install the tunnel as a service using the token from Cloudflare:

```bash
sudo cloudflared service install <YOUR_TUNNEL_TOKEN>
sudo systemctl enable --now cloudflared
sudo systemctl status cloudflared --no-pager
```

The Cloudflare Tunnel connection is outbound, so the mini PC does not need inbound port forwarding for 80/443.

## 4. Update the server after a GitHub push

```bash
cd /opt/dayflow
git pull --ff-only origin main
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml build app
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml up -d db app
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml exec -T app php scripts/migrate.php
curl -fsS http://127.0.0.1:8080/health
```

Never run `git pull` for `.env`; it is intentionally ignored and must stay only on the server.

## 5. Useful checks

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml logs --tail=100 app
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml ps
sudo journalctl -u cloudflared -n 100 --no-pager
```
