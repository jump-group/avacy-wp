// Builds the site the demo boots from: runs the public recipe once in Chromium on the local
// engine, with the plugins from <root>/plugins/, and exports wp-content (plugins and SQLite
// database) to <root>/site.zip. Writes the one-step recipe that imports it, <root>/blueprint.json.
//   node snapshot.mjs <recipe.json> <root>
// <root> holds the engine (build-engine.sh), index.html and plugins/<slug>.zip.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { serve } from './serve.mjs';

const [recipeFile, rootArg] = process.argv.slice(2);
const root = path.resolve(rootArg);
const engine = JSON.parse(fs.readFileSync(path.join(root, 'engine.json'), 'utf8'));
const recipe = JSON.parse(fs.readFileSync(recipeFile, 'utf8'));

const slugOf = (data) => (data.resource === 'url' ? path.basename(new URL(data.url).pathname).split('.')[0] : data.slug);
const install = {
  ...recipe,
  preferredVersions: { php: engine.php, wp: engine.wp },
  steps: recipe.steps.map((s) => {
    if (s.step !== 'installPlugin') return s;
    const file = path.join(root, 'plugins', `${slugOf(s.pluginData)}.zip`);
    if (!fs.existsSync(file)) throw new Error(`missing ${file}`);
    return { ...s, pluginData: { resource: 'url', url: `{{BASE}}plugins/${path.basename(file)}` } };
  }),
};
fs.writeFileSync(path.join(root, 'install.json'), JSON.stringify(install, null, 2));

const server = await serve(root);
const url = `http://127.0.0.1:${server.address().port}/?install`;
const browser = await chromium.launch();
try {
  const page = await browser.newPage();
  page.on('console', (m) => m.type() === 'error' && console.error('console:', m.text().slice(0, 300)));
  await page.goto(url);
  await page.waitForFunction(() => window.__avacyPlayground, null, { timeout: 300000 });
  const { b64, plugins } = await page.evaluate(async () => {
    const { zipWpContent } = await import('/client/index.js');
    const client = window.__avacyPlayground.client;
    const out = await client.run({
      code: `<?php require '/wordpress/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php';
        global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
        $p = []; foreach (get_plugins() as $f => $d) { $p[] = $d['Name'] . ' ' . $d['Version'] . (is_plugin_active($f) ? '' : ' (inactive)'); }
        echo json_encode($p);`,
    });
    const bytes = await zipWpContent(client);
    let s = '';
    for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return { b64: btoa(s), plugins: JSON.parse(out.text) };
  });
  console.log('plugins:', plugins.join(', '));
  for (const name of ['Avacy', 'WP Consent API', 'Site Kit']) {
    if (!plugins.some((p) => p.includes(name) && !p.endsWith('(inactive)'))) throw new Error(`${name} is not active`);
  }
  fs.writeFileSync(path.join(root, 'site.zip'), Buffer.from(b64, 'base64'));
} finally {
  await browser.close();
  server.close();
}

const boot = {
  $schema: recipe.$schema,
  landingPage: recipe.landingPage ?? '/',
  preferredVersions: install.preferredVersions,
  login: true,
  // Otherwise Playground prefetches the WordPress.org update checks from the browser after boot.
  features: { networking: false },
  steps: [{ step: 'importWordPressFiles', wordPressFilesZip: { resource: 'url', url: '{{BASE}}site.zip' } }],
  meta: { ...recipe.meta, description: `Snapshot of ${path.basename(recipeFile)}. ${recipe.meta?.description ?? ''}`.trim() },
};
fs.writeFileSync(path.join(root, 'blueprint.json'), JSON.stringify(boot, null, 2) + '\n');
console.log('site.zip', fs.statSync(path.join(root, 'site.zip')).size, 'bytes');
