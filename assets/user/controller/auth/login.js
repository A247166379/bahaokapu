!function () {
    function authUrl(path) { return path + (path.includes('?') ? '&' : '?') + 'lang=' + encodeURIComponent(getVar('STORE_LOCALE') || getVar('LANG') || 'zh-cn'); }
    function localePath(path) {
        if (!path || path.charAt(0) !== '/' || path.charAt(1) === '/') return path;
        const clean = path.replace(/^\/(zh-tw|en|ru|vi)(?=\/|$)/, '') || '/';
        if (/^\/(admin|plugin|assets|user\/api)(\/|$)/.test(clean)) return path;
        const locale = (getVar('STORE_LOCALE') || 'zh-cn');
        const prefix = ['zh-tw','en','ru','vi'].includes(locale) ? '/' + locale : '';
        return prefix + (clean === '/' && prefix ? '' : clean);
    }

    function safeGoto(raw, fallback) {
        if (typeof raw !== "string" || raw === "" || raw === "null") {
            return fallback;
        }
        let target;
        try {
            target = decodeURIComponent(raw);
        } catch (e) {
            return fallback;
        }
        const safe = target.charAt(0) === "/"
            && target.charAt(1) !== "/"
            && target.indexOf("\\") === -1
            && !/[\x00-\x1f\x7f]/.test(target);
        return safe ? target : fallback;
    }

    let goto = localePath(safeGoto(util.getParam("goto"), "/"));

    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post(authUrl("/user/api/authentication/login"), data, res => {
            window.location.href = goto;
            message.success(res.msg);
        });
    });
}();
