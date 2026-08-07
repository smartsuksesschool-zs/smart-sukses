> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 3.3 Deployment Topology

## 3.3.1 Infrastruktur VPS (Single Server — Phase 1)

Untuk skala awal (3 cabang, 50–200 siswa per cabang), satu VPS cukup untuk semua komponen. Skalabilitas vertikal (upgrade RAM/CPU) tersedia jika dibutuhkan.

| **Komponen** | **Lokasi** | **Port / Path** | **Keterangan** |
| --- | --- | --- | --- |
| Nginx | VPS /etc/nginx/ | 80, 443 | Reverse proxy, SSL termination, static file serving |
| PHP-FPM | VPS (PHP 8.3) | 9000 | FastCGI process manager untuk Laravel |
| Laravel App | VPS /var/www/smartsukses/ | – | Aplikasi utama. 1 instance melayani semua tenant |
| MySQL 8 | VPS localhost | 3306 | Database server (akses localhost only, tidak expose ke publik) |
| Laravel Queue | VPS (supervisor) | – | Worker untuk background jobs (PDF generate, bulk fee) |
| Certbot | VPS (cron harian) | – | Auto-renew Let's Encrypt SSL certificate |
| Cloudflare DNS | Cloudflare CDN | – | A record → IP VPS. Cache statis (CSS, JS, gambar) |

## 3.3.2 Konfigurasi Nginx (Single Domain)

server {
    listen 443 ssl;
    server_name apps.smartsukses.sch.id;
    root /var/www/smartsukses/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
