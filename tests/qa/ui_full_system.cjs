// Disposable headless Chrome acceptance runner. Never connects to a hosted app.
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const base = 'http://127.0.0.1:9081';
const evidence = path.resolve(root, process.env.IBEMS_QA_BROWSER_EVIDENCE || 'build/logs/browser');
const { pageFailures } = require('./browser_page_assertions.cjs');
const { readPriorEvidence } = require('./browser_resume_evidence.cjs');
// Synchronous preflight runs outside the catch that records failures, so rejected
// saved evidence cannot be replaced by empty results or discarded exceptions.
const priorDocument = process.argv.includes('--resume') || process.argv.includes('--extended-only')
  ? readPriorEvidence(path.join(evidence, 'results.json'), {
    required: process.argv.includes('--extended-only'), allowIncomplete: process.argv.includes('--resume')
  }) : null;
fs.mkdirSync(evidence, { recursive: true });
const profile = fs.mkdtempSync(path.join(root, 'writable/chrome-qa-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', '--remote-debugging-port=9082', `--user-data-dir=${profile}`,
  '--disable-background-networking', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  '--window-size=1440,1000', 'about:blank'
], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
const chromeLog = fs.createWriteStream(path.join(evidence, 'chrome.log'));
chrome.stderr.pipe(chromeLog);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws, serial = 0;
const pending = new Map();
const errors = [];
const results = [];
const sessions = new Map();
const inflight = new Set();
let lastNetworkEvent = Date.now();
async function cdp(method, params = {}) {
  const id = ++serial;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout ${method}`)); }, 15000);
    pending.set(id, { resolve, reject, timer });
    ws.send(JSON.stringify({ id, method, params }));
  });
}
async function evaluate(expression) {
  const r = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ' ' + (r.exceptionDetails.exception?.description || ''));
  return r.result.value;
}
async function navigate(url) {
  const navigation = await cdp('Page.navigate', { url: base + '/' + url });
  if (navigation.errorText) throw new Error('Navigation failed: ' + navigation.errorText);
  await sleep(850);
  for (let i = 0; i < 40; i++) {
    if (await evaluate("document.readyState === 'complete' && document.body !== null")) break;
    await sleep(100);
  }
  await sleep(400);
  for (let i=0;i<25;i++) {
    if(inflight.size===0 && Date.now()-lastNetworkEvent>700)break;
    await sleep(150);
  }
}
async function click(selector) {
  await waitFor(`(()=>{const el=document.querySelector(${JSON.stringify(selector)});return !!el && !el.disabled && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'})()`);
  const r = await evaluate(`(()=>{const el=document.querySelector(${JSON.stringify(selector)}); if(!el)return false;el.click();return true})()`);
  if (!r) throw new Error(`Missing control ${selector}`);
  await sleep(350);
}
async function fill(selector, value) {
  await evaluate(`(()=>{const el=document.querySelector(${JSON.stringify(selector)}); if(!el)throw new Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`);
}
async function screenshot(name) {
  const r = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(path.join(evidence, name + '.png'), Buffer.from(r.data, 'base64'));
}
async function login(email) {
  await cdp('Network.clearBrowserCookies');
  if(sessions.has(email)) {
    const session=sessions.get(email);
    await cdp('Network.setCookies',{cookies:session.cookies});
    await navigate(session.url.replace(/^\//,''));
    return evaluate("({url:location.pathname,text:document.body.innerText})");
  }
  await navigate('login');
  await fill('#email', email + '@simulation.ibems.local');
  await fill('#password', 'Simulation-Only-2026!');
  await click('#submit-btn');
  await sleep(1600);
  for (let i=0;i<80;i++) {
    if (await evaluate("document.body !== null && document.readyState === 'complete' && !/login$/.test(location.pathname)")) break;
    await sleep(150);
  }
  const state = await evaluate("({url:location.pathname,text:document.body.innerText})");
  if (/login$/.test(state.url)) throw new Error(`Login failed for ${email}: ${state.text.slice(-500)}`);
  sessions.set(email,{cookies:(await cdp('Network.getAllCookies')).cookies,url:state.url});
  return state;
}
async function waitFor(expression) {
  for(let i=0;i<100;i++){if(await evaluate(expression))return;await sleep(150);}
  throw new Error('UI wait failed: '+expression);
}
async function api(url, method='GET', data) {
  return evaluate(`(async()=>{const r=await fetch(${JSON.stringify(base+'/'+url)},{method:${JSON.stringify(method)},headers:{'Content-Type':'application/json','Accept':'application/json'},${data===undefined?'':`body:JSON.stringify(${JSON.stringify(data)}),`}credentials:'same-origin'});return {code:r.status,body:await r.json()}})()`);
}
async function openDay(email) {
  await login(email);await navigate('store/pos');
  await waitFor("document.querySelector('#opening-balance-status')?.textContent.trim()!=='Checking'");
  const day=await api('store/day-session/status');
  if(day.body.session?.status!=='open') {
    await click('#opening-balance-open-btn'); await fill('#opening-balance-input','0'); await click('#opening-balance-save');
    await waitFor("document.querySelector('#opening-balance-modal').classList.contains('is-hidden') || document.querySelector('#opening-balance-result')?.textContent");
    await sleep(900);
  }
}
async function cashSale(email, product, amount, customer) {
  if(results.some(r=>r.business===email&&r.scenario==='cash POS via visible controls'))return;
  await openDay(email);
  await waitFor(`document.querySelector('[data-direct-product="${product}"]')!==null`);
  await click(`[data-direct-product="${product}"]`);
  if(customer){await fill('#debt-customer-search',customer);await sleep(600);await waitFor("document.querySelector('[data-customer-id]')!==null");await click('[data-customer-id]');}
  await click('#submit-transaction'); await waitFor("document.querySelector('#confirm-cash-received')!==null");
  await fill('#confirm-cash-received',String(amount));await click('#confirm-proceed');
  await waitFor("document.querySelector('#receipt-modal').style.display === 'grid'");
  const receipt=await evaluate("document.querySelector('#receipt-content')?.innerText");
  if(!receipt || receipt.trim().length<50)throw new Error('Cash receipt missing: '+receipt);
  results.push({role:'STORE_SYSTEM',business:email,scenario:'cash POS via visible controls',status:'tested',receipt});
  await screenshot(email+'-cash-receipt');await click('#receipt-new');
  save();
}
function save(){fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify({results,errors},null,2));}
async function record(scenario, extra={}) {results.push({scenario,status:'tested',...extra});await screenshot(scenario.replaceAll(/[^a-z0-9]/gi,'-'));save();console.log(scenario+' PASS');}
async function submitForm(selector) {await click(selector+' button[type="submit"]');await sleep(1100);}
async function rentalAction(name,id) {
  await click(`[data-action="${name}"][data-id="${id}"]`);
  await waitFor("document.querySelector('[data-dialog-confirm]')?.offsetParent !== null");await click('[data-dialog-confirm]');await sleep(1100);
  if(await evaluate("document.querySelector('#offering-result').classList.contains('is-error')"))throw new Error(await evaluate("document.querySelector('#offering-result').textContent"));
}
async function creditAndPayroll() {
  if(results.some(r=>r.scenario==='credit and actual payroll UI'))return;
  await login('faculty');await navigate('user/card');await waitFor("!document.querySelector('#purchase-card-unlock').disabled");await click('#purchase-card-unlock');await sleep(1000);
  await openDay('supplies');await fill('#payment-method','debt');await fill('#debt-customer-search','Faculty');await sleep(700);await click('[data-customer-id="501"]');
  await click('[data-direct-product="101"]');await waitFor("!document.querySelector('#submit-transaction').disabled");await click('#submit-transaction');await click('#confirm-proceed');
  await waitFor("document.querySelector('#receipt-modal').style.display === 'grid'");await screenshot('employee-debt-receipt');await click('#receipt-new');
  await login('faculty');await navigate('user/card');await waitFor("!document.querySelector('#purchase-card-unlock').disabled");await click('#purchase-card-unlock');await sleep(800);
  await openDay('supplies');await fill('#payment-method','debt');await fill('#debt-customer-search','Faculty');await sleep(700);await click('[data-customer-id="501"]');await click('[data-direct-product="101"]');
  if(!await evaluate("document.querySelector('#submit-transaction').disabled && document.querySelector('#submit-transaction').textContent.includes('Exceeds Credit')"))throw new Error('Credit-limit UI did not block overlimit');
  await screenshot('credit-overlimit-blocked');
  await login('collecting');await navigate('deductions/workspace');
  const period='[data-deduction-workflow-form="period"]';await fill(period+' [name="frequency"]','custom');await fill(period+' [name="date_start"]','2026-11-01');await fill(period+' [name="date_end"]','2026-11-01');await submitForm(period);
  await waitFor("document.querySelector('[name=\"requests[501][selected]\"]')!==null");await click('[name="requests[501][selected]"]');await submitForm('[data-deduction-workflow-form="prepare"]');
  await waitFor("document.querySelector('[data-deduction-workflow-form=\"submit\"]')!==null");await submitForm('[data-deduction-workflow-form="submit"]');
  await waitFor("document.querySelector('[data-deduction-workflow-form=\"result\"]')!==null");await fill('[data-deduction-workflow-form="result"] [name="result_reference"]','UI-QA-20261101');await submitForm('[data-deduction-workflow-form="result"]');
  await waitFor("document.querySelector('[data-deduction-workflow-form=\"reconcile\"]')!==null");await submitForm('[data-deduction-workflow-form="reconcile"]');await waitFor("document.querySelector('[data-deduction-workflow-form=\"finalize\"]')!==null");await submitForm('[data-deduction-workflow-form="finalize"]');
  await record('credit and actual payroll UI',{amount:100,overlimitBlocked:true});
}
async function specialtyUi() {
  if(!results.some(r=>r.scenario==='daily food create publish sell UI')){
    await openDay('cafeteria');await navigate('store/offerings');await waitFor("!document.querySelector('#offering-store').disabled");
    let data=await api('store/offerings/data?store_id=4');
    if(!data.body.food.some(p=>p.name==='Synthetic browser meal')){
      await fill('#food-template-form [name="name"]','Synthetic browser meal');await fill('#food-template-form [name="variant-price"]','35');await fill('#food-template-form [name="today_capacity"]','2');await submitForm('#food-template-form');
      await waitFor("document.querySelector('#offering-result').textContent.includes('Dish saved')");data=await api('store/offerings/data?store_id=4');
    }
    const product=data.body.food.find(p=>p.name==='Synthetic browser meal');if(!product)throw new Error('Published meal missing');
    await cashSale('cafeteria',product.id,35);await record('daily food create publish sell UI');
  }
  for(const email of ['business-center','toga']){
    if(results.some(r=>r.scenario==='rental booking fee issue return refund UI'&&r.business===email))continue;
    const store=email==='business-center'?5:6;await openDay(email);await navigate('store/offerings');await waitFor("!document.querySelector('#offering-store').disabled");
    const data=await api('store/offerings/data?store_id='+store);const pack=data.body.packages[0];
    let booking=data.body.bookings.find(b=>b.borrower==='Synthetic browser borrower'&&b.status==='reserved'&&Number(b.transaction_id)===0);
    if(!booking){
      await fill('#rental-package-select',String(pack.id));await fill('#rental-book-form [name="borrower"]','Synthetic browser borrower');await fill('#rental-book-form [name="contact"]','UI-QA');await submitForm('#rental-book-form');
      await waitFor("document.querySelector('#offering-result').textContent.includes('Reservation recorded')");
      const next=await api('store/offerings/data?store_id='+store);booking=next.body.bookings.find(b=>b.borrower==='Synthetic browser borrower'&&b.status==='reserved'&&Number(b.transaction_id)===0);
    }
    if(!booking)throw new Error('Reserved synthetic rental missing from readback');
    const id=booking.id;
    await fill(`[data-pay="${id}"] [name="cash_received"]`,String(booking.fee_cents/100));await submitForm(`[data-pay="${id}"]`);await waitFor(`document.querySelector('[data-action="issue"][data-id="${id}"]')!==null`);await rentalAction('issue',id);
    await waitFor(`document.querySelector('[data-return="${id}"]')!==null`);
    await evaluate(`document.querySelectorAll('[data-return="${id}"] [data-asset]').forEach(e=>{e.value=e.max;e.dispatchEvent(new Event('input',{bubbles:true}))})`);await submitForm(`[data-return="${id}"]`);
    await waitFor(`document.querySelector('[data-action="refund"][data-id="${id}"]')!==null`);await rentalAction('refund',id);
    const final=await api('store/offerings/data?store_id='+store);if(final.body.bookings.some(b=>Number(b.id)===Number(id)))throw new Error('Returned and refunded rental remains outstanding');
    await record('rental booking fee issue return refund UI',{business:email,booking:id});
  }
}
async function onboardingAndBoundaries() {
  if(!results.some(r=>r.scenario==='HR visible onboarding')){
    await login('hr');await navigate('hr/staff');
    await waitFor("document.querySelector('#hr-salary-grade').options.length>0");
    for(const [name,value] of Object.entries({employee_id:'BROWSER-20261101',name:'Synthetic browser employee',email:'browser-new@simulation.ibems.local',password:'Simulation-Only-2026!',user_type:'faculty',employment_type:'plantilla'}))await fill(`#hr-add-form [name="${name}"]`,value);
    await evaluate("for(const id of ['hr-salary-schedule','hr-salary-grade','hr-salary-step']){const e=document.getElementById(id);e.value=[...e.options].find(o=>o.value)?.value;e.dispatchEvent(new Event('change',{bubbles:true}));}");
    await click('#hr-add-submit');await waitFor("document.querySelector('#hr-add-result').textContent.includes('account created')");await record('HR visible onboarding');
  }
  if(!results.some(r=>r.scenario==='HTTP ownership and role denials')){
    await login('supplies');await navigate('store/pos');const forbidden=await api('hr/staff/create','POST',{name:'Forbidden synthetic employee'});const otherStore=await api('store/products?store_id=2');
    if(forbidden.code!==403||otherStore.code!==403)throw new Error('Cross-role or cross-store HTTP denial failed');
    await login('faculty');const foreign=await api('user/transactions/8');if(![403,404].includes(foreign.code)||foreign.body.transaction)throw new Error('Foreign employee transaction data exposed');
    await record('HTTP ownership and role denials',{crossRole:forbidden.code,crossStore:otherStore.code,foreignReceipt:foreign.code});
  }
  await login('faculty');await cdp('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await navigate('user/dashboard');await screenshot('user-mobile-390');
  const size=await evaluate("({width:document.documentElement.scrollWidth,viewport:innerWidth})");if(size.width>size.viewport)throw new Error('Employee mobile horizontal overflow');
  await cdp('Emulation.clearDeviceMetricsOverride');await record('employee mobile viewport',{width:390});
}
const pageControls = {
  'admin/dashboard': ['#ad-health-message'],
  'admin/store-ops': ['#store-search'],
  'admin/stores': ['#store-search'],
  'admin/stores/1': ['#sd-refresh'],
  'admin/products': ['#ap-search'],
  'admin/user-view': ['#uv-search'],
  'admin/audit': ['#audit-action-filter'],
  'admin/accounting-debts': ['#ad-top-body'],
  'admin/departments': ['#department-admin-page'],
  'accounting/dashboard': ['#acd-trend-chart'],
  'store-admin/dashboard': ['#sad-store-list'],
  'store-admin/stores': ['#store-search'],
  'store-admin/stores/1': ['#sd-refresh'],
  'user/dashboard': ['#u-debt-status-card'],
  'user/card': ['#purchase-card-visual'],
  'user/history': ['#uh-debt-status-card'],
  'user/deductions': ['#ud-period'],
  'user/stores': ['#us-search'],
  'store/dashboard': ['.store-readiness-panel'],
  'store/inventory': ['#inventory-search','#open-product-modal-top'],
  'store/history': ['#history-date-from','#history-payment-filter'],
  'store/reports': ['#reports-date-from'],
  'store/settings': ['#settings-store-select'],
  'store/staff-records': ['#debt-search'],
  'store/offerings': ['#offering-store'],
  'store/pos': ['#submit-transaction'],
  'hr/staff': ['#hr-add-form'],
  'department/dashboard': ['#department-portal-dashboard'],
  'department/authorizations': ['#department-pin-form'],
  'accounting/department-debts': ['#department-accounting-body'],
  'accounting/debts': ['#acct-search','#open-debt-investigations'],
  'deductions/workspace': ['[data-deduction-workflow-form="period"]'],
  'store-admin/daily-reports': ['.daily-report-page'],
  'be/reports': ['.daily-report-page'],
  'cashier/reports': ['.daily-report-page'],
  'accounting/reports': ['.daily-report-page'],
};
async function checkPage(role, url) {
  const start = errors.length;
  await navigate(url);
  const route = url.split('?')[0];
  // This historical Admin URL intentionally redirects to the current store list.
  const expectedPath = '/' + (route === 'admin/store-ops' ? 'admin/stores' : route);
  const requiredControls = pageControls[route] || ['main'];
  const state = await evaluate(`({url:location.pathname,title:document.title,text:document.body.innerText,width:document.documentElement.scrollWidth,viewport:innerWidth,controls:Object.fromEntries(${JSON.stringify(requiredControls)}.map(s=>[s,Boolean(document.querySelector(s))]))})`);
  const exceptions = errors.slice(start);
  const failures = pageFailures(state, { expectedPath, requiredControls, exceptions });
  results.push({role,url,status:failures.length?'failed':'tested',actual:state.url,expectedPath,requiredControls,title:state.title,overflow:state.width>state.viewport,errors:exceptions,failures});
  fs.writeFileSync(path.join(evidence, `${role}-${url.replaceAll('/','_').replaceAll('?','_')}.json`), JSON.stringify(state, null, 2));
  save();
  if (failures.length) throw new Error(role+' '+url+': '+failures.join('; '));
  console.log(role+' '+url+' tested');
  return state;
}
async function main() {
  let targets;
  for (let i=0;i<60;i++) { try { targets=await (await fetch('http://127.0.0.1:9082/json/list')).json(); if(targets.length)break; } catch{} await sleep(200); }
  if (!targets?.length) throw new Error('Disposable Chrome did not expose CDP');
  ws = new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
  ws.addEventListener('message', e=>{
    const msg=JSON.parse(e.data);
    if(msg.id && pending.has(msg.id)){const p=pending.get(msg.id);pending.delete(msg.id);clearTimeout(p.timer);msg.error?p.reject(new Error(msg.error.message)):p.resolve(msg.result);}
    if(msg.method==='Runtime.exceptionThrown')errors.push(msg.params.exceptionDetails.exception?.description||msg.params.exceptionDetails.text);
    if(msg.method==='Network.requestWillBeSent'){inflight.add(msg.params.requestId);lastNetworkEvent=Date.now();}
    if(msg.method==='Network.loadingFinished'||msg.method==='Network.loadingFailed'){inflight.delete(msg.params.requestId);lastNetworkEvent=Date.now();}
  });
  await cdp('Page.enable'); await cdp('Runtime.enable'); await cdp('Network.enable');
  if(process.argv.includes('--extended-only')) {
    results.push(...priorDocument.results);
    await require('./ui_extended_workflows.cjs')({login,navigate,click,fill,waitFor,api,evaluate,record,screenshot,openDay,submitForm,results,sleep});
    if(errors.length || results.some(r=>r.status==='failed'))throw new Error('Extended acceptance recorded failures');
    fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify({completed:true,extendedCompleted:true,results,errors},null,2));
    return;
  }
  const portals=[
    ['admin','ADMIN',['admin/dashboard','admin/store-ops','admin/accounting-debts','admin/user-view','admin/products','admin/audit','admin/stores','admin/stores/1','admin/departments']],
    ['hr','HR',['hr/staff']],
    ['accounting','ACCOUNTING_HEAD',['accounting/dashboard','accounting/debts','accounting/department-debts','accounting/reports','deductions/workspace','finance/reports']],
    ['legacy-accounting','ACCOUNTING_OFFICE',['accounting/dashboard','accounting/debts','deductions/workspace']],
    ['cashier','CASHIER_HEAD',['cashier/dashboard','cashier/reports','deductions/workspace','finance/reports']],
    ['collecting','COLLECTING_OFFICER',['cashier/dashboard','cashier/reports','deductions/workspace']],
    ['be','BE_DIRECTOR',['be/dashboard','be/reports','finance/reports']],
    ['payroll','PAYROLL_CLERK',['accounting/payroll','deductions/workspace']],
    ['supervisor','STORE_SUPERVISOR',['store-admin/dashboard','store-admin/stores','store-admin/stores/1','store/dashboard','store/inventory?store_id=1','store/history?store_id=1','store/reports?store_id=1','store/settings?store_id=1','store/staff-records?store_id=1','store/offerings?store_id=1','store-admin/daily-reports?store_id=1']],
    ['faculty','USER',['user/dashboard','user/card','user/history','user/deductions','user/stores','department/dashboard','department/authorizations']],
    ['supplies','STORE_SYSTEM',['store/pos','store/offerings']]
  ];
  const previous=priorDocument?.results || [];
  results.push(...previous.filter(x=>x.status==='tested' && (!x.url || (x.expectedPath && x.requiredControls && portals.some(p=>p[1]===x.role&&p[2].includes(x.url))))));
  for(const [email,role,pages] of portals){
    const todo=pages.filter(url=>!results.some(x=>x.role===role&&x.url===url&&x.status==='tested'));
    if(!todo.length)continue;
    await login(email);
    for(const url of todo)await checkPage(role,url);
    await screenshot(role.toLowerCase());
    console.log(role+' pages checked');
    fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify({results,errors},null,2));
  }
  await cashSale('supplies',101,100);
  await cashSale('milk',102,60);
  await cashSale('water',103,25,'Faculty');
  for(const email of ['cafeteria','business-center','toga']){if(fs.existsSync(path.join(evidence,email+'-offerings.png')))continue;await openDay(email);await checkPage('STORE_SYSTEM','store/offerings');await screenshot(email+'-offerings');}
  await creditAndPayroll();await specialtyUi();
  await login('accounting');await navigate('finance/reports?anchor_date=2026-10-15');
  const csv=await evaluate("(async()=>{const r=await fetch('/finance/reports/export?anchor_date=2026-10-15');return {code:r.status,text:await r.text()}})()");if(csv.code!==200||!csv.text.includes('17185.00'))throw new Error('Finance CSV gross does not match October month');fs.writeFileSync(path.join(evidence,'october-finance.csv'),csv.text);await record('October finance CSV export');
  await onboardingAndBoundaries();
  if(errors.length || results.some(r=>r.status==='failed'))throw new Error('Acceptance recorded page or JavaScript failures');
  fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify({completed:true,results,errors},null,2));
}
main().catch(async e=>{fs.writeFileSync(path.join(evidence,'results.json'),JSON.stringify({completed:false,results,errors,failure:e.message},null,2));fs.writeFileSync(path.join(evidence,'failure.txt'),e.stack);if(ws){try{await screenshot('failure');fs.writeFileSync(path.join(evidence,'failure-state.json'),JSON.stringify(await evaluate("({url:location.pathname,text:document.body.innerText})"),null,2));}catch{}}console.error(e.message);process.exitCode=1;}).finally(()=>{ws?.close();chrome.kill();chromeLog.end();});
