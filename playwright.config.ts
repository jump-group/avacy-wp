import { defineConfig, devices } from '@playwright/test';

/**
 * Matrix of blueprints. Each entry spins up a wp-playground instance on its
 * own port and runs the matching spec file.
 */
const BLUEPRINTS = [
  { name: 'with-html-forms', port: 9400, testMatch: 'banner-visible.spec.ts' },
  { name: 'with-wp-consent-api', port: 9401, testMatch: 'wp-consent-api.spec.ts' },
];

process.env.PLAYGROUND_PORTS = BLUEPRINTS.map((b) => b.port).join(',');

export default defineConfig({
  testDir: './tests',
  testIgnore: ['**/global-setup.ts'],
  globalSetup: require.resolve('./tests/global-setup'),
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],

  use: {
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },

  projects: BLUEPRINTS.map((b) => ({
    name: b.name,
    testMatch: b.testMatch,
    use: {
      ...devices['Desktop Chrome'],
      baseURL: `http://127.0.0.1:${b.port}`,
    },
  })),

  webServer: BLUEPRINTS.map((b) => ({
    command: `bash ./scripts/playground.sh ${b.name} ${b.port}`,
    port: b.port,
    timeout: 180_000,
    reuseExistingServer: !process.env.CI,
    stdout: 'pipe',
    stderr: 'pipe',
  })),
});
