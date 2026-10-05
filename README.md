# OzePMS

Multi-property hotel Property Management System by E2X Infotech Pvt Ltd.

* Start here: [`PROJECT_STATUS.md`](PROJECT_STATUS.md)
* Coding conventions and module ownership: [`docs/04-development-guide.md`](docs/04-development-guide.md)
* Local setup (macOS): [`docs/05-local-setup.md`](docs/05-local-setup.md)
* Production deployment (Ubuntu, Nginx, PHP-FPM): [`docs/06-deployment.md`](docs/06-deployment.md)

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # optional demo data
npm run build
php artisan test
```

© E2X Infotech Pvt Ltd. All rights reserved.
