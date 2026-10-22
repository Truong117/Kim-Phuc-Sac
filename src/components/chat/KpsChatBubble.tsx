import { useEffect, useId, useRef, useState } from "react";
import { ChatIcon, CloseIcon } from "@/icons";
import { useSidebar } from "@/context/SidebarContext";

/**
 * KPS Chat Bubble V1 is presentational only.
 * No messages are sent or stored; no API, AI provider, or database is accessed.
 */
export default function KpsChatBubble() {
  const [open, setOpen] = useState(false);
  const { isMobileOpen } = useSidebar();
  const panelId = useId();
  const launcherRef = useRef<HTMLButtonElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  const hasOpenedRef = useRef(false);

  // Restore keyboard focus when the window is closed and launcher remounts.
  useEffect(() => {
    if (open) {
      hasOpenedRef.current = true;
      closeRef.current?.focus();
    } else if (hasOpenedRef.current) {
      launcherRef.current?.focus();
    }
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };

    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open]);

  // Do not float above the mobile navigation drawer.
  if (isMobileOpen) return null;

  return (
    <div className="fixed right-4 bottom-5 z-[100000] flex flex-col items-end gap-3 sm:right-6 sm:bottom-6">
      {open && (
        <section
          aria-labelledby={panelId}
          className="flex max-h-[min(540px,calc(100dvh-104px))] w-[min(380px,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-[#d8cab9] bg-white shadow-[0_20px_70px_rgba(36,25,16,0.22)] dark:border-gray-700 dark:bg-gray-900"
        >
          <div className="flex items-center justify-between gap-3 bg-[#785737] px-4 py-4 text-white">
            <div className="flex min-w-0 items-center gap-3">
              <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/15">
                <ChatIcon className="size-6" aria-hidden="true" />
              </div>
              <div className="min-w-0">
                <h2 id={panelId} className="truncate text-sm font-semibold">KPS AI Assistant</h2>
                <p className="text-xs text-white/80">Trợ lý nội bộ Kim Phục Sắc</p>
              </div>
            </div>
            <button
              ref={closeRef}
              type="button"
              onClick={() => setOpen(false)}
              aria-label="Đóng cửa sổ trợ lý"
              className="flex size-9 shrink-0 items-center justify-center rounded-lg text-white/90 transition-colors hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
              <CloseIcon className="size-5" aria-hidden="true" />
            </button>
          </div>

          <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain bg-[#fbf8f4] px-4 py-4 dark:bg-gray-900">
            <div className="flex justify-start">
              <div className="max-w-[95%] rounded-2xl rounded-tl-md border border-[#e7ddd1] bg-white p-4 text-sm leading-6 text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                <p className="font-semibold text-[#785737] dark:text-[#e3c7a5]">
                  Xin chào! 👋
                </p>
                <p className="mt-2">
                  Tôi là trợ lý KPS. Giao diện chat đã sẵn sàng và tính năng
                  trò chuyện sẽ được bổ sung trong các phiên bản tiếp theo.
                </p>
              </div>
            </div>

            <div className="space-y-2">
              <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                Tính năng dự kiến
              </p>
              <div className="rounded-xl border border-[#eadfd3] bg-white px-3 py-2.5 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <span aria-hidden="true">📋 </span>Tra cứu báo cáo công việc
              </div>
              <div className="rounded-xl border border-[#eadfd3] bg-white px-3 py-2.5 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <span aria-hidden="true">📈 </span>Xem số liệu theo quyền truy cập
              </div>
              <div className="rounded-xl border border-[#eadfd3] bg-white px-3 py-2.5 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <span aria-hidden="true">💡 </span>Hướng dẫn sử dụng hệ thống
              </div>
            </div>
          </div>

          <div className="border-t border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div className="flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2 dark:border-gray-700 dark:bg-gray-800">
              <input
                type="text"
                disabled
                aria-label="Ô nhập tin nhắn chưa khả dụng"
                placeholder="Chat sẽ sớm ra mắt..."
                className="min-w-0 flex-1 bg-transparent px-2 py-1.5 text-sm text-gray-500 placeholder:text-gray-400 disabled:cursor-not-allowed dark:text-gray-400 dark:placeholder:text-gray-500"
              />
              <button
                type="button"
                disabled
                aria-label="Gửi tin nhắn (chưa khả dụng)"
                className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-gray-400 disabled:cursor-not-allowed dark:bg-gray-700 dark:text-gray-500"
              >
                <svg
                  aria-hidden="true"
                  viewBox="0 0 24 24"
                  className="size-5"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="m22 2-7 20-4-9-9-4Z" />
                  <path d="M22 2 11 13" />
                </svg>
              </button>
            </div>
            <p className="mt-2 text-center text-[11px] text-gray-400 dark:text-gray-500">
              Bản giao diện thử nghiệm · Chưa kết nối AI
            </p>
          </div>
        </section>
      )}

      {!open && (
        <button
          ref={launcherRef}
          type="button"
          onClick={() => setOpen(true)}
          aria-label="Mở trợ lý KPS"
          className="group relative flex size-14 items-center justify-center rounded-full bg-[#785737] text-white shadow-[0_7px_28px_rgba(83,55,31,0.3)] ring-4 ring-white/70 transition-transform duration-200 hover:scale-105 hover:bg-[#63462d] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#785737] dark:ring-gray-900/70"
        >
          <ChatIcon className="size-7" aria-hidden="true" />
        </button>
      )}
    </div>
  );
}
