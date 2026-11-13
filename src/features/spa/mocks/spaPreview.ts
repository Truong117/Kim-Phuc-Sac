import type { SpaPreviewSnapshot, SpaAppointment } from "../types";
import { spaBusinessFixture, spaServicesFixture } from "./businessPreview";

// Synthetic demonstration records. Never replace these with actual customer data.
const appointments: Pick<
  SpaAppointment,
  "id" | "customerName" | "startTime" | "status" | "tasks"
>[] = [
  {
    id: "SPA-001",
    customerName: "Nguyễn Minh Anh",
    startTime: "09:00",
    status: "arrived",
    tasks: [
      {
        id: "task-1",
        serviceKey: "skinRecovery",
        technicianId: "tech-1",
        technicianName: "Lan Anh",
        room: "01",
        startTime: "09:00",
        durationMinutes: 60,
        status: "in_progress",
        requiresSkinPhoto: true,
        noteKey: "sensitiveSkin",
      },
    ],
  },
  {
    id: "SPA-002",
    customerName: "Trần Ngọc Linh",
    startTime: "09:30",
    status: "arrived",
    tasks: [
      {
        id: "task-2",
        serviceKey: "deepClean",
        technicianId: "tech-2",
        technicianName: "Ngọc Mai",
        room: "02",
        startTime: "09:30",
        durationMinutes: 60,
        status: "assigned",
        requiresSkinPhoto: true,
        noteKey: "firstVisit",
      },
    ],
  },
  {
    id: "SPA-003",
    customerName: "Lê Thanh Hà",
    startTime: "10:30",
    status: "arrived",
    tasks: [
      {
        id: "task-3",
        serviceKey: "skinRecovery",
        technicianId: "tech-1",
        technicianName: "Lan Anh",
        room: "01",
        startTime: "10:30",
        durationMinutes: 60,
        status: "assigned",
        requiresSkinPhoto: true,
        noteKey: "reviewPreviousSession",
      },
    ],
  },
  {
    id: "SPA-004",
    customerName: "Phạm Bảo Ngọc",
    startTime: "12:30",
    status: "booked",
    tasks: [
      {
        id: "task-4",
        serviceKey: "deepClean",
        technicianId: "tech-1",
        technicianName: "Lan Anh",
        room: "01",
        startTime: "12:30",
        durationMinutes: 60,
        status: "assigned",
        requiresSkinPhoto: true,
        noteKey: "firstVisit",
      },
      {
        id: "task-5",
        serviceKey: "relaxation",
        technicianId: "tech-2",
        technicianName: "Ngọc Mai",
        room: "03",
        startTime: "13:30",
        durationMinutes: 45,
        status: "assigned",
        requiresSkinPhoto: false,
        noteKey: "noSpecialNote",
      },
    ],
  },
  {
    id: "SPA-005",
    customerName: "Võ Thu Hương",
    startTime: "08:00",
    status: "arrived",
    tasks: [
      {
        id: "task-6",
        serviceKey: "relaxation",
        technicianId: "tech-1",
        technicianName: "Lan Anh",
        room: "03",
        startTime: "08:00",
        durationMinutes: 45,
        status: "completed",
        requiresSkinPhoto: false,
        noteKey: "noSpecialNote",
      },
    ],
  },
  {
    id: "SPA-006",
    customerName: "Đỗ Mỹ Duyên",
    startTime: "15:00",
    status: "cancelled",
    tasks: [],
  },
];

const date = "2026-11-11";
export const spaPreviewFixture: SpaPreviewSnapshot = {
  ...spaBusinessFixture,
  date,
  skinPhotos: [],
  previewTechnician: { id: "tech-1", name: "Lan Anh" },
  customers: appointments.map((item, index) => ({
    id: `customer-${index + 1}`,
    name: item.customerName,
    phone: `000000000${index + 1}`,
    note: "",
  })),
  services: spaServicesFixture,
  technicians: [
    { id: "tech-1", name: "Lan Anh" },
    { id: "tech-2", name: "Ngọc Mai" },
    { id: "tech-3", name: "Bích Ngọc" },
  ],
  beds: ["01", "02", "03"].map((room) => ({
    id: `bed-${room}`,
    room,
    number: "01",
  })),
  appointments: appointments.map((item, index) => ({
    ...item,
    date,
    customerId: `customer-${index + 1}`,
    note: "",
    receivedBy:
      item.status === "arrived"
        ? { id: "sample-reception", name: "Lễ tân mẫu" }
        : undefined,
    receivedAt: item.status === "arrived" ? `${date}T01:00:00.000Z` : undefined,
    tasks: item.tasks.map((task) => ({
      ...task,
      serviceName: spaServicesFixture.find((s) => s.key === task.serviceKey)
        ?.name,
      unitPrice: spaServicesFixture.find((s) => s.key === task.serviceKey)
        ?.price,
      packageId:
        task.id === "task-1"
          ? "PKG-001"
          : task.id === "task-3"
            ? "PKG-002"
            : undefined,
      result:
        task.status === "completed"
          ? "Đã hoàn tất buổi chăm sóc mẫu."
          : undefined,
      participants: [task.technicianId],
      bedId: `bed-${task.room}`,
      bedNumber: "01",
      assignedBy: { id: "sample-dispatch", name: "Điều phối mẫu" },
    })),
    audit: [],
  })),
};
