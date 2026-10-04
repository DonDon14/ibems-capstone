window.FoodTemplateUI = (() => {
    const e = value => String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = cents => (Number(cents)/100).toFixed(2);
    function variant(row={}) {
        return `<fieldset data-food-variant data-id="${Number(row.id||0)}"><legend>Variant</legend><label>Name <input name="variant-label" value="${e(row.variant_label||'Regular')}" maxlength="100" required></label><label>Default price <input name="variant-price" type="number" min="0.01" step="0.01" value="${row.price??''}" required></label><label>Selling unit <select name="variant-unit">${['serving','meal','tray','piece','pack','container','liter'].map(unit=>`<option ${unit===(row.unit_code||'serving')?'selected':''}>${unit}</option>`).join('')}</select></label><label>Base portions consumed (shared batch only)<input name="variant-portions" type="number" min="1" max="100000" step="1" value="${Number(row.food_portions||1)}" required></label></fieldset>`;
    }
    function fields(product, variants) {
        const meta=product.food_details||{};
        return `<input name="family_id" type="hidden" value="${Number(product.family_id)}"><input name="version" type="hidden" value="${Number(meta.version)}"><label>Dish name <input name="name" maxlength="150" required value="${e(product.name)}"></label><label>Category <input name="category" maxlength="100" required value="${e(product.category)}"></label><label>Description <textarea name="description" maxlength="2000">${e(meta.description)}</textarea></label><label>Allergens <input name="allergens" maxlength="500" value="${e(meta.allergens)}"></label><label>Dietary information <input name="dietary" maxlength="500" value="${e(meta.dietary)}"></label><input name="image_url" type="hidden" value="${e(product.image_url)}"><label>Replace photo <input name="photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif"></label><label>Tracking <select name="tracking"><option value="independent">Separate variant quantities</option><option value="shared" ${meta.tracking==='shared'?'selected':''}>Shared batch</option></select></label><div data-variant-rows>${variants.map(variant).join('')}</div><button class="secondary-btn" type="button" data-add-food-variant>Add variant</button><button class="primary-btn" type="submit">Save template for future selections</button>`;
    }
    function render(state, target) {
        const groups=new Map();
        for (const product of state.food) { const key=product.food_details?'f'+product.family_id:'p'+product.id; if(!groups.has(key)) groups.set(key,[]); groups.get(key).push(product); }
        target.innerHTML=Array.from(groups.values()).map(variants=>{
            const product=variants[0], meta=product.food_details||{}, batch=(state.batches||[]).find(row=>Number(row.family_id)===Number(product.family_id));
            let html=`<article class="offering-panel">${product.image_url?`<img class="food-template-photo" src="${e(product.image_url)}" alt="${e(product.name)}" loading="lazy">`:''}<h3>${e(product.name)}</h3><p>${e(product.category)} · ${e(meta.description)}</p><p>Allergens: ${e(meta.allergens||'Not specified')} · ${e(meta.dietary)}</p>`;
            if(meta.tracking==='shared') html+=`<form data-food-batch="${Number(product.family_id)}"><label>Total base portions prepared today <input name="capacity" type="number" min="${Math.max(1,Number(batch?.used||0))}" max="100000" step="1" value="${Number(batch?.capacity||10)}" required></label><p>${Number(batch?.used||0)} consumed${batch?.closed?' · Closed':''}</p><button class="primary-btn" type="submit" ${batch?.closed?'disabled':''}>Save batch / add preparation</button></form>`;
            for (const item of variants) {
                const today=state.offerings.find(row=>Number(row.product_id)===Number(item.id));
                const enabled=Number(today?.enabled||0)===1 && !Number(today?.closed||0);
                html+=`<form data-food="${Number(item.id)}"><h4>${e(item.variant_label||'Regular')} · ${e(item.unit_code)}</h4><p>${enabled?'On today’s menu':'Not offered'} · ${Number(today?.sold||0)} sold</p><label>Today’s price <input name="price" type="number" min="0.01" step="0.01" required value="${today?money(today.price_cents):Number(item.price).toFixed(2)}"></label><label>${meta.tracking==='shared'?'Maximum sales of this variant today (also limited by shared batch)':'Total prepared servings today'} <input name="capacity" type="number" min="${Math.max(1,Number(today?.sold||0))}" max="100000" step="1" required value="${Number(today?.capacity||10)}"></label><label>Available today <input name="enabled" type="checkbox" ${enabled?'checked':''}></label><button class="primary-btn" type="submit" ${today?.closed?'disabled':''}>Save availability</button></form>`;
                if(today && !Number(today.batch_id) && !Number(today.closed)) html+=closeForm('offering_id',today.id,Number(today.capacity)-Number(today.sold));
            }
            if(batch && !Number(batch.closed)) html+=closeForm('batch_id',batch.id,Number(batch.capacity)-Number(batch.used));
            if(product.food_details || !Number(product.family_id)) html+=`<details><summary>Edit saved dish template</summary><p>Existing daily selections and receipts keep their saved details. Existing variants cannot be removed; make them unavailable for today instead.</p><form data-edit-food-template>${!product.food_details?`<input type="hidden" name="legacy_product_id" value="${Number(product.id)}">`:''}${fields({...product,family_id:product.family_id||0,food_details:product.food_details||{version:0}},variants)}</form></details>`;
            return html+'</article>';
        }).join('') || '<p>No dish templates yet.</p>';
    }
    function closeForm(key,id,remaining) { return `<details><summary>Close and record leftovers</summary><form data-food-close><input type="hidden" name="${key}" value="${Number(id)}"><label>Remaining quantity <input name="leftover" type="number" min="0" step="1" required value="${remaining}"></label><label>Disposition <select name="disposition" required><option value="">Choose one</option><option value="Discarded">Discarded</option><option value="Spoiled">Spoiled</option><option value="Donated">Donated</option><option value="Retained">Retained</option></select></label><p>Only discarded or spoiled leftovers count in the waste report. Closing stops new sales and does not carry stock into tomorrow.</p><button class="secondary-btn" type="submit">Close food preparation</button></form></details>`; }
    async function payload(form,storeId) {
        // Controls are disabled by the save lock, so read their values explicitly.
        const result=Object.fromEntries(Array.from(form.elements).filter(input=>input.name && input.type!=='file').map(input=>[input.name,input.value]));
        result.publish_today = Boolean(form.elements.publish_today?.checked);
        if (result.publish_today) result.today_capacity = Number(form.elements.today_capacity.value);
        result.variants=Array.from(form.querySelectorAll('[data-food-variant]')).map(row=>({id:Number(row.dataset.id),label:row.querySelector('[name="variant-label"]').value,price:row.querySelector('[name="variant-price"]').value,unit:row.querySelector('[name="variant-unit"]').value,portions:row.querySelector('[name="variant-portions"]').value}));
        const file=form.elements.photo?.files?.[0];
        if(file) {
            const body=new FormData(); body.append('store_id',String(storeId)); body.append('image',file);
            const response=await fetch('/store/offerings/food-image',{method:'POST',body}); const data=await response.json();
            if(!response.ok||data.status!=='success') throw new Error(data.message||'Image upload failed.');
            result.image_url=data.image_url; form.elements.image_url.value=data.image_url;
        }
        return result;
    }
    return {variant,render,payload};
})();
