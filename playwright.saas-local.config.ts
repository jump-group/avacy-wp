import { defineConfig, devices } from '@playwright/test';
import { assertLocalUrl } from './tests/saas-local/support/local-only';

/**
 * Test plugin WP → SaaS locale. Si lancia con `yarn test:plugin-saas` dalla radice:
 * run.sh accende lo stack, crea i dati, avvia Playground e passa porta e credenziali per env.
 * Importare local-only attiva il blocco del runner Node (solo host locali).
 */
const baseURL = assertLocalUrl(`http://127.0.0.1:${process.env.SAAS_WP_PORT ?? '9431'}`, 'Playground');
assertLocalUrl(process.env.SAAS_LOCAL_API ?? 'http://apiavacy.test:3000', 'SAAS_LOCAL_API');

export default defineConfig({
  testDir: './tests/saas-local',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  workers: 1,
  reporter: [['list']],
  outputDir: './tests/saas-local/guard-log/test-results',
  use: { ...devices['Desktop Chrome'], baseURL, trace: 'retain-on-failure' },
});
