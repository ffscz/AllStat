// Run: node tests/otp-submit.cjs (Playwright z NODE_PATH, bez sítě a databáze)
// Formulář s polem [data-otp] musí poslat hodnotu stisknutého tlačítka, i když ho assets/js/otp.js po odeslání vypíná.
// Souhlas s připojením AI (oauth/authorize.php) podle ní rozhoduje mezi Povolit a Zamítnout.
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const otp = fs.readFileSync(path.join(__dirname, '../assets/js/otp.js'), 'utf8');
const html = '<!doctype html><form method="post" action="/echo"><input type="text" name="totp_code" pattern="[0-9]{6}" maxlength="6" required data-otp>'
    + '<button id="allow" type="submit" name="decision" value="allow">A</button>'
    + '<button id="deny" type="submit" name="decision" value="deny" formnovalidate>D</button></form><script src="/otp.js"></script>';

const server = http.createServer((req, res) => {
    if (req.url === '/echo') {
        let body = '';
        req.on('data', (chunk) => { body += chunk; });
        req.on('end', () => { res.setHeader('Content-Type', 'text/plain'); res.end(body); });
    } else if (req.url === '/otp.js') {
        res.setHeader('Content-Type', 'text/javascript');
        res.end(otp);
    } else {
        res.setHeader('Content-Type', 'text/html');
        res.end(html);
    }
});

server.listen(0, '127.0.0.1', async () => {
    const browser = await chromium.launch();
    let failed = false;
    const cases = [
        ['klik na Povolit', (p) => p.click('#allow'), 'decision=allow'],
        ['Enter v poli kódu', (p) => p.press('[name=totp_code]', 'Enter'), 'decision=allow'],
        ['klik na Zamítnout', (p) => p.click('#deny'), 'decision=deny'],
    ];
    for (const [label, act, expected] of cases) {
        const page = await browser.newPage();
        await page.goto('http://127.0.0.1:' + server.address().port + '/');
        await page.fill('[name=totp_code]', '123456');
        await act(page);
        await page.waitForURL('**/echo');
        const body = await page.textContent('body');
        const ok = body.includes(expected);
        console.log((ok ? 'OK   ' : 'CHYBA ') + label + ': ' + body);
        failed = failed || !ok;
        await page.close();
    }
    await browser.close();
    server.close();
    process.exit(failed ? 1 : 0);
});
