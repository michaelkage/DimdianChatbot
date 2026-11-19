import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { chromium } from 'playwright';

const root = new URL('../docs/', import.meta.url).pathname;
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8' };

test('real widget works on desktop and mobile in demo mode', async () => {
  const server = createServer(async (req, res) => {
    try {
      const pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
      const file = normalize(join(root, pathname === '/' ? 'index.html' : pathname));
      if (!file.startsWith(normalize(root))) throw new Error('Invalid path');
      const body = await readFile(file);
      res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' });
      res.end(body);
    } catch {
      res.writeHead(404); res.end('Not found');
    }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    await page.goto(`http://127.0.0.1:${address.port}/`);
    const widget = page.locator('#dimdian-chat-widget');
    const launcher = widget.locator('.launcher');
    await launcher.waitFor();
    await launcher.click();
    const panel = widget.locator('.panel');
    await assert.doesNotReject(() => panel.waitFor({ state: 'visible' }));
    await assert.doesNotReject(() => widget.locator('.fields').evaluate(el => {
      if (!el.hidden) throw new Error('Name and phone fields should be hidden in demo mode');
    }));
    await assert.doesNotReject(() => widget.locator('.wa-consent').evaluate(el => {
      if (!el.hidden) throw new Error('WhatsApp opt-in should be hidden in demo mode');
    }));

    const input = widget.locator('textarea');
    await input.fill('Hello, I would like to know more.');
    await widget.locator('.send').click();
    await page.getByText('Hello, I would like to know more.').waitFor();
    await page.getByText(/This is a simulated support reply/).waitFor({ timeout: 5000 });
    assert.equal(await widget.locator('.message').count(), 2);

    await widget.locator('.close').click();
    await launcher.click();
    assert.equal(await widget.locator('.message').count(), 2, 'conversation remains visible after close and reopen');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await widget.locator('.launcher').waitFor();
    const box = await widget.locator('.launcher').boundingBox();
    assert.ok(box && box.x >= 0 && box.x + box.width <= 390, 'launcher fits the mobile viewport');
    await widget.locator('.launcher').click();
    const panelBox = await widget.locator('.panel').boundingBox();
    assert.ok(panelBox && panelBox.width <= 390 && panelBox.x >= 0, 'chat panel fits the mobile viewport');

    // The owner-only integration console must load as a static page and must
    // request its access key at runtime rather than embedding credentials.
    await page.goto(`http://127.0.0.1:${address.port}/whatsapp-test.html`);
    await page.getByRole('heading', { name: 'WhatsApp webhook monitor.' }).waitFor();
    assert.equal(await page.locator('#key').getAttribute('type'), 'password');
    assert.equal(await page.locator('#status').textContent(), 'Not connected.');
    assert.equal(await page.locator('#project').getAttribute('value'), null, 'project URL is not prefilled');
    assert.equal(await page.locator('#key').getAttribute('value'), null, 'monitor key is not embedded in the page');
  } finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
});
