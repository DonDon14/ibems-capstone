(() => {
    let version = 0, active = null, reviewed = null;
    const el = id => document.getElementById(id);
    const numeric = value => (typeof value === "number" || typeof value === "string" && value.trim() !== "") && Number.isFinite(Number(value));
    const integer = value => numeric(value) && Number.isSafeInteger(Number(value)) && Number(value) >= 0;
    const amount = value => numeric(value) && Number(value) >= 0;
    const visible = modal => modal?.isConnected && getComputedStyle(modal).display !== "none";
    function feedback(message = "", type = "pending", owned = false) {
        const host = el("import-csv-result");
        host.textContent = message;
        host.className = `import-result is-${type}`;
        host.setAttribute("role", type === "error" ? "alert" : "status");
        host.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
        if (owned && message) { host.focus({preventScroll:true}); host.scrollIntoView({block:"nearest",inline:"nearest"}); }
    }
    function busy() {
        if (active && !active.current()) active.release();
        return !!active?.locked;
    }
    function invalidate() {
        if (busy() && active.kind === "apply") return false;
        active?.release(); active = null; ++version; reviewed = null; importCsvPreviewReady = false;
        el("import-csv-submit").disabled = true;
        el("import-csv-preview").disabled = false; el("import-csv-preview").textContent = "Preview CSV";
        return true;
    }
    function begin(kind,file,button) {
        const modal = el("import-csv-modal"), signal = window.IbemsPortalNavigation?.currentSignal;
        if (busy() || !visible(modal) || signal?.aborted || !button?.isConnected || button.disabled || !button.getClientRects().length) return null;
        const request = ++version, previous = document.activeElement, fileInput = el("import-csv-file"), applyButton = el("import-csv-submit");
        const owned = modal.contains(previous) || previous === document.body;
        const controls = Array.from(modal.querySelectorAll("button, input, select, textarea"));
        const original = controls.map(control=>[control,control.disabled]);
        const label = button.innerHTML, oldBusy = modal.getAttribute("aria-busy");
        const context = {kind,signal,file,locked:true,
            current:() => request === version && active === context && visible(modal) && !signal?.aborted
                && signal === window.IbemsPortalNavigation?.currentSignal && fileInput.isConnected && fileInput.files?.[0] === file,
            ownsFocus:() => owned && (modal.contains(document.activeElement) || document.activeElement === document.body),
            announce(message,type) { if (context.current()) feedback(message,type,context.ownsFocus()); },
            label(text) { if (context.current()) button.textContent = text; },
            release() {
                if (!context.locked) return;
                context.locked = false;
                original.forEach(([control,disabled])=>{control.disabled=disabled;});
                button.innerHTML=label;
                if (oldBusy === null) modal.removeAttribute("aria-busy"); else modal.setAttribute("aria-busy",oldBusy);
                applyButton.disabled = !reviewed || reviewed.file !== fileInput.files?.[0] || reviewed.signal?.aborted
                    || reviewed.signal !== window.IbemsPortalNavigation?.currentSignal || !importCsvPreviewReady;
            },
            cancel() { if(!context.current())return;const ownedNow=context.ownsFocus();feedback();context.release();if(ownedNow&&previous?.isConnected&&!previous.disabled)previous.focus(); },
        };
        active=context;
        controls.forEach(control=>{if(kind==='apply'||control.id!=='close-import-csv')control.disabled=true;});
        modal.setAttribute("aria-busy","true");
        return context;
    }
    function counts(data,file) {
        return data?.status === "success" && data.filename === file.name && [data.total_rows,data.valid_rows,data.invalid_rows].every(integer)
            && Number(data.valid_rows)+Number(data.invalid_rows) === Number(data.total_rows);
    }
    function invalidRows(data) {
        const seen=new Set();
        return Array.isArray(data.invalid_preview) && data.invalid_preview.length <= Number(data.invalid_rows)
            && (Number(data.invalid_rows) === 0 || data.invalid_preview.length > 0) && data.invalid_preview.every(row=>{
                if(!row || !integer(row.line) || Number(row.line)<2 || Number(row.line)>Number(data.total_rows)+1 || seen.has(Number(row.line))
                    || typeof row.error !== "string" || !row.error.trim())return false;
                seen.add(Number(row.line));return true;
            });
    }
    function previewData(data,file) {
        if(!counts(data,file) || ![data.create_count,data.update_count,data.credit_limit_update_count].every(integer)
            || Number(data.create_count)+Number(data.update_count)!==Number(data.valid_rows) || Number(data.credit_limit_update_count)>Number(data.valid_rows)
            || !invalidRows(data) || !Array.isArray(data.valid_preview) || data.valid_preview.length>Number(data.valid_rows)
            || Number(data.valid_rows)>0 && data.valid_preview.length===0)return false;
        const seen=new Set(data.invalid_preview.map(row=>Number(row.line)));
        return data.valid_preview.every(row=>{
            if(!row || !integer(row.line) || Number(row.line)<2 || Number(row.line)>Number(data.total_rows)+1 || seen.has(Number(row.line))
                || ![row.name,row.email,row.salary_schedule_code,row.salary_grade].every(value=>typeof value==='string'&&value.trim())
                || !['create','update'].includes(row.action) || !['faculty','staff'].includes(row.user_type)
                || !integer(row.salary_step) || Number(row.salary_step)<1 || !/^\d{4}-\d{2}-\d{2}$/.test(row.salary_effective_date||'')
                || ![row.monthly_salary,row.credit_limit,row.credit_percentage].every(amount) || Number(row.credit_percentage)>100)return false;
            seen.add(Number(row.line));return true;
        });
    }
    async function preview() {
        if(busy())return;
        const button=el('import-csv-preview'),modal=el('import-csv-modal');
        if(!visible(modal)||button.disabled||!button.getClientRects().length)return;
        const file=el('import-csv-file').files?.[0];
        reviewed=null;importCsvPreviewReady=false;el('import-csv-submit').disabled=true;
        if(!file){feedback('Choose a CSV file before previewing.','error');el('import-csv-file').focus();return;}
        if(!/\.(csv|txt)$/i.test(file.name)){feedback('Choose a .csv or .txt file.','error',true);return;}
        const context=begin('preview',file,button);if(!context)return;
        const formData=new FormData();formData.append('csv_file',file);
        el('import-csv-valid').innerHTML='';el('import-csv-invalid').innerHTML='';
        context.label('Previewing…');context.announce('Checking CSV rows…','pending');
        try{
            const response=await fetch('/accounting/debts/preview-csv',{method:'POST',body:formData,signal:context.signal});
            const data=await response.json();if(!context.current())return;
            if(data?.status==='error'){context.announce(typeof data.message==='string'&&data.message.trim()?data.message:'CSV preview was rejected. Review the file and preview again.','error');return;}
            if(!response.ok||!previewData(data,file))throw Error('Preview unavailable');
            renderCsvPreview(data);
            reviewed={file,data,signal:context.signal};importCsvPreviewReady=Number(data.valid_rows)>0;
            if(Number(data.total_rows)===0)context.announce('The CSV contains no data rows. Choose a file with employee rows and preview again.','neutral');
            else if(!importCsvPreviewReady)context.announce(`Preview complete. All ${Number(data.invalid_rows)} rows are invalid. Correct the file and preview again.`,'warning');
            else context.announce(el('import-csv-result').textContent,Number(data.invalid_rows)>0?'warning':'success');
        }catch(_){if(context.current())context.announce('CSV preview could not be read. Keep the selected file and use Preview CSV to retry.','error');}
        finally{context.release();}
    }
    async function apply() {
        if(busy())return;
        const button=el('import-csv-submit'),file=el('import-csv-file').files?.[0],signal=window.IbemsPortalNavigation?.currentSignal;
        if(!visible(el('import-csv-modal'))||button.disabled||!button.getClientRects().length)return;
        if(!file||!importCsvPreviewReady||reviewed?.file!==file||reviewed.signal!==signal){invalidate();feedback('Preview the selected file before applying its import.','error',true);return;}
        const preview=reviewed.data,context=begin('apply',file,button);if(!context)return;
        context.label('Reviewing…');context.announce('Review the CSV import before confirming.','pending');
        try{
            let accepted;
            try{accepted=await window.IbemsDialog.confirm(`Apply ${file.name}? The preview has ${Number(preview.valid_rows)} valid ${Number(preview.valid_rows)===1?'row':'rows'} and ${Number(preview.invalid_rows)} invalid ${Number(preview.invalid_rows)===1?'row':'rows'}. Invalid rows will be skipped. This can create employees and update their salary and credit profiles.`,{title:'Apply employee CSV import?',confirmLabel:'Apply Import'});}
            catch(_){context.announce('The import review could not be opened. Try Apply Import again.','warning');return;}
            if(!context.current())return;
            if(!accepted){context.cancel();return;}
            context.label('Applying…');context.announce('Applying employee CSV import…','pending');
            const formData=new FormData();formData.append('csv_file',file);
            let data;
            try{
                const response=await fetch('/accounting/debts/import-csv',{method:'POST',body:formData,signal:context.signal});
                data=await response.json();if(!context.current())return;
                if(data?.status==='error'){
                    context.announce(typeof data.message==='string'&&data.message.trim()?data.message:'The CSV import was rejected. Review the file before trying again.','error');
                    try{window.IbemsFeedbackSound?.play('error');}catch(_){}return;
                }
                if(!response.ok||!counts(data,file)||!integer(data.batch_id)||Number(data.batch_id)<1||Number(data.total_rows)!==Number(preview.total_rows)||!invalidRows(data))throw Error('Confirmation unavailable');
            }catch(_){
                if(context.current()){
                    reviewed=null;importCsvPreviewReady=false;
                    context.announce('The import could not be confirmed. Check the employee records with Accounting before previewing or applying this file again.','warning');
                }
                return;
            }
            reviewed=null;importCsvPreviewReady=false;
            el('import-csv-valid').innerHTML='';
            el('import-csv-invalid').innerHTML=data.invalid_preview.map(row=>`<div class="invalid-item"><strong>Line ${Number(row.line)}</strong><br>${aEscape(row.name||'-')} (${aEscape(row.email||'-')})<br><span>${aEscape(row.error)}</span></div>`).join('');
            const message=`Import batch #${Number(data.batch_id)} complete. Applied: ${Number(data.valid_rows)} of ${Number(data.total_rows)} rows. Skipped: ${Number(data.invalid_rows)}.`;
            const type=Number(data.invalid_rows)>0?'warning':'success';context.announce(message,type);
            if(Number(data.valid_rows)>0){try{window.IbemsFeedbackSound?.play('success');}catch(_){}}
            context.release();
            try{if(await loadData()!==true)throw Error('Refresh failed');if(context.current())context.announce(message,type);}
            catch(_){if(context.current())context.announce(`${message} Employee records could not be refreshed. Close this dialog and use Accounting Refresh to read the latest records.`,'warning');}
        }finally{context.release();}
    }
    window.IbemsAccountingImport={get busy(){return busy();},get applying(){return busy()&&active.kind==='apply';},preview,apply,invalidate,feedback};
})();
