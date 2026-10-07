(() => {
    'use strict';
    const root = document.getElementById('visit-stats-admin');
    if (!root) return;
    if (typeof window.__visitStatsDestroy === 'function') window.__visitStatsDestroy();
    const q = selector => root.querySelector(selector);
    const dateInput = q('#vs-date');
    const number = new Intl.NumberFormat('zh-CN');
    const compactNumber = new Intl.NumberFormat('zh-CN', {notation:'compact',maximumFractionDigits:1});
    const namespace = '.visitStats';
    const listeners = [];
    let data = null, metric = 'pv', disposed = false, request = null, sequence = 0;
    let collectionEnabled = null, collectionChangedAt = null, collectionSaving = false, collectionRequest = null, collectionEpoch = 0;
    const count = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
    const formatCount = value => count(value) === null ? '—' : number.format(value);
    const setText = (selector, value) => { q(selector).textContent = String(value); };
    const dateLabel = date => date === root.dataset.today ? '今天' : date === previousDate(root.dataset.today) ? '昨天' : date;
    function previousDate(date) {
        const parsed = new Date(date + 'T00:00:00Z');
        if (!Number.isFinite(parsed.getTime())) return '';
        parsed.setUTCDate(parsed.getUTCDate() - 1);
        return parsed.toISOString().slice(0,10);
    }
    function listen(element, name, callback) {
        element.addEventListener(name, callback); listeners.push(() => element.removeEventListener(name, callback));
    }
    const tabs = Array.from(root.querySelectorAll('[data-vs-tab]'));
    function selectTab(button, focus = false) {
        tabs.forEach(peer => {
            const selected = peer === button;
            peer.setAttribute('aria-selected',String(selected));
            peer.tabIndex = selected ? 0 : -1;
            q('#' + peer.getAttribute('aria-controls')).hidden = !selected;
        });
        if (focus) button.focus();
        if (button.dataset.vsTab === 'trend') renderChart();
    }
    tabs.forEach((button,index) => {
        listen(button,'click',() => selectTab(button));
        listen(button,'keydown',event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else return;
            event.preventDefault(); selectTab(tabs[next],true);
        });
    });
    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = String(text);
        if (className) node.className = className;
        return node;
    }
    function collectionState(response) {
        const state = response?.collection;
        if (!state || typeof state !== 'object' || typeof state.enabled !== 'boolean') return null;
        return {enabled:state.enabled,changed_at:typeof state.changed_at === 'string' ? state.changed_at : null};
    }
    function renderCollection(message) {
        const control = q('#vs-collection-enabled');
        control.checked = collectionEnabled === true;
        control.disabled = collectionSaving || collectionEnabled === null;
        q('#vs-collection-panel').setAttribute('aria-busy',String(collectionSaving));
        const status = message || (collectionEnabled === null ? '开关状态暂不可用，请刷新重试。'
            : collectionEnabled ? '已启用，正在记录新访问。' : '已关闭，不记录新访问；历史记录仍可查看。');
        setText('#vs-collection-status',status + (collectionChangedAt ? ' 上次切换：' + collectionChangedAt + '（北京时间）' : ''));
    }
    async function saveCollection() {
        const control = q('#vs-collection-enabled');
        if (disposed || collectionSaving || collectionEnabled === null) { renderCollection(); return; }
        const desired = control.checked, previous = collectionEnabled;
        if (desired === previous) return;
        const epoch = ++collectionEpoch, controller = new AbortController();
        collectionRequest = controller; collectionSaving = true; q('#vs-collection-error').hidden = true;
        renderCollection(desired ? '正在保存：启用访问统计…' : '正在保存：关闭访问统计…');
        try {
            const response = await fetch('/admin/api/visitStats/collection',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({enabled:desired ? '1' : '0'}),signal:controller.signal});
            let payload;
            try { payload = await response.json(); } catch (_) { throw new Error('保存接口暂时不可用，请刷新或重新登录。'); }
            if (!response.ok || Number(payload.code) !== 200) throw new Error(typeof payload.msg === 'string' ? payload.msg : typeof payload.message === 'string' ? payload.message : '保存访问统计开关失败。');
            const state = collectionState(payload.data);
            if (!state || state.enabled !== desired) throw new Error('服务器未确认所选开关状态，请刷新核实。');
            if (disposed || epoch !== collectionEpoch || !root.isConnected) return;
            collectionEnabled = state.enabled; collectionChangedAt = state.changed_at; collectionSaving = false;
            renderCollection(desired ? '已保存：访问统计已启用。' : '已保存：访问统计已关闭，历史记录仍可查看。');
            load({preserveCollectionStatus:true});
        } catch (error) {
            if (disposed || epoch !== collectionEpoch || error.name === 'AbortError') return;
            collectionEnabled = previous; collectionSaving = false;
            renderCollection('保存未确认，当前显示上次确认状态，请刷新核实。');
            q('#vs-collection-error').hidden = false; setText('#vs-collection-error',error.message || '保存访问统计开关失败，请刷新核实。');
        } finally {
            if (!disposed && epoch === collectionEpoch) { collectionSaving = false; control.disabled = collectionEnabled === null; q('#vs-collection-panel').setAttribute('aria-busy','false'); if (collectionRequest === controller) collectionRequest = null; }
        }
    }
    function coverageText(response) {
        const state = response.coverage || {};
        const status = state.current_status || (state.current ? 'full' : 'unavailable');
        const current = status === 'unavailable' ? '所选日期尚无可用历史记录。'
            : state.current_paused ? '所选日期包含暂停采集的时段，仅显示实际记录的数据。'
            : status === 'partial' ? '所选日期仅覆盖已启用的实际采集时段，未覆盖整日。' : '所选日期的统计窗口已完整覆盖。';
        const comparison = state.comparison ? '可与前一天相同时段比较。' : '前一天相同时段未完整记录，暂不计算增长率。';
        return current + comparison + (state.pause_history_valid === false ? '暂停历史记录不完整，统计仅展示已知记录。' : '');
    }
    function comparisonText(key) {
        const summary = data.summary;
        const until = typeof summary.comparison_until === 'string' ? summary.comparison_until : (data.date === root.dataset.today ? '' : '24:00');
        const current = summary['current_comparison_' + key] ?? summary[key];
        const previous = summary['comparison_' + key];
        const previousLabel = dateLabel(data.previous_date);
        const windowLabel = until === '24:00' ? '整日对比' : until ? '截至 ' + until + ' 同时段' : '同时段对比';
        if (!data.coverage?.comparison || count(current) === null || count(previous) === null) return windowLabel + '：历史未完整记录，暂无对比';
        const change = summary[key + '_change_percent'];
        const changeLabel = typeof change === 'number' && Number.isFinite(change)
            ? (change > 0 ? '+' : '') + change.toLocaleString('zh-CN',{maximumFractionDigits:1}) + '%' : '暂无增长率';
        return windowLabel + '：' + formatCount(current) + ' / ' + previousLabel + ' ' + formatCount(previous) + '（' + changeLabel + '）';
    }
    function renderSummary() {
        const selectedLabel = dateLabel(data.date), previousLabel = dateLabel(data.previous_date);
        for (const key of ['pv','uv']) {
            setText('#vs-' + key + '-label', selectedLabel + (key === 'pv' ? '浏览量 PV' : '独立访客 UV'));
            setText('#vs-' + key, formatCount(data.summary[key]));
            setText('#vs-' + key + '-compare', comparisonText(key));
            const previous = data.summary['previous_' + key];
            const dayLabel = data.coverage?.previous_status === 'partial' ? '已记录' : '整日';
            setText('#vs-' + key + '-previous', previousLabel + dayLabel + ' ' + key.toUpperCase() + '：' + (count(previous) === null ? '尚无历史记录' : formatCount(previous)));
        }
        setText('#vs-series-current', selectedLabel + '各小时');
        setText('#vs-series-previous', previousLabel + '各小时');
        setText('#vs-chart-description', data.date + ' 与 ' + data.previous_date + '，北京时间。前一天曲线按完整小时展示。');
        setText('#vs-coverage', coverageText(data));
        setText('#vs-started-at', data.started_at ? '统计开始时间：' + data.started_at + '（北京时间）' : '访问统计尚未启用。');
        for (const key of ['pv','uv']) {
            setText('#vs-hour-current-' + key, selectedLabel + ' ' + key.toUpperCase());
            setText('#vs-hour-previous-' + key, previousLabel + ' ' + key.toUpperCase());
        }
    }
    function hours() {
        const map = new Map();
        data.hourly.forEach(row => { if (row && Number.isInteger(row.hour) && row.hour >= 0 && row.hour <= 23) map.set(row.hour,row); });
        return Array.from({length:24}, (_,hour) => map.get(hour) || {hour,label:String(hour).padStart(2,'0') + ':00',pv:null,uv:null,previous_pv:null,previous_uv:null});
    }
    function renderHours() {
        const tbody = q('#vs-hour-rows'); tbody.replaceChildren();
        for (const row of hours()) {
            const tr = element('tr');
            tr.append(element('td', String(row.hour).padStart(2,'0') + ':00–' + String(row.hour).padStart(2,'0') + ':59'));
            ['pv','uv','previous_pv','previous_uv'].forEach(key => tr.append(element('td',formatCount(row[key]))));
            tbody.append(tr);
        }
    }
    function renderDistribution(selector, rows, type) {
        const tbody = q(selector); tbody.replaceChildren();
        if (!rows.length) {
            const row = element('tr'), cell = element('td', count(data.summary.pv) === null ? '所选日期尚无历史记录' : '暂无访问记录','vs-empty');
            cell.colSpan = 4; row.append(cell); tbody.append(row); return;
        }
        for (const row of rows) {
            if (!row || typeof row !== 'object') continue;
            const tr = element('tr');
            const fallbacks = {pc:'电脑',android:'安卓',ios:'苹果',other:'其他'};
            const label = typeof row.label === 'string' && row.label ? row.label : type === 'devices' ? fallbacks[row.key] || '其他' : row.code || '未知地区';
            tr.append(element('td',label),element('td',formatCount(row.pv)),element('td',formatCount(row.uv)));
            tr.append(shareCell(row.share)); tbody.append(tr);
        }
    }
    function shareCell(share) {
        const cell = element('td'), parsed = count(share), value = parsed === null ? null : Math.min(100,parsed);
        const wrapper = element('div',undefined,'vs-share');
        wrapper.append(element('span',value === null ? '—' : value.toLocaleString('zh-CN',{maximumFractionDigits:1}) + '%'));
        if (value !== null) { const track = element('span',undefined,'vs-share-track'), fill = element('i'); fill.style.width = value + '%'; track.setAttribute('aria-hidden','true'); track.append(fill); wrapper.append(track); }
        cell.append(wrapper); return cell;
    }
    function renderSourceRows(selector, rows, domains, emptyText) {
        const tbody = q(selector); tbody.replaceChildren();
        for (const row of rows) {
            if (!row || typeof row !== 'object') continue;
            const label = typeof row.label === 'string' && row.label ? row.label : '其他来源';
            if (domains && (typeof row.domain !== 'string' || !row.domain)) continue;
            const tr = element('tr'), name = element('td');
            if (domains) {
                name.append(element('span',row.domain,'vs-source-domain'),element('span',label,'vs-source-domain-label text-muted'));
            } else name.textContent = label;
            tr.append(name,element('td',formatCount(row.pv)),element('td',formatCount(row.uv)),shareCell(row.share));
            tbody.append(tr);
        }
        if (!tbody.children.length) {
            const row = element('tr'), cell = element('td',emptyText,'vs-empty'); cell.colSpan = 4; row.append(cell); tbody.append(row);
        }
    }
    function renderSources() {
        // Source collection may be enabled later than the main visit statistics.
        const complete = data.source_coverage && typeof data.source_coverage === 'object'
            && data.source_summary && typeof data.source_summary === 'object'
            && Array.isArray(data.sources) && Array.isArray(data.source_domains);
        const coverage = complete ? data.source_coverage : {};
        const status = coverage.current_status || (coverage.current ? 'full' : 'unavailable');
        const recorded = complete && (status === 'full' || status === 'partial');
        const summary = recorded ? data.source_summary : {};
        const sources = recorded ? data.sources : [];
        const domains = recorded ? data.source_domains.slice(0,50) : [];
        const selectedLabel = dateLabel(data.date);
        setText('#vs-source-totals',recorded ? selectedLabel + '来源记录：PV ' + formatCount(summary.pv) + ' · UV ' + formatCount(summary.uv) : '尚无来源记录');
        setText('#vs-source-state',!recorded ? data.date + ' 尚无来源记录。'
            : coverage.current_paused ? data.date + ' 包含暂停采集的时段，仅显示已记录的来源。'
            : status === 'partial' ? data.date + ' 仅显示实际采集时段的来源，未覆盖整日。'
            : data.date + ' 的来源统计窗口已完整覆盖。');
        const startedAt = typeof data.sources_started_at === 'string' && data.sources_started_at ? data.sources_started_at : null;
        setText('#vs-source-started-at',startedAt ? '来源统计开始时间：' + startedAt + '（北京时间）；启用前的来源无法补回。' : '来源统计尚未启用或尚无启用记录；不影响上方访问统计。');
        const total = recorded ? count(data.source_domains_total) : null;
        setText('#vs-source-domain-total',total === null ? '' : total > 50 ? '共 ' + formatCount(total) + ' 个来源网站，按 PV 显示前 50 个。' : '共 ' + formatCount(total) + ' 个来源网站。');
        q('#vs-source-domain-total').hidden = total === null;
        renderSourceRows('#vs-source-rows',sources,false,recorded ? '所选日期暂无来源记录' : '尚无来源记录');
        renderSourceRows('#vs-source-domain-rows',domains,true,recorded ? '所选日期暂无来源网站记录' : '尚无来源记录');
    }
    function svgElement(tag, attributes, text) {
        const node = document.createElementNS('http://www.w3.org/2000/svg',tag);
        Object.entries(attributes || {}).forEach(([key,value]) => node.setAttribute(key,String(value)));
        if (text !== undefined) node.textContent = String(text);
        return node;
    }
    function renderChart() {
        if (!data || disposed || !root.isConnected || q('#vs-results').hidden || q('#vs-panel-trend').hidden) return;
        const container = q('#vs-chart');
        const rows = hours(), values = rows.flatMap(row => [count(row[metric]),count(row['previous_' + metric])]).filter(value => value !== null);
        const selectedHasData = count(data.summary[metric]) !== null;
        let message = !selectedHasData ? '所选日期尚无可用历史记录。'
            : data.summary[metric] === 0 ? '所选日期暂无访问记录。' : '';
        if (data.coverage?.previous_status === 'unavailable') message += '前一天尚无历史记录，暂不展示对比曲线。';
        else if (data.coverage?.previous_status === 'partial') message += '前一天只展示实际采集的已记录时段。';
        if (data.coverage?.current_paused) message += '所选日期含暂停采集时段，未记录的小时不补算。';
        setText('#vs-chart-state',message); q('#vs-chart-state').hidden = !message;
        container.setAttribute('aria-label',data.date + ' 与 ' + data.previous_date + ' 每小时 ' + metric.toUpperCase() + ' 趋势，精确值见下方小时数据。');
        container.replaceChildren();
        if (!values.length) { container.append(element('p','暂无可绘制的小时数据','vs-empty')); return; }
        const width = Math.max(360,Math.round(container.clientWidth || 960)), height = 270;
        const left = 48, right = 18, top = 15, bottom = 35, chartWidth = width - left - right, chartHeight = height - top - bottom;
        const maximum = Math.max(1,...values), rawStep = maximum / 4, power = 10 ** Math.floor(Math.log10(rawStep));
        const coefficient = [1,2,5,10].find(value => value * power >= rawStep) || 10;
        const step = Math.max(1,coefficient * power), ceiling = step * 4;
        const svg = svgElement('svg',{viewBox:'0 0 ' + width + ' ' + height,role:'presentation','aria-hidden':'true'});
        const x = hour => left + chartWidth * hour / 23, y = value => top + chartHeight * (1 - value / ceiling);
        for (let line = 0; line <= 4; line++) {
            const value = line * step, position = y(value);
            svg.append(svgElement('line',{x1:left,y1:position,x2:width-right,y2:position,class:'vs-grid-line'}));
            svg.append(svgElement('text',{x:left-8,y:position+4,'text-anchor':'end'},compactNumber.format(value)));
        }
        [0,4,8,12,16,20,23].forEach(hour => svg.append(svgElement('text',{x:x(hour),y:height-10,'text-anchor':'middle'},String(hour).padStart(2,'0') + ':00')));
        for (const previous of [true,false]) {
            const key = previous ? 'previous_' + metric : metric;
            let segment = [];
            const flush = () => { if (segment.length) svg.append(svgElement('polyline',{points:segment.join(' '),class:'vs-line vs-line-' + (previous ? 'previous' : 'current')})); segment = []; };
            for (const row of rows) {
                const value = count(row[key]);
                if (value === null) { flush(); continue; }
                segment.push(x(row.hour) + ',' + y(value));
                const circle = svgElement('circle',{cx:x(row.hour),cy:y(value),r:2.5,fill:previous?'var(--vs-orange)':'var(--vs-blue)'});
                circle.append(svgElement('title',{},(previous ? data.previous_date : data.date) + ' ' + String(row.hour).padStart(2,'0') + ':00 ' + metric.toUpperCase() + '：' + formatCount(value)));
                svg.append(circle);
            }
            flush();
        }
        container.append(svg);
    }
    function validate(response, selected) {
        if (!response || response.date !== selected || typeof response.previous_date !== 'string' || !response.summary || !response.coverage
            || !Array.isArray(response.hourly) || !Array.isArray(response.devices) || !Array.isArray(response.countries)) throw new Error('统计接口返回的数据格式不正确，请稍后刷新。');
        return response;
    }
    async function load(options = {}) {
        if (disposed || !dateInput.reportValidity()) return;
        const selected = dateInput.value;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(selected)) return;
        request?.abort(); const controller = new AbortController(); request = controller;
        const token = ++sequence, collectionSnapshot = collectionEpoch;
        q('#vs-refresh').disabled = true; q('#vs-results').hidden = true; q('#vs-error').hidden = true;
        root.setAttribute('aria-busy','true'); setText('#vs-status','正在加载 ' + selected + ' 的访问统计…');
        try {
            const response = await fetch('/admin/api/visitStats/summary?date=' + encodeURIComponent(selected), {method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
            let payload;
            try { payload = await response.json(); } catch (_) { throw new Error('统计接口暂时不可用，请稍后刷新或重新登录。'); }
            if (!response.ok || Number(payload.code) !== 200) throw new Error(typeof payload.msg === 'string' ? payload.msg : typeof payload.message === 'string' ? payload.message : '加载访问统计失败，请稍后刷新。');
            const result = validate(payload.data,selected);
            if (disposed || token !== sequence || !root.isConnected) return;
            data = result; q('#vs-results').hidden = false;
            if (!collectionSaving && collectionSnapshot === collectionEpoch) {
                const state = collectionState(result), message = options.preserveCollectionStatus && state && state.enabled === collectionEnabled && state.changed_at === collectionChangedAt ? q('#vs-collection-status').textContent : null;
                collectionEnabled = state ? state.enabled : null; collectionChangedAt = state ? state.changed_at : null;
                q('#vs-collection-error').hidden = true;
                // The saved notice already contains the changed-at suffix.
                if (message) { renderCollection(); setText('#vs-collection-status',message); } else renderCollection();
            }
            renderSummary(); renderHours(); renderDistribution('#vs-device-rows',data.devices,'devices'); renderDistribution('#vs-country-rows',data.countries,'countries'); renderSources(); renderChart();
            setText('#vs-status',data.date + ' · 北京时间 · 已更新；点击刷新获取最新统计。');
        } catch (error) {
            if (disposed || token !== sequence || error.name === 'AbortError') return;
            data = null; q('#vs-results').hidden = true; q('#vs-error').hidden = false;
            setText('#vs-error',error.message || '加载访问统计失败，请稍后刷新。'); setText('#vs-status','本次统计未加载成功。');
            if (collectionEnabled === null && !collectionSaving) renderCollection('读取开关状态失败，请刷新重试。');
        } finally {
            if (!disposed && token === sequence) { q('#vs-refresh').disabled = false; root.removeAttribute('aria-busy'); if (request === controller) request = null; }
        }
    }
    listen(q('#vs-filter'),'submit',event => { event.preventDefault(); load(); });
    listen(q('#vs-collection-enabled'),'change',saveCollection);
    listen(dateInput,'change',load);
    listen(q('#vs-today'),'click',() => { dateInput.value = root.dataset.today; load(); });
    listen(q('#vs-yesterday'),'click',() => { dateInput.value = previousDate(root.dataset.today); load(); });
    root.querySelectorAll('[data-vs-metric]').forEach(button => listen(button,'click',() => {
        metric = button.dataset.vsMetric;
        root.querySelectorAll('[data-vs-metric]').forEach(peer => peer.setAttribute('aria-pressed',String(peer === button)));
        renderChart();
    }));
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(renderChart) : null;
    observer?.observe(q('#vs-chart'));
    function destroy() {
        if (disposed) return;
        disposed = true; sequence++; collectionEpoch++; request?.abort(); request = null; collectionRequest?.abort(); collectionRequest = null; observer?.disconnect(); listeners.forEach(remove => remove());
        if (window.jQuery) window.jQuery(document).off(namespace);
        if (window.__visitStatsDestroy === destroy) delete window.__visitStatsDestroy;
    }
    window.__visitStatsDestroy = destroy;
    if (window.jQuery) window.jQuery(document).off(namespace).on('admin:page:destroy' + namespace + ' pjax:beforeReplace' + namespace,destroy);
    load();
})();
