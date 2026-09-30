// Збирає assets/src/*.js у бандли (порядок файлів важливий), мініфікує
// і перевіряє бюджет: кожен бандл < 8 KB gzip.
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { minify } = require('terser');

const root = path.join(__dirname, '..');
const version = require('../package.json').version;
const LIMIT = 8 * 1024;
const bundles = {
	'bp-attribution': ['classify.js', 'tracker.js'],
	// залежить від bp-attribution (window.BPAttrClassify, window.bpAttr)
	'bp-phone': ['phone-core.js', 'phone-tracker.js'],
};

(async () => {
	let failed = false;
	for (const [name, files] of Object.entries(bundles)) {
		const src = files.map((f) => fs.readFileSync(path.join(root, 'assets/src', f), 'utf8')).join('\n');
		fs.writeFileSync(path.join(root, `assets/dist/${name}.js`), src);
		const out = await minify(src, { compress: { passes: 2 }, mangle: true, format: { comments: false } });
		const min = `/*! ${name} ${version} */\n${out.code}\n`;
		fs.writeFileSync(path.join(root, `assets/dist/${name}.min.js`), min);
		const gz = zlib.gzipSync(min, { level: 9 }).length;
		console.log(`${name}.min.js: ${min.length} B, gzip ${gz} B (ліміт ${LIMIT} B)`);
		if (gz >= LIMIT) failed = true;
	}
	if (failed) {
		console.error('Перевищено бюджет 8 KB gzip');
		process.exit(1);
	}
})();
