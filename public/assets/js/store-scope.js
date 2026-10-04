window.IbemsStoreScope = {
    resolve(stores, defaultId) {
        const raw = new URL(window.location.href).searchParams.get('store_id');
        const id = Number(raw === null ? defaultId || stores[0]?.id : raw);
        if (!Number.isInteger(id) || id <= 0 || !stores.some(store => Number(store.id) === id)) {
            throw new Error('The selected store is unavailable or is not assigned to you. Return to Assigned Stores.');
        }
        return id;
    }
};
