const backend = "http://127.0.0.1:8001";
const frontend = "http://127.0.0.1:5174";

export default async function verifyLocalStack() {
  const waitFor = async (url: string) => {
    for (let attempt = 0; attempt < 30; attempt += 1) {
      try {
        const response = await fetch(url);
        if (response.ok) return response;
      } catch {
        // Both servers start in the same local runner.
      }
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
    throw new Error(`Local E2E server did not start: ${url}`);
  };

  const backendResponse = await waitFor(`${backend}/api/health`);
  if (
    !backendResponse.ok ||
    backendResponse.headers.get("x-kps-e2e-verified") !== "phase7b2-v1"
  ) {
    throw new Error("Backend is not the guarded local E2E server.");
  }
  await waitFor(frontend);
}
