// Actual visible controls on the guarded disposable SQL browser fixture.
module.exports = async function(ctx) {
  const {login,navigate,click,fill,waitFor,api,evaluate,record,openDay,submitForm,results,sleep}=ctx;
  const done=name=>results.some(r=>r.scenario===name);
  if(process.argv.includes('--credit-authority-only')) {
    if(done('Accounting-only credit authority browser enforcement'))return;
    await login('admin');await navigate('admin/user-view');
    const before=await api('admin/user-view/501');if(before.code!==200)throw Error('Synthetic financial profile unavailable');
    await fill('#uv-search','SIM-501');await click('#uv-search-btn');await waitFor("document.querySelector('[data-edit-user=\"501\"]')!==null");await click('[data-edit-user="501"]');await waitFor("document.querySelector('#uv-edit-modal').getClientRects().length>0 && document.querySelector('#uv-e-name').value.length>0");
    const locked=await evaluate("document.querySelector('#uv-e-financial-fields').classList.contains('hidden') && [...document.querySelectorAll('#uv-e-financial-fields input,#uv-e-financial-fields select')].every(x=>x.disabled)");
    if(!locked)throw Error('Admin financial controls remain editable');
    const row=before.body.data;
    const denied=await api('admin/user-view/update','POST',{user_id:501,name:row.name,email:row.email,user_type:row.user_type,roles:row.roles,credit_percentage:40});
    if(denied.code!==403)throw Error('Admin forged credit update accepted');
    const financial={user_id:501,employment_type:row.employment_type,salary_schedule_id:row.salary_schedule_id,salary_grade:row.salary_grade,salary_step:row.salary_step,salary_effective_date:row.salary_effective_date,monthly_salary:row.base_salary,credit_percentage:40,reason:'Synthetic client-policy credit review'};
    const wrong=await api('accounting/debts/financial-profile','POST',financial);if(wrong.code!==403)throw Error('Admin Accounting endpoint accepted');
    const unchanged=await api('admin/user-view/501');for(const key of ['credit_limit','credit_percentage','current_debt'])if(String(row[key])!==String(unchanged.body.data[key]))throw Error('Rejected Admin request changed '+key);
    await login('accounting');const approved=await api('accounting/debts/financial-profile','POST',financial);if(approved.code!==200)throw Error('Accounting financial change rejected: '+JSON.stringify(approved));
    await record('Accounting-only credit authority browser enforcement',{adminControlsDisabled:true,forgedAdmin:403,wrongActivePortal:403,authorizedAccounting:200});return;
  }
  if(process.argv.includes('--partial-zero-only')) {
    if(done('partial and zero actual payroll visible controls'))return;
    await login('payroll');await navigate('deductions/workspace');
    const exists=await evaluate("document.body.innerText.includes('PAY-20261102-20261102')");
    if(!exists){
    for(const [email,id] of [['faculty',501],['staff',503]]) {
      await login(email);await navigate('user/card');await waitFor("!document.querySelector('#purchase-card-unlock').disabled");await click('#purchase-card-unlock');await sleep(800);
      await openDay('supplies');await fill('#payment-method','debt');await fill('#debt-customer-search',email);await sleep(800);await click(`[data-customer-id="${id}"]`);await click('[data-direct-product="101"]');
      await waitFor("!document.querySelector('#submit-transaction').disabled");await click('#submit-transaction');await click('#confirm-proceed');await waitFor("document.querySelector('#receipt-modal').style.display==='grid'");await click('#receipt-new');
    }
    await login('payroll');await navigate('deductions/workspace');
    const period='[data-deduction-workflow-form=period]';await fill(period+' [name="frequency"]','custom');await fill(period+' [name="date_start"]','2026-11-02');await fill(period+' [name="date_end"]','2026-11-02');await submitForm(period);
    for(const id of [501,503]){await waitFor(`document.querySelector('[name="requests[${id}][selected]"]')!==null`);await click(`[name="requests[${id}][selected]"]`);}
    await submitForm('[data-deduction-workflow-form=prepare]');await waitFor("document.querySelector('[data-deduction-workflow-form=submit]')!==null");await submitForm('[data-deduction-workflow-form=submit]');await waitFor("document.querySelectorAll('[data-deduction-workflow-form=result]').length===2");
    }
    const forms=await evaluate("[...document.querySelectorAll('[data-deduction-workflow-form=result]')].map(f=>({action:f.getAttribute('action'),name:f.closest('article').querySelector('h4').textContent}))");
    const firstItem=Number(forms[0].action.match(/items\/(\d+)/)[1]);
    await login('hr');const denied=await api(`deductions/workspace/items/${firstItem}/confirm`,'POST',{confirmed_amount:100,result_reference:'FORBIDDEN-QA'});if(denied.code!==403)throw Error('HR can confirm payroll');
    await login('payroll');await navigate('deductions/workspace');
    for(const row of forms) {
      const faculty=row.name.includes('Faculty'),selector=`form[action="${row.action}"]`;
      await waitFor(`Boolean(document.querySelector(${JSON.stringify(selector)})?.querySelector('.deduction-feedback'))`);
      await fill(selector+' [name="confirmed_amount"]',faculty?'20':'0');await fill(selector+' [name="result_reference"]',faculty?'SQL-UI-PARTIAL':'SQL-UI-ZERO');
      await submitForm(selector);
      if(!await evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('[name=reason_code]').validity.valueMissing`))throw Error('Browser accepted a blank partial/zero reason');
      const rejection=await evaluate(`(async()=>{const f=document.querySelector(${JSON.stringify(selector)});const r=await fetch(f.action,{method:'POST',body:new FormData(f)});return {code:r.status,text:await r.text()}})()`);
      if(!rejection.text.includes('reason code'))throw Error('Server accepted a forged blank payroll reason');
      await navigate((await evaluate('location.pathname+location.search')).replace(/^\//,''));
      if(!await evaluate(`Boolean(document.querySelector(${JSON.stringify(selector)}))`))throw Error('Invalid payroll result posted');
      await waitFor(`Boolean(document.querySelector(${JSON.stringify(selector)})?.querySelector('.deduction-feedback'))`);
      await fill(selector+' [name="confirmed_amount"]',faculty?'20':'0');await fill(selector+' [name="result_reference"]',faculty?'SQL-UI-PARTIAL':'SQL-UI-ZERO');
      await fill(selector+' [name="reason_code"]',faculty?'insufficient_salary':'not_deducted');await submitForm(selector);
      await waitFor(`!document.querySelector(${JSON.stringify(selector)})`);
    }
    await waitFor("document.querySelector('[data-deduction-workflow-form=reconcile]')!==null");await submitForm('[data-deduction-workflow-form=reconcile]');await waitFor("document.querySelector('[data-deduction-workflow-form=finalize]')!==null");await submitForm('[data-deduction-workflow-form=finalize]');
    await record('partial and zero actual payroll visible controls',{requested:[100,100],actual:[20,0],blankReasonsRejected:2,unauthorizedHr:403});
    return;
  }
  if(!done('store BE cashier accounting report handoff visible controls')) {
    await openDay('supplies');await click('#store-day-close-btn');await waitFor("document.querySelector('#store-day-close-modal').style.display==='grid'");await fill('#closing-note-input','Synthetic independently counted browser day');await click('#store-day-close-save');
    await waitFor("document.querySelector('#store-day-close-modal').classList.contains('is-hidden')");
    await login('supervisor');await navigate('store-admin/daily-reports?store_id=1');await waitFor("document.querySelector('[data-report-action=submit]')!==null");await fill('[data-report-action=submit] [name="note"]','Synthetic browser source reconciliation');await submitForm('[data-report-action=submit]');
    await login('be');await navigate('be/reports');await waitFor("document.querySelector('[data-report-action=review] [value=approve]')!==null");
    const action=await evaluate("document.querySelector('[data-report-action=review]').getAttribute('action')");const id=Number(action.match(/reports\/(\d+)/)[1]);
    await fill('[data-report-action=review] [name="note"]','Synthetic independently reviewed frozen source');await click('[data-report-action=review] [value=approve]');await sleep(1200);
    await login('cashier');await navigate('cashier/reports');const receive=`form[action$="/reports/${id}/receive"]`;await waitFor(`Boolean(document.querySelector(${JSON.stringify(receive)}))`);await submitForm(receive);
    await login('accounting');await navigate('accounting/reports');if(!await evaluate("document.body.innerText.includes('2026-11-01') && document.body.innerText.includes('Accounting copy furnished')"))throw Error('Accounting report handoff missing');
    await login('hr');const denied=await api(`be/reports/${id}/review`,'POST',{decision:'approve'});if(denied.code!==403)throw Error('HR can review reports');
    await record('store BE cashier accounting report handoff visible controls',{reportId:id,unauthorizedHr:403});
  }
  if(!done('Accounting Head department settlement visible controls')) {
    await login('accounting');await navigate('accounting/department-debts');if(!await evaluate("document.querySelector('#department-accounting-page').dataset.canOperate==='true'"))throw Error('Accounting Head financial controls unavailable');
    await fill('#department-period-month','2026-10');await click('#department-accounting-refresh');await waitFor("document.querySelector('[data-settlement]')!==null");
    await click('[data-settlement]');await fill('#department-settlement-amount','25');await fill('#department-settlement-reference','SQL-BROWSER-DEPT-25');await fill('#department-settlement-remarks','Synthetic payment reconciled separately from employee payroll');await click('#department-finance-save');
    await waitFor("document.querySelector('#department-finance-modal').classList.contains('is-hidden')");
    const result=await api('accounting/department-debts/data?month=2026-10');if(result.body.departments.some(d=>Number(d.outstanding_amount)!==0))throw Error('Department settlement did not reconcile');
    await login('admin');await navigate('accounting/department-debts');if(await evaluate("Boolean(document.querySelector('#department-finance-form'))"))throw Error('Admin has accounting settlement controls');
    const denied=await api('accounting/department-debts/settlement','POST',{amount:25});if(denied.code!==403)throw Error('Admin can settle department debt');
    await record('Accounting Head department settlement visible controls',{amount:25,adminReadOnly:true,unauthorizedAdmin:403});
  }
  if(!done('independent investigation recommendation and Head approval visible controls')) {
    await login('legacy-accounting');await navigate('accounting/debts');await click('#open-debt-investigations');await waitFor("!document.querySelector('#investigation-user').disabled");await fill('#investigation-user','501');await waitFor("!document.querySelector('#investigation-transaction').disabled");
    const queue=await api('accounting/debt-investigations');
    const existing=(queue.body.investigations||[]).find(r=>r.summary==='Synthetic browser independent no change review');
    if(!existing){await fill('#investigation-transaction','0');await fill('#investigation-issue','other');await fill('#investigation-summary','Synthetic browser independent no change review');await fill('#investigation-evidence','Synthetic ledger reconciled to zero');await click('#investigation-open');await waitFor("document.querySelector('#investigation-message').textContent.includes('opened')");}
    await waitFor("[...document.querySelectorAll('[data-investigation-id]')].some(e=>e.textContent.includes('Synthetic browser independent no change review'))");
    const id=await evaluate("Number([...document.querySelectorAll('[data-investigation-id]')].find(e=>e.textContent.includes('Synthetic browser independent no change review')).dataset.investigationId)");const row=`[data-investigation-id="${id}"]`;
    if(!existing || ['open','investigating'].includes(existing.status)){await fill(row+' .investigation-findings','Synthetic independently reviewed ledger agrees');await fill(row+' .investigation-result-evidence','Synthetic source and statement');await fill(row+' .investigation-action','no_change');await click(row+' .investigation-recommend');await waitFor(`document.querySelector('${row}')?.dataset.investigationStatus==='recommended'`);}
    const same=await api(`accounting/debt-investigations/${id}/approve`,'POST',{});if(same.code!==403)throw Error('Same investigator approved their recommendation');
    await login('accounting');await navigate('accounting/debts');await click('#open-debt-investigations');await waitFor(`document.querySelector('${row} .investigation-approve')!==null`);await click(row+' .investigation-approve');await waitFor("document.querySelector('[data-dialog-confirm]')?.offsetParent!==null");await click('[data-dialog-confirm]');await waitFor(`document.querySelector('${row}')?.dataset.investigationStatus==='closed'`);
    await record('independent investigation recommendation and Head approval visible controls',{caseId:id,noFinancialChange:true,sameReviewerRejected:403});
  }
};
