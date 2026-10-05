# KPS Internal System

Hệ thống quản lý nội bộ dành cho Kim Phục Sắc. Frontend hiện cung cấp nền tảng giao diện và prototype cho Dashboard quản lý, Báo cáo công việc hằng ngày và Lịch sử báo cáo. Giai đoạn tiếp theo dự kiến tập trung vào CRM/Kinh doanh sau khi yêu cầu nghiệp vụ được xác nhận.

## Frontend stack

- React 19 và TypeScript
- Vite 8
- Tailwind CSS v4
- React Router
- react-i18next
- ApexCharts

## Chạy local

Yêu cầu Node.js `>=20.19.0` hoặc `>=22.12.0`.

```bash
npm install
npm run dev
```

## Kiểm tra production build

```bash
npm run build
```

Lint source:

```bash
npm run lint
```

Thông tin kiến trúc, trạng thái module và định hướng tiếp theo được duy trì tại [docs/KPS_PROJECT_CONTEXT.md](docs/KPS_PROJECT_CONTEXT.md).

Project được phát triển từ TailAdmin React Free. Thông tin giấy phép gốc được giữ trong [LICENSE.md](LICENSE.md).
