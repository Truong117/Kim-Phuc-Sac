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

Xem hướng dẫn cấu hình MySQL và health endpoint trong [backend/README.md](backend/README.md).

Thông tin kiến trúc, trạng thái module và định hướng tiếp theo được duy trì tại [docs/KPS_PROJECT_CONTEXT.md](docs/KPS_PROJECT_CONTEXT.md).

Project được phát triển từ TailAdmin React Free. Thông tin giấy phép gốc được giữ trong [LICENSE.md](LICENSE.md).
