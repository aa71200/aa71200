import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const fixturePath = fileURLToPath(new URL('./fixture.php', import.meta.url));
const plugin = process.env.CODEXAWP_TEST_PLUGIN;
const modes = process.env.CODEXAWP_CHECKOUT_MODE ? [process.env.CODEXAWP_CHECKOUT_MODE] : ['classic', 'blocks'];
const fixture = (...args) => JSON.parse(execFileSync(process.env.CODEXAWP_TEST_PHP || 'php', [fixturePath, ...args], { encoding: 'utf8' }));

const north = { address: '143 Main Street', city: 'Los Angeles', state: 'CA', postcode: '90012' };
const south = { address: '84 Main Street', city: 'New York', state: 'NY', postcode: '10001' };

async function address(page, mode, value, guest) {
	const fields = {
		first_name: 'Portfolio', last_name: 'Customer', address_1: value.address,
		city: value.city, postcode: value.postcode, phone: '555-010-3456',
	};
	for (const [name, content] of Object.entries(fields)) {
		const selector = mode === 'classic' ? '#billing_' + name : '#shipping-' + name + ', #billing-' + name;
		const field = page.locator(selector).filter({ visible: true }).first();
		if (name === 'phone' && !await field.count()) continue;
		await field.fill(content);
	}
	const state = page.locator(mode === 'classic' ? '#billing_state' : '#shipping-state, #billing-state').first();
	if (mode === 'classic') {
		await state.evaluate((element, value) => {
			const $ = window.jQuery;
			$(element).val(value).trigger('change');
		}, value.state);
	} else {
		await state.selectOption(value.state);
	}
	if (guest) {
		await page.locator(mode === 'classic' ? '#billing_email' : '#email').fill('portfolio@example.test');
	}
}

async function begin(browser, mode, data, customer = null) {
	const page = await browser.newPage();
	page.setDefaultTimeout(12_000);
	try {
		if (customer) {
			await logIn(page, data.login, customer);
		}
		await page.goto(data.add);
		await page.goto(data.checkout);
		await address(page, mode, north, !customer);
		return page;
	} catch (error) {
		await page.close();
		throw error;
	}
}

async function logIn(page, url, account) {
	await page.goto(url);
	await page.locator('#user_login').fill(account.login);
	await page.locator('#user_pass').fill(account.password);
	await page.locator('#wp-submit').click({ noWaitAfter: true });
	await page.waitForURL(destination => !destination.pathname.endsWith('/wp-login.php'), { waitUntil: 'domcontentloaded', timeout: 25_000 });
}

const placeOrder = page => page.getByRole('button', { name: /place order/i }).last();

async function orderFromCheckout(page) {
	await page.waitForURL(url => /order-received/.test(url.href), { timeout: 25_000 });
	const url = new URL(page.url());
	const id = url.searchParams.get('order-received') || url.pathname.match(/order-received\/(\d+)/)?.[1];
	assert.ok(id, 'The order confirmation contains a persisted order ID.');
	return fixture('order', id);
}

async function purchaseOrder(browser, mode, data) {
	for (const customer of [null, data.customers.unapproved]) {
		const page = await begin(browser, mode, data, customer);
		try {
			await page.getByText('Direct bank transfer', { exact: true }).first().waitFor();
			assert.equal(await page.getByRole('radio', { name: /purchase order/i }).count(), 0);
		} finally {
			await page.close();
		}
	}
	const page = await begin(browser, mode, data, data.customers.approved);
	try {
		await page.getByText('Purchase Order', { exact: true }).first().click();
		await page.getByLabel('Purchase order reference', { exact: false }).first().fill('FIXTURE-1042');
		await placeOrder(page).click();
		const order = await orderFromCheckout(page);
		assert.equal(order.reference, 'FIXTURE-1042');
		assert.equal(order.payment, 'codexawp_purchase_order');
		assert.equal(order.status, 'on-hold');
	} finally {
		await page.close();
	}
}

