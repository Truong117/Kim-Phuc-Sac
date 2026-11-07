export const dailyMailFixture = {
  report_id: 12,
  employee_name: "Nguyễn Văn An",
  source_changed: false,
  source: { report_id: 12, employee_id: 2, date: "2026-11-05", items: [] },
  document: {
    id: 101,
    report_id: 12,
    status: "completed",
    requires_new_document: false,
    mode: "openai",
    source: { report_id: 12, employee_id: 2, date: "2026-11-05", items: [] },
    result: {
      work_groups: [
        {
          title: "Hoàn thành thiết kế",
          text: "Đã bàn giao 3 mẫu được duyệt.",
          evidence: [{ report_id: 12, item_id: 4, quote: "Bàn giao 3 mẫu" }],
        },
      ],
      comments: [
        {
          item_id: 4,
          text: "Kết quả có căn cứ bàn giao.",
          quote: "Bàn giao 3 mẫu",
        },
      ],
      recommendations: [
        {
          item_id: 4,
          text: "Xác nhận lịch triển khai với bộ phận liên quan.",
          quote: "Bàn giao 3 mẫu",
        },
      ],
      work_totals: [
        { metric: "Thiết kế", unit: "mẫu", total: 3, status: "confirmed" },
        {
          metric: "Doanh thu",
          unit: "đồng",
          total: 9000,
          status: "needs_confirmation",
        },
      ],
    },
    units: [],
  },
};

export const weeklyMailFixture = {
  id: 201,
  status: "completed",
  period: {
    start_date: "2026-10-30",
    end_date: "2026-11-05",
    days: 7,
    timezone: "Asia/Ho_Chi_Minh",
  },
  department_id: null,
  source_changed: false,
  config_changed: false,
  requires_new_run: false,
  is_mock: false,
  revision: { number: 2, root_id: 200, parent_id: 200 },
  result: {
    overview: [
      {
        text: "Các nhóm đã hoàn thành phần việc được giao.",
        evidence: [{ report_id: 12, item_id: 4, quote: "Bàn giao 3 mẫu" }],
      },
    ],
    highlights: [{ text: "Có 3 mẫu đã bàn giao.", evidence: [] }],
    ongoing: [{ text: "Đang chờ xác nhận triển khai.", evidence: [] }],
    attention: [{ text: "Cần quyết định lịch triển khai.", evidence: [] }],
    support: [{ text: "Cần bộ phận kinh doanh phản hồi.", evidence: [] }],
    uncertainties: [
      { text: "Chưa đủ dữ liệu để kết luận doanh thu.", evidence: [] },
    ],
    recommendations: [{ text: "Chốt lịch trước thứ Sáu.", evidence: [] }],
  },
};

export const dailySummaryFixture = {
  ...weeklyMailFixture,
  id: 301,
  period: {
    ...weeklyMailFixture.period,
    start_date: "2026-11-05",
    end_date: "2026-11-05",
    days: 1,
  },
  revision: { number: 1, root_id: 301, parent_id: null },
};
