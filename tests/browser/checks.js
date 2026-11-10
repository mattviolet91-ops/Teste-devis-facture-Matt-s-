// Test dans un vrai navigateur (Chromium, format téléphone) de ce que les tests PHP ne voient pas :
// ce que le navigateur envoie réellement à myPOS, et la signature d'un document par le client.
// Usage : node tests/browser/checks.js http://127.0.0.1:8123 '<JSON de setup.php>'
const { chromium, devices } = require('playwright');

const base = process.argv[2];
const urls = JSON.parse(process.argv[3]);
const failures = [];
const check = (ok, label) => { console.log((ok ? '  ok   ' : '  ÉCHEC ') + label); if (!ok) { failures.push(label); } };

(async () => {
  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});

  // 1. Formulaire envoyé à myPOS : uniquement les champs signés, la signature en dernier.
  for (const [name, path] of [['montant libre', urls.payment], ['facture', urls.invoice]]) {
    const page = await (await browser.newContext({ ...devices['iPhone 13'] })).newPage();
    let sent = null;
    await page.route('https://www.mypos.com/**', async (route) => {
      sent = [...new URLSearchParams(route.request().postData() || '').keys()];
      await route.fulfill({ status: 200, body: 'ok' });
    });
    await page.goto(base + path);
    await page.waitForTimeout(2500);
    console.log('Paiement myPOS (' + name + ')');
    check(sent !== null, 'le formulaire part vers myPOS');
    check(sent && !sent.includes('_once') && !sent.includes('_token'), 'aucun champ ajouté par l\'application');
    check(sent && sent[sent.length - 1] === 'Signature', 'la signature est le dernier champ');
    check(sent && sent.includes('Amount') && sent.includes('OrderID') && sent.includes('URL_Notify'), 'les champs de paiement sont présents');
    await page.context().close();
  }

  // 2. Un formulaire de l'application garde sa protection contre les doublons.
  {
    const page = await (await browser.newContext({ ...devices['iPhone 13'] })).newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(base + '/connexion');
    console.log('Formulaires de l\'application');
    check(await page.locator('form input[name="_once"]').count() > 0, 'champ anti-doublon présent sur la connexion');
    await page.fill('input[name=email]', 'essai@local.test');
    await page.fill('input[name=password]', 'motdepasse-essai-123');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    check(!page.url().includes('/connexion'), 'connexion réussie');
    check(errors.length === 0, 'aucune erreur de script' + (errors.length ? ' : ' + errors[0] : ''));
    await page.context().close();
  }

  // 3. Le client signe un document depuis son lien (souris, sans écran tactile simulé).
  {
    const page = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    await page.goto(base + urls.document);
    await page.fill('input[name=name]', 'Paul Essai');
    await page.check('input[name=agree]');
    await page.locator('canvas[data-signature-canvas]').scrollIntoViewIfNeeded();
    const box = await page.locator('canvas[data-signature-canvas]').boundingBox();
    await page.mouse.move(box.x + 20, box.y + 60);
    await page.mouse.down();
    for (let i = 0; i < 20; i++) { await page.mouse.move(box.x + 20 + i * 12, box.y + 60 + Math.sin(i) * 25); }
    await page.mouse.up();
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    console.log('Signature d\'un document en ligne');
    check((await page.locator('body').innerText()).includes('Document signé'), 'le document est signé');
    const file = await page.request.get(base + urls.document + '/fichier');
    check(file.status() === 200 && (file.headers()['content-type'] || '').includes('pdf'), 'le PDF signé se télécharge');
    await page.context().close();
  }

  await browser.close();
  if (failures.length) { console.error('\n' + failures.length + ' vérification(s) en échec.'); process.exit(1); }
  console.log('\nTout est bon.');
})().catch((e) => { console.error(e); process.exit(1); });
