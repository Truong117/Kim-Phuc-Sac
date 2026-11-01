import Button from "@/components/ui/button/Button";
import { Component, type ErrorInfo, type ReactNode } from "react";

type Props = {
  children: ReactNode;
  title: string;
  description: string;
  closeLabel: string;
  retryLabel: string;
  onClose: () => void;
  onRetry: () => void;
};

type State = { failed: boolean };

export default class WeeklyPlanAiPreviewErrorBoundary extends Component<
  Props,
  State
> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error("Weekly Planning AI preview render failed", error, info);
  }

  render() {
    if (!this.state.failed) return this.props.children;

    return (
      <div
        role="alert"
        className="mt-5 rounded-xl border border-error-200 bg-error-50 p-5 dark:border-error-900 dark:bg-error-500/10"
      >
        <p className="font-semibold text-error-700 dark:text-error-400">
          {this.props.title}
        </p>
        <p className="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
          {this.props.description}
        </p>
        <div className="mt-5 flex flex-wrap justify-end gap-2">
          <Button type="button" variant="outline" onClick={this.props.onClose}>
            {this.props.closeLabel}
          </Button>
          <Button
            type="button"
            className="bg-kps-primary hover:bg-kps-primary-hover"
            onClick={this.props.onRetry}
          >
            {this.props.retryLabel}
          </Button>
        </div>
      </div>
    );
  }
}
