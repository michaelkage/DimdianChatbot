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
    assert.equal(await launcher.getAttribute('aria-expanded'), 'true');
    const panel = widget.locator('.panel');
    await assert.doesNotReject(() => panel.waitFor({ state: 'visible' }));
    await assert.doesNotReject(() => widget.locator('.fields').evaluate(el => {
      if (!el.hidden) throw new Error('Name and phone fields should be hidden in demo mode');
    }));
    await assert.doesNotReject(() => widget.locator('.wa-consent').evaluate(el => {
      if (!el.hidden) throw new Error('WhatsApp opt-in should be hidden in demo mode');
    }));

    const input = widget.locator('textarea');
    await widget.locator('.quick-reply').first().click();
    assert.equal(await input.inputValue(), 'I need help finding a product.');
    await input.fill('Hello, I would like to know more.');
    await widget.locator('.send').click();
    await page.getByText('Hello, I would like to know more.').waitFor();
    await page.getByText(/This is a simulated support reply/).waitFor({ timeout: 5000 });
    assert.equal(await widget.locator('.message').count(), 2);

    await widget.locator('.close').click();
    assert.equal(await launcher.getAttribute('aria-expanded'), 'false');
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
    await page.getByRole('heading', { name: 'Connection checks.' }).waitFor();
    assert.equal(await page.locator('#key').getAttribute('type'), 'password');
    assert.equal(await page.locator('#verify-token').getAttribute('type'), 'password');
    assert.equal(await page.locator('#monitor-status').textContent(), 'Not checked yet.');
    assert.equal(await page.locator('#project').inputValue(), 'https://gpafuuoxrbzveaddomgt.supabase.co', 'console points to the Dimdian Supabase project');
    assert.equal(await page.locator('#key').getAttribute('value'), null, 'monitor key is not embedded in the page');
    assert.equal(await page.locator('#verify-token').getAttribute('value'), null, 'webhook verify token is not embedded in the page');
    assert.equal(await page.locator('#verify-token').getAttribute('type'), 'password');
    await page.getByRole('button', { name: 'Test verification' }).waitFor();
    await page.getByRole('button', { name: 'Test & load events' }).waitFor();
  } finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
});


test('optional WooCommerce search renders safe product cards and creates a product enquiry', async () => {
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
    const page = await browser.newPage();
    await page.route('**/wp-json/chatbridge/v1/products?**', async route => {
      const url = new URL(route.request().url());
      assert.equal(url.searchParams.get('search'), 'brake pads');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          count: 1,
          products: [{
            name: 'Brake Pad Set',
            price: '₦45,000',
            url: 'https://store.example.test/product/brake-pad-set',
            image: 'https://store.example.test/product.jpg',
            stock_status: 'instock'
          }]
        })
      });
    });
    const origin = `http://127.0.0.1:${address.port}`;
    await page.goto(origin + '/whatsapp-test.html');
    await page.addScriptTag({
      url: origin + '/assets/widget.js',
      attributes: {
        'data-commerce-api': origin + '/wp-json/chatbridge/v1/products',
        'data-api-base': origin + '/api'
      }
    });
    const widget = page.locator('#dimdian-chat-widget');
    await widget.locator('.launcher').click();
    await widget.getByRole('button', { name: 'Find a product' }).click();
    await widget.locator('.commerce').waitFor({ state: 'visible' });
    await widget.locator('.commerce-search input').fill('brake pads');
    await widget.locator('.commerce-search button').click();
    await widget.getByText('Brake Pad Set').waitFor();
    await widget.getByText('₦45,000').waitFor();
    await widget.getByText('In stock').waitFor();
    await widget.getByRole('button', { name: 'Ask about this' }).click();
    const message = widget.locator('textarea');
    assert.match(await message.inputValue(), /Brake Pad Set/);
    assert.match(await message.inputValue(), /store\.example\.test\/product\/brake-pad-set/);
  } finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
});
