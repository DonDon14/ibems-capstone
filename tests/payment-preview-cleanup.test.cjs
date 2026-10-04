const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/store-settings.part2.js','utf8');
function fixture(){
    const revoked=[],preview={innerHTML:''},signal=new AbortController();let change;
    const modal={classList:{add(){}}};
    const context=vm.createContext({
        URL:{revokeObjectURL:url=>revoked.push(url),createObjectURL:file=>`blob:${file.name}`},
        window:{IbemsPortalNavigation:{currentSignal:signal.signal}},
        editingPaymentMethodImageUrl:'/existing-image.png',editingPaymentMethodId:1,editingPaymentAccountId:2,
        paymentMethodSavePending:false,paymentAccountSavePending:false,
        settingsEscape:value=>value,setAccountResult(){},
        document:{body:{classList:{remove(){}}},getElementById:id=>id==='method-image-preview'?preview:id==='edit-method-image'?{addEventListener:(_,fn)=>{change=fn;}}:modal},
    });
    vm.runInContext(source.slice(0,source.indexOf('function setPaymentMethodSavePending')),context);
    vm.runInContext(source.slice(source.indexOf('function renderMethodImagePreview'),source.indexOf('async function uploadSelectedMethodImage')),context);
    const start=source.indexOf('document.getElementById("edit-method-image")?.addEventListener');
    vm.runInContext(source.slice(start,source.indexOf('document.getElementById("delete-method-btn")?.addEventListener',start)),context);
    return {context,revoked,preview,signal,select:name=>change({target:{files:name?[{name}]:[]}})};
}
test('replacing and clearing image previews releases temporary URLs without revoking saved image',()=>{
    const f=fixture();f.select('first');f.select('second');
    assert.deepEqual(f.revoked,['blob:first']);assert.match(f.preview.innerHTML,/blob:second/);
    f.select(null);assert.deepEqual(f.revoked,['blob:first','blob:second']);assert.match(f.preview.innerHTML,/existing-image/);
});
test('closing payment editor releases preview once',()=>{
    const f=fixture();f.select('first');f.context.closePaymentMethodModal();f.context.closePaymentMethodModal();
    assert.deepEqual(f.revoked,['blob:first']);
});
test('departing page releases active preview',()=>{
    const f=fixture();f.select('first');f.signal.abort();assert.deepEqual(f.revoked,['blob:first']);
});
