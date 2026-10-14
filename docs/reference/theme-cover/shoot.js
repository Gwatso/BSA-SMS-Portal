const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1200, height: 900 }, deviceScaleFactor: 1 });
  await p.goto('file://' + __dirname + '/cover.html');
  await p.evaluate(() => document.fonts.ready);
  console.log(await p.evaluate(() => [...document.fonts].map(f => f.family + ':' + f.status).join(', ')));
  await p.screenshot({ path: __dirname + '/screenshot-raw.png' });
  await b.close();
})();
