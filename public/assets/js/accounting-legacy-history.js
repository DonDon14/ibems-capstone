(() => {
    let queueVersion=0,detailVersion=0,queueLoading=false,detailLoading=false,selected=0;
    let queueSignal,detailSignal;
    const el=id=>document.getElementById(id);
    const numeric=value=>(typeof value==='number'||typeof value==='string'&&value.trim()!=='')&&Number.isFinite(Number(value));
    const integer=value=>numeric(value)&&Number.isSafeInteger(Number(value))&&Number(value)>=0;
    const amount=value=>numeric(value)&&Number(value)>=0;
    const date=value=>typeof value==='string'&&value.trim()&&Number.isFinite(Date.parse(value));
    const month=value=>typeof value==='string'&&/^\d{4}-(0[1-9]|1[0-2])$/.test(value);
    const notes=value=>value&&typeof value==='object'&&(!Array.isArray(value)||value.length===0);
    const active=(modal,signal)=>modal?.isConnected&&getComputedStyle(modal).display!=='none'&&!signal?.aborted&&signal===window.IbemsPortalNavigation?.currentSignal;
    const focus=element=>{element.focus({preventScroll:true});element.scrollIntoView({block:'nearest',inline:'nearest'});};
    function feedback(scope,message='',type='pending'){
        const host=el(`settlement-${scope}-feedback`);host.textContent=message;host.className=`legacy-read-feedback is-${type}`;
        host.setAttribute('role',type==='error'?'alert':'status');host.setAttribute('aria-live',type==='error'?'assertive':'polite');
    }
    const validRun=row=>row&&integer(row.id)&&Number(row.id)>0&&month(row.run_month)&&date(row.run_at)&&typeof row.run_by_name==='string'&&row.run_by_name.trim()&&integer(row.total_accounts)&&amount(row.total_debt_before)&&notes(row.notes);
    async function queue({manual=false}={}){
        const signal=window.IbemsPortalNavigation?.currentSignal,modal=el('settlement-run-modal');
        if(!active(modal,signal)||manual&&queueLoading&&queueSignal===signal)return false;
        queueSignal=signal;queueLoading=true;const request=++queueVersion,current=()=>request===queueVersion&&active(modal,signal);
        const list=el('settlement-runs-list'),retry=el('settlement-queue-retry'),message=el('settlement-queue-feedback');
        const previous=document.activeElement,owned=list.contains(previous)||previous===retry||previous===message;
        const ownsFocus=()=>owned&&(document.activeElement===message||document.activeElement===document.body);
        list.innerHTML='';list.tabIndex=-1;list.setAttribute('aria-busy','true');settlementRunsCache=[];retry.hidden=true;retry.disabled=true;
        feedback('queue','Loading legacy deduction batches…');if(owned)focus(message);
        try{
            const response=await fetch('/accounting/settlement/runs?limit=12',{signal,cache:'no-store'}),data=await response.json();
            if(!current())return false;
            const seen=new Set();
            if(!response.ok||data?.status!=='success'||!Array.isArray(data.data)||data.data.some(row=>{if(!validRun(row)||seen.has(Number(row.id)))return true;seen.add(Number(row.id));return false;}))throw Error('History unavailable');
            if(data.data.length){renderSettlementRuns(data.data);list.tabIndex=0;feedback('queue');if(ownsFocus())focus(list);}
            else{feedback('queue','No previous salary deduction batches.','neutral');if(ownsFocus())focus(message);}
            return true;
        }catch(_){if(!current())return false;feedback('queue','Legacy deduction history could not be loaded. Retry legacy history to check again.','error');retry.hidden=false;retry.disabled=false;if(ownsFocus())focus(retry);return false;}
        finally{if(current()){queueLoading=false;retry.disabled=false;list.setAttribute('aria-busy','false');}}
    }
    function validDetails(data,runId){
        if(data?.status!=='success'||!validRun(data.run)||Number(data.run.id)!==runId||!Array.isArray(data.items)
            ||!integer(data.summary?.processed_accounts)||Number(data.summary.processed_accounts)!==data.items.length
            ||![data.summary.total_deducted,data.summary.total_debt_after].every(amount))return false;
        let deducted=0,after=0;
        for(const row of data.items){
            if(!row||!integer(row.user_id)||Number(row.user_id)<1||![row.monthly_salary,row.previous_debt,row.deducted_amount,row.new_debt].every(amount)||!date(row.created_at))return false;
            deducted+=Number(row.deducted_amount);after+=Number(row.new_debt);
        }
        return Math.round(deducted*100)===Math.round(Number(data.summary.total_deducted)*100)&&Math.round(after*100)===Math.round(Number(data.summary.total_debt_after)*100);
    }
    function clearDetails(message){
        activeSettlementDetails=null;
        el('settlement-details-export').disabled=true;el('settlement-details-print').disabled=true;
        el('settlement-details-head').textContent=message;
        el('settlement-details-body').innerHTML='<tr><td colspan="6">Batch details unavailable.</td></tr>';
        el('settlement-details-table-region').classList.remove('is-populated');
        ['count','deducted','after'].forEach(name=>el(`settle-details-${name}`).textContent='—');
    }
    async function details(runId,{manual=false}={}){
        const signal=window.IbemsPortalNavigation?.currentSignal,modal=el('settlement-run-details-modal');runId=Number(runId);
        if(!active(modal,signal)||!integer(runId)||runId<1||manual&&detailLoading&&detailSignal===signal&&selected===runId)return false;
        selected=runId;detailSignal=signal;detailLoading=true;const request=++detailVersion,current=()=>request===detailVersion&&active(modal,signal)&&selected===runId;
        const retry=el('settlement-details-retry'),message=el('settlement-details-feedback'),region=el('settlement-details-table-region');
        const previous=document.activeElement,owned=previous===retry||previous===message||previous===region;
        const ownsFocus=()=>owned&&(document.activeElement===message||document.activeElement===document.body);
        clearDetails('Loading salary deduction batch details…');el('settlement-details-body').innerHTML='<tr><td colspan="6">Loading batch details…</td></tr>';
        region.setAttribute('aria-busy','true');retry.hidden=true;retry.disabled=true;feedback('details',`Loading deduction batch #${runId}…`);if(owned)focus(message);
        try{
            const response=await fetch(`/accounting/settlement/runs/${runId}`,{signal,cache:'no-store'}),data=await response.json();if(!current())return false;
            if(!response.ok||!validDetails(data,runId))throw Error('Batch unavailable');
            renderSettlementDetails(data);activeSettlementDetails=data;
            el('settlement-details-export').disabled=false;el('settlement-details-print').disabled=false;
            region.classList.toggle('is-populated',data.items.length>0);feedback('details');if(ownsFocus())focus(region);return true;
        }catch(_){if(!current())return false;clearDetails('Salary deduction batch details are unavailable.');feedback('details',`Deduction batch #${runId} could not be loaded. Retry batch details to check again.`,'error');retry.hidden=false;retry.disabled=false;if(ownsFocus())focus(retry);return false;}
        finally{if(current()){detailLoading=false;retry.disabled=false;region.setAttribute('aria-busy','false');}}
    }
    function reset(scope){
        if(scope==='queue'){++queueVersion;queueLoading=false;}else{++detailVersion;detailLoading=false;selected=0;clearDetails('Batch details unavailable.');}
        el(`settlement-${scope}-retry`).hidden=true;feedback(scope);
    }
    window.IbemsLegacyHistory={queue,details,reset,retryDetails:()=>details(selected,{manual:true})};
})();
