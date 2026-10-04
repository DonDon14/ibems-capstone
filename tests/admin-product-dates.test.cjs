const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/admin-products.js','utf8');
for (const shared of [false,true]) test(`product table and snapshot preserve Manila instants with ${shared?'shared formatter':'fallback'}`,()=>{
    const elements=Object.fromEntries(['ap-body','ap-count-text','ap-view-content','ap-store-link'].map(id=>[id,{innerHTML:'',textContent:''}]));
    const context=vm.createContext({window:{},document:{getElementById:id=>elements[id]},apOpenModal:()=>{}});
    if(shared) vm.runInContext(fs.readFileSync('public/assets/js/ibems-format.js','utf8'),context);
    vm.runInContext(source.slice(0,source.indexOf('document.addEventListener("error"'))+
        source.slice(source.indexOf('function apSetListState'),source.indexOf('function apRenderPager'))+
        source.slice(source.indexOf('function apDetailItem'),source.indexOf('function apResetFilters')),context);
    for (const timestamp of ['2026-09-30 16:36:42','2026-09-30T16:36:42Z','2026-10-01T00:36:42+08:00',null,'<bad timestamp>']) {
        context.fixture={id:1,store_id:3,name:'Fixture product',is_active:true,updated_at:timestamp};
        vm.runInContext('apRows=[fixture];apRenderTable(apRows);apOpenSnapshot(1);',context);
        const expected=timestamp===null?'-':timestamp==='<bad timestamp>'?'&lt;bad timestamp&gt;':'Oct 1, 2026, 12:36 AM';
        assert.ok(elements['ap-body'].innerHTML.includes(`<td>${expected}</td>`));
        assert.ok(elements['ap-view-content'].innerHTML.includes(`<strong>${expected}</strong>`));
        assert.ok(elements['ap-view-content'].innerHTML.includes('Last Updated (Manila)'));
        assert.ok(!elements['ap-body'].innerHTML.includes('<bad timestamp>'));
    }
});
