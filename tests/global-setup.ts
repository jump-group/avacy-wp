import { request, APIRequestContext } from '@playwright/test';

const PORTS = (process.env.PLAYGROUND_PORTS ?? '9400')
  .split(',')
  .map((p) => p.trim())
  .filter(Boolean);

const MAX_WAIT_MS = 180_000;
const POLL_INTERVAL_MS = 1_000;
const READINESS_MARKER = 'avacy-cdn.com';

/**
 * Playwright starts tests as soon as the webServer port is listening, but
 * wp-playground-cli needs extra seconds to bootstrap WordPress + apply the
 * blueprint on the first request. We poll each blueprint's front-end until
 * the Avacy plugin has enqueued its scripts.
 */
async function waitForPort(ctx: APIRequestContext, port: string) {
  const baseUrl = `http://127.0.0.1:${port}`;
  const start = Date.now();

  while (Date.now() - start < MAX_WAIT_MS) {
    try {
      const res = await ctx.get(`${baseUrl}/`, { maxRedirects: 10 });
      const body = await res.text();
      if (res.ok() && body.includes(READINESS_MARKER)) {
        const elapsed = ((Date.now() - start) / 1000).toFixed(1);
        console.log(`✓ Playground on :${port} ready after ${elapsed}s`);
        return;
      }
    } catch {
      // keep polling
    }
    await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
  }

  throw new Error(`Timed out after ${MAX_WAIT_MS}ms waiting for "${READINESS_MARKER}" on :${port}`);
}

export default async function globalSetup() {
  const ctx = await request.newContext({ ignoreHTTPSErrors: true });
  try {
    await Promise.all(PORTS.map((p) => waitForPort(ctx, p)));
  } finally {
    await ctx.dispose();
  }
}
