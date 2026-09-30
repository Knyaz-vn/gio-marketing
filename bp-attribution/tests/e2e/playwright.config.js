// @ts-check
const path = require('path');
const { defineConfig } = require('@playwright/test');

const BASE = process.env.WP_URL || 'http://127.0.0.1:8899';
const WP_DIR = process.env.WP_DIR || path.join(__dirname, '../../.wp');

module.exports = defineConfig({
	testDir: __dirname,
	timeout: 60000,
	workers: 1,
	use: {
		baseURL: BASE,
		launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
	},
	webServer: {
		command: `php -S ${BASE.replace(/^https?:\/\//, '')} -t "${WP_DIR}" "${WP_DIR}/router.php"`,
		url: BASE + '/wp-login.php',
		reuseExistingServer: true,
		timeout: 30000,
	},
});
