# KPS Internal System

Hệ thống quản lý nội bộ dành cho Kim Phục Sắc. Repository gồm frontend React/Vite hiện tại và nền tảng API Laravel 12 để phát triển các chức năng backend theo từng giai đoạn.

## Cấu trúc repository

- Frontend: React 19, TypeScript, Vite 8 và Tailwind CSS v4 tại thư mục root.
- Backend: Laravel 12 API tại [`backend/`](backend/README.md).

## Chạy frontend local

Yêu cầu Node.js `>=20.19.0` hoặc `>=22.12.0`.

```bash
npm install
cp .env.example .env.local
npm run dev
```

`VITE_API_BASE_URL` trong `.env.local` phải trỏ tới Laravel backend; convention local được khuyến nghị là `http://localhost:8000`.

Trên Vercel, Production/Preview dùng `VITE_API_BASE_URL=/` và biến server-side `KPS_BACKEND_ORIGIN` để `vercel.mjs` proxy riêng `/api` và `/sanctum` tới đúng Render environment. Không cấu hình browser gọi trực tiếp từ `vercel.app` sang `onrender.com`, và không cho Preview trỏ tới backend production. Xem runbook [An toàn môi trường và triển khai KPS](docs/ENVIRONMENT_DEPLOYMENT_SAFETY.md).

Kiểm tra frontend:

```bash
npm run build
npm run lint
```

## Chạy backend local

Yêu cầu PHP 8.2 trở lên và Composer 2.

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Backend local dùng SQLite theo cấu hình mẫu. Trước khi chạy migration, phải xác nhận `DB_CONNECTION=sqlite` và `DB_DATABASE` trỏ tới file local; không sao chép thông tin TiDB/production vào `.env` development.

Xem hướng dẫn backend trong [backend/README.md](backend/README.md) và runbook [An toàn môi trường và triển khai KPS](docs/ENVIRONMENT_DEPLOYMENT_SAFETY.md) trước khi cấu hình staging, production, migration hoặc object storage.

Thông tin kiến trúc, trạng thái module và định hướng tiếp theo được duy trì tại [docs/KPS_PROJECT_CONTEXT.md](docs/KPS_PROJECT_CONTEXT.md).

Project được phát triển từ TailAdmin React Free. Thông tin giấy phép gốc được giữ trong [LICENSE.md](LICENSE.md).
