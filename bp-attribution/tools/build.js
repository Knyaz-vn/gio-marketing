// Збирає assets/src/*.js в один файл (порядок важливий: classify -> tracker),
// мініфікує і перевіряє бюджет: < 8 KB gzip.
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { minify } = require('terser');

const root = path.join(__dirname, '..');
const src = ['classify.js', 'tracker.js'].map((f) => fs.readFileSync(path.join(root, 'assets/src', f), 'utf8')).join('\n');
const LIMIT = 8 * 1024;

(async () => {
	fs.writeFileSync(path.join(root, 'assets/dist/bp-attribution.js'), src);
	const out = await minify(src, { compress: { passes: 2 }, mangle: true, format: { comments: false } });
	const min = '/*! bp-attribution ' + require('../package.json').version + ' */\n' + out.code + '\n';
	fs.writeFileSync(path.join(root, 'assets/dist/bp-attribution.min.js'), min);
	const gz = zlib.gzipSync(min, { level: 9 }).length;
	console.log(`bp-attribution.min.js: ${min.length} B, gzip ${gz} B (ліміт ${LIMIT} B)`);
	if (gz >= LIMIT) {
		console.error('Перевищено бюджет 8 KB gzip');
		process.exit(1);
	}
})();