async function shipping(browser, mode, data) {
	const page = await begin(browser, mode, data);
	try {
		if (plugin === 'woo-pickup-point-checkout') {
			await page.getByText('Pickup Point', { exact: true }).first().click();
			const market = page.getByRole('radio', { name: /Market Counter/ });
			await market.check();
			await address(page, mode, south, true);
			await page.getByText('Pickup Point', { exact: true }).first().click();
			const harbor = page.getByRole('radio', { name: /Harbor Locker/ });
			await harbor.waitFor();
			assert.equal(await harbor.isChecked(), false, 'Region change cleared the previous selection.');
			await placeOrder(page).click();
			await page.getByText(/choose an available pickup point/i).first().waitFor();
			await harbor.check();
		} else {
			await page.getByText(/Carton delivery \(2 cartons\)/i).first().waitFor();
		}
		await placeOrder(page).click();
		const order = await orderFromCheckout(page);
		if (plugin === 'woo-pickup-point-checkout') {
			assert.equal(order.pickup.id, 'STH-01');
		} else {
			assert.equal(order.shipping, 10);
		}
	} catch (error) {
		console.error((await page.locator('body').innerText()).slice(-2800));
		throw error;
	} finally {
		await page.close();
	}
	if (plugin === 'woo-pickup-point-checkout') {
		const changed = await begin(browser, mode, data);
		try {
			await changed.getByText('Pickup Point', { exact: true }).first().click();
			await changed.getByRole('radio', { name: /Market Counter/ }).check();
			await changed.getByText(/standard shipping/i).first().click();
			await changed.getByRole('radio', { name: /Market Counter/ }).waitFor({ state: 'hidden' });
			await placeOrder(changed).click();
			const order = await orderFromCheckout(changed);
			assert.equal(order.pickup, '', 'Changing shipping method leaves the saved order without a pickup point.');
			assert.equal(order.shipping, 14);
		} finally {
			await changed.close();
		}
	}
}

async function comparison(browser) {
	const data = fixture('comparison-editor');
	const page = await browser.newPage();
	try {
		await logIn(page, data.login, data.customer);
		const close = page.getByRole('button', { name: /close (guide|welcome)/i }).first();
		try { await close.click({ timeout: 2500 }); } catch { /* The welcome guide is optional. */ }
		const desktop = page.getByRole('combobox', { name: 'Product 2', exact: true });
		const canvas = page.frameLocator('iframe[name="editor-canvas"]');
		const inCanvas = canvas.getByRole('combobox', { name: 'Product 2', exact: true });
		const [scope, field] = await Promise.any([
			desktop.waitFor({ timeout: 20_000 }).then(() => [page, desktop]),
			inCanvas.waitFor({ timeout: 20_000 }).then(() => [canvas, inCanvas]),
		]);
		await field.fill(data.search);
		await scope.getByRole('option', { name: data.search, exact: true }).waitFor();
		await field.press('ArrowDown');
		await field.press('Enter');
		await page.waitForFunction(ids => {
			const blocks = window.wp.data.select('core/block-editor').getBlocks();
			const productIds = blocks.find(block => block.name === 'codexawp/product-comparison')?.attributes.productIds;
			return productIds?.[0] === ids[0] && productIds?.[1] === ids[1];
		}, data.products);
		console.log('PASS comparison editor preserves an existing ID and supports product search and keyboard selection');
	} finally {
		await page.close();
	}
}

const browser = await chromium.launch({ executablePath: process.env.PORTFOLIO_CHROMIUM });
try {
	if (plugin === 'woo-product-comparison-block') await comparison(browser);
	for (const mode of plugin === 'woo-product-comparison-block' ? [] : modes) {
		const data = fixture(plugin, mode);
		if (plugin === 'woo-purchase-order-gateway') {
			await purchaseOrder(browser, mode, data);
		} else {
			await shipping(browser, mode, data);
		}
		console.log('PASS ' + plugin + ' ' + mode + ' checkout and persisted order');
	}
} finally {
	await browser.close();
}
