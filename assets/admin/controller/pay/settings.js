(() => {
    'use strict';
    const root = document.querySelector('.pay-settings');
    if (!root) return;
    if (typeof window.__adminPaySettingsDestroy === 'function') window.__adminPaySettingsDestroy();
    const namespace = '.adminPaySettings';
    const codes = ['alipay', 'wxpay', 'usdt'];
    const names = {alipay:'支付宝', wxpay:'微信支付', usdt:'USDT'};
    const icons = {alipay:'payment-alipay.svg', wxpay:'payment-wxpay.svg', usdt:'payment-usdt.svg'};
    const q = selector => root.querySelector(selector);
    const escapes = {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => escapes[char]);
    const positiveId = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : 0;
    const safeImage = value => {
        try { const url = new URL(String(value || '/favicon.ico'), window.location.origin); return ['http:', 'https:'].includes(url.protocol) ? url.href : '/favicon.ico'; }
        catch (_) { return '/favicon.ico'; }
    };
    let disposed = false, methods = [], plugins = [], enableSaving = false, bindingSaving = false, refreshRevision = 0, catalogCleanup = null;
    const drafts = new Map(), profiles = new Map(), profileRequests = new Map(), profileRevisions = new Map();
    const requests = new Set(), listeners = [];
    const tabs = Array.from(root.querySelectorAll('[data-pay-tab]'));
    const listen = (node, name, callback) => { node.addEventListener(name, callback); listeners.push(() => node.removeEventListener(name, callback)); };
    const pluginFor = handle => plugins.find(plugin => String(plugin.id) === String(handle));
    const supportedPlugins = code => plugins.filter(plugin => Array.isArray(plugin.supported_methods) && plugin.supported_methods.includes(code));
    const validProfile = draft => positiveId(draft.profileId) && (profiles.get(draft.handle) || []).some(profile => profile.id === Number(draft.profileId));

    async function readAPI(url, {method = 'POST', body} = {}) {
        const controller = new AbortController(); requests.add(controller);
        try {
            const response = await fetch(url, {method, credentials:'same-origin', cache:'no-store', signal:controller.signal,
                headers:{Accept:'application/json', ...(body ? {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'} : {})},
                ...(body ? {body:new URLSearchParams(body)} : {})});
            let payload;
            try { payload = await response.json(); } catch (_) { throw new Error('接口暂时不可用，请刷新或重新登录。'); }
            if (!response.ok || payload?.code !== 200) throw new Error(typeof payload?.msg === 'string' ? payload.msg : '操作失败，请重试。');
            if (!payload.data || typeof payload.data !== 'object' || Array.isArray(payload.data)) throw new Error('接口数据格式不正确，请刷新重试。');
            return payload.data;
        } finally { requests.delete(controller); }
    }

    function normalizeMethods(data) {
        if (!Array.isArray(data.methods)) throw new Error('支付方式数据格式不正确，请刷新重试。');
        return codes.map(code => {
            const row = data.methods.find(item => item?.code === code);
            if (!row) throw new Error('支付方式数据不完整，请刷新重试。');
            return {...row, code, name:String(row.name || names[code]), icon:row.icon || '/assets/common/images/' + icons[code], enabled:row.enabled === true || Number(row.enabled) === 1};
        });
    }

    function syncMethods(data, committedCode = null) {
        methods = normalizeMethods(data);
        methods.forEach(method => {
            const previous = drafts.get(method.code);
            if (!previous || committedCode === method.code || (!previous.dirty && !previous.saving)) {
                const handle = String(method.binding?.handle || '');
                drafts.set(method.code, {...previous, handle, profileId:positiveId(method.binding?.pay_config_id), dirty:false, saving:false, profileBusy:false, message:previous?.message || '', failed:previous?.failed || false});
            }
        });
    }

    function settleProfile(draft) {
        const list = profiles.get(draft.handle);
        if (!list) return;
        const previous = draft.profileId;
        if (list.length === 1) draft.profileId = list[0].id;
        else if (!list.some(profile => profile.id === Number(draft.profileId))) draft.profileId = 0;
        if (previous !== draft.profileId) draft.dirty = true;
    }

    async function loadProfiles(handle, force = false) {
        if (!handle) return [];
        if (!force && profiles.has(handle)) return profiles.get(handle);
        if (!force && profileRequests.has(handle)) return profileRequests.get(handle);
        const revision = (profileRevisions.get(handle) || 0) + 1; profileRevisions.set(handle, revision);
        const request = readAPI('/admin/api/pay/getPluginConfigs', {body:{handle}}).then(data => {
            if (!Array.isArray(data.profiles)) throw new Error('收款配置数据格式不正确，请重试。');
            // Keep only labels and actual IDs; configuration values stay in the shared editor.
            const list = data.profiles.map(profile => ({id:positiveId(profile?.id), name:String(profile?.name || '默认配置')}));
            if (list.some(profile => !profile.id)) throw new Error('收款配置编号不正确，请刷新重试。');
            if (!disposed && revision === profileRevisions.get(handle)) profiles.set(handle, list);
            return list;
        }).finally(() => { if (profileRequests.get(handle) === request) profileRequests.delete(handle); });
        profileRequests.set(handle, request);
        return request;
    }

    async function prepareMethod(code) {
        const draft = drafts.get(code), handle = draft?.handle;
        if (!draft) return;
        if (!handle) { draft.profileBusy = false; renderMethods(); return; }
        draft.profileBusy = true; renderMethods();
        try {
            await loadProfiles(handle);
            if (disposed || drafts.get(code) !== draft || draft.handle !== handle) return;
            settleProfile(draft);
        } catch (error) {
            if (disposed || error.name === 'AbortError' || drafts.get(code) !== draft || draft.handle !== handle) return;
            draft.message = error.message || '收款配置加载失败，请重试。'; draft.failed = true;
        } finally {
            if (!disposed && drafts.get(code) === draft && draft.handle === handle) { draft.profileBusy = false; renderMethods(); }
        }
    }

    function renderMethods() {
        if (disposed) return;
        const active = document.activeElement, activeCard = active?.closest('[data-method-card]');
        const activeCode = activeCard?.dataset.methodCard, activeAction = active?.dataset.payAction;
        q('#pay-methods').innerHTML = methods.map(method => {
            const draft = drafts.get(method.code), supported = supportedPlugins(method.code), list = profiles.get(draft.handle) || [];
            const plugin = pluginFor(draft.handle), hasPlugin = supported.some(item => String(item.id) === draft.handle);
            const selected = (value, expected) => String(value) === String(expected) ? ' selected' : '';
            const options = supported.map(item => `<option value="${escapeHtml(item.id)}"${selected(item.id, draft.handle)}>${escapeHtml(item.info?.name || item.name || item.id)}</option>`).join('');
            const configurable = Boolean(plugin) && (Array.isArray(plugin.submit) ? plugin.submit.length > 0 : typeof plugin.submit === 'string' && plugin.submit.trim() !== '');
            const canSave = hasPlugin && !bindingSaving && !enableSaving && !draft.profileBusy && validProfile(draft);
            const currentReady = !draft.dirty && method.binding?.ready === true;
            let state = draft.profileBusy ? '正在读取收款配置…' : draft.dirty ? '选择后点击保存生效。' : method.binding?.readiness_message || '请选择支付插件。';
            state = String(state).replace(/路由/g, '支付设置');
            return `<article class="card pay-method-card" data-method-card="${method.code}"${draft.saving ? ' aria-busy="true"' : ''}>
                <div class="pay-method-card__heading"><div class="pay-method-card__identity"><img class="pay-method-card__icon" src="${escapeHtml(safeImage(method.icon))}" alt=""><h3>${escapeHtml(method.name)}</h3></div>
                <label class="pay-method-card__enable" for="pay-enable-${method.code}"><input id="pay-enable-${method.code}" data-pay-action="enable" type="checkbox" role="switch"${method.enabled ? ' checked' : ''}${enableSaving || bindingSaving ? ' disabled' : ''}><span>启用</span></label></div>
                <div class="pay-method-card__field"><label for="pay-plugin-${method.code}">支付插件</label><select id="pay-plugin-${method.code}" class="pay-method-card__select" data-pay-action="plugin"${draft.saving ? ' disabled' : ''}><option value="">${supported.length ? '请选择支付插件' : '暂无支持此方式的已安装插件'}</option>${options}</select></div>
                ${draft.profileBusy ? '' : list.length > 1 ? `<div class="pay-method-card__field"><label for="pay-profile-${method.code}">收款配置</label><select id="pay-profile-${method.code}" class="pay-method-card__select" data-pay-action="profile"${draft.saving ? ' disabled' : ''}><option value="">请选择收款配置</option>${list.map(profile => `<option value="${profile.id}"${selected(profile.id, draft.profileId)}>${escapeHtml(profile.name)}</option>`).join('')}</select></div>` : hasPlugin ? `<p class="pay-method-card__profile-note text-muted">${list.length === 1 ? '收款配置：' + escapeHtml(list[0].name) : '尚无收款配置，请先配置插件。'}</p>` : ''}
                <p class="pay-method-card__state${currentReady ? ' is-ready' : ''}">${escapeHtml(state)}</p>
                <div class="pay-method-card__actions"><button class="btn btn-sm btn-light-primary" type="button" data-pay-action="configure"${!configurable || draft.saving ? ' disabled' : ''}>配置插件</button><button class="btn btn-sm btn-primary" type="button" data-pay-action="save"${canSave ? '' : ' disabled'}>${draft.saving ? '正在保存…' : '保存'}</button></div>
                <p class="pay-method-card__message ${draft.failed ? 'is-error' : 'is-success'}" role="${draft.failed ? 'alert' : 'status'}"${draft.message ? '' : ' hidden'}>${escapeHtml(draft.message)}</p>
            </article>`;
        }).join('');
        if (activeCode && activeAction) q(`[data-method-card="${activeCode}"] [data-pay-action="${activeAction}"]`)?.focus({preventScroll:true});
    }

    async function saveBinding(code) {
        const method = methods.find(item => item.code === code), draft = drafts.get(code);
        if (!method || !draft || bindingSaving || enableSaving || draft.profileBusy || !validProfile(draft) || !supportedPlugins(code).some(plugin => String(plugin.id) === draft.handle)) return;
        bindingSaving = true; draft.saving = true; draft.message = ''; draft.failed = false; renderMethods();
        try {
            const data = await readAPI('/admin/api/pay/saveMethodBinding', {body:{id:positiveId(method.binding_id) || positiveId(method.binding?.id), buyer_method:code, handle:draft.handle, pay_config_id:positiveId(draft.profileId)}});
            if (disposed) return;
            syncMethods(data, code);
            const saved = drafts.get(code); saved.message = '已保存。'; saved.failed = false;
        } catch (error) {
            if (!disposed && error.name !== 'AbortError') { draft.message = error.message || '保存失败，请重试。'; draft.failed = true; draft.dirty = true; }
        } finally {
            if (!disposed) { bindingSaving = false; drafts.get(code).saving = false; renderMethods(); }
        }
    }

    async function saveEnabled(code, enabled) {
        if (disposed || enableSaving || bindingSaving) return;
        enableSaving = true; const draft = drafts.get(code); draft.message = '正在保存启用状态…'; draft.failed = false; renderMethods();
        const body = Object.fromEntries(methods.map(method => [method.code, method.code === code ? (enabled ? 1 : 0) : (method.enabled ? 1 : 0)]));
        try {
            const data = await readAPI('/admin/api/pay/saveMethods', {body});
            if (disposed) return;
            syncMethods(data); drafts.get(code).message = enabled ? '支付方式已启用。' : '支付方式已关闭。'; drafts.get(code).failed = false;
        } catch (error) {
            if (!disposed && error.name !== 'AbortError') { draft.message = error.message || '启用状态保存失败，请重试。'; draft.failed = true; }
        } finally { if (!disposed) { enableSaving = false; renderMethods(); } }
    }

    async function refreshSettings({handle} = {}) {
        const revision = ++refreshRevision;
        q('#pay-settings-error').hidden = true; q('#pay-settings-retry').hidden = true;
        q('#pay-settings-status').hidden = false;
        q('#pay-settings-status').textContent = '正在加载支付设置…';
        try {
            const [pluginData, methodData] = await Promise.all([readAPI('/admin/api/pay/getPlugins'), readAPI('/admin/api/pay/methods', {method:'GET'})]);
            if (disposed || revision !== refreshRevision) return;
            if (!Array.isArray(pluginData.list)) throw new Error('支付插件数据格式不正确，请刷新重试。');
            const normalizedMethods = normalizeMethods(methodData);
            plugins = pluginData.list.filter(plugin => plugin && plugin.id != null);
            syncMethods({methods:normalizedMethods}); renderMethods();
            if (catalogCleanup) catalogCleanup();
            if (!window.AdminPayPluginCatalog?.mount) throw new Error('支付插件界面未加载，请刷新重试。');
            catalogCleanup = window.AdminPayPluginCatalog.mount(q('#pay-plugin-catalog'), plugins, {onConfigured:detail => refreshSettings({handle:String(detail?.handle || '')})});
            const changedHandle = String(handle || '');
            if (changedHandle) profiles.delete(changedHandle);
            if (changedHandle) await loadProfiles(changedHandle, true);
            if (disposed || revision !== refreshRevision) return;
            await Promise.all(methods.map(method => prepareMethod(method.code)));
            if (!disposed && revision === refreshRevision) q('#pay-settings-status').hidden = true;
        } catch (error) {
            if (disposed || revision !== refreshRevision || error.name === 'AbortError') return;
            q('#pay-settings-status').hidden = true; q('#pay-settings-error').textContent = error.message || '加载支付设置失败，请重试。'; q('#pay-settings-error').hidden = false; q('#pay-settings-retry').hidden = false;
        }
    }

    function selectTab(button, focus = false) {
        tabs.forEach(peer => { const selected = peer === button; peer.setAttribute('aria-selected', String(selected)); peer.tabIndex = selected ? 0 : -1; q('#' + peer.getAttribute('aria-controls')).hidden = !selected; });
        if (focus) button.focus();
    }
    tabs.forEach((button, index) => {
        listen(button, 'click', () => selectTab(button));
        listen(button, 'keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else return;
            event.preventDefault(); selectTab(tabs[next], true);
        });
    });
    listen(q('#pay-methods'), 'change', event => {
        const control = event.target.closest('[data-pay-action]'), card = control?.closest('[data-method-card]');
        if (!card || disposed) return;
        const code = card.dataset.methodCard, draft = drafts.get(code);
        if (control.dataset.payAction === 'enable') { saveEnabled(code, control.checked); return; }
        if (!draft || draft.saving) return;
        draft.message = ''; draft.failed = false; draft.dirty = true;
        if (control.dataset.payAction === 'plugin') { draft.handle = control.value; draft.profileId = 0; prepareMethod(code); }
        else if (control.dataset.payAction === 'profile') { draft.profileId = positiveId(control.value); renderMethods(); }
    });
    listen(q('#pay-methods'), 'click', event => {
        const control = event.target.closest('button[data-pay-action]'), card = control?.closest('[data-method-card]');
        if (!card || control.disabled || disposed) return;
        const code = card.dataset.methodCard, draft = drafts.get(code);
        if (control.dataset.payAction === 'save') { saveBinding(code); return; }
        if (control.dataset.payAction === 'configure') {
            const plugin = pluginFor(draft.handle);
            if (plugin && window.AdminPayPluginConfig?.open(plugin, validProfile(draft) ? Number(draft.profileId) : null)) return;
            draft.message = '支付插件配置未加载，请刷新重试。'; draft.failed = true; renderMethods();
        }
    });
    listen(q('#pay-settings-retry'), 'click', () => refreshSettings());
    function destroy() {
        if (disposed) return;
        disposed = true; refreshRevision++; requests.forEach(controller => controller.abort()); requests.clear();
        listeners.forEach(remove => remove()); if (catalogCleanup) catalogCleanup(); catalogCleanup = null;
        if (window.jQuery) window.jQuery(document).off(namespace);
        if (window.__adminPaySettingsDestroy === destroy) delete window.__adminPaySettingsDestroy;
    }
    window.__adminPaySettingsDestroy = destroy;
    if (window.jQuery) window.jQuery(document).off(namespace).on('pjax:beforeReplace' + namespace + ' admin:page:destroy' + namespace, destroy);
    refreshSettings();
})();
