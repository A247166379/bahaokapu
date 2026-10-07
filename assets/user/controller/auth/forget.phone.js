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


    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post(authUrl("/user/api/authentication/password"), data, res => {
            setTimeout(() => {
                window.location.href = localePath("/user/authentication/login");
            }, 1000);
            message.success(res.msg);
        });
    });


    $(`.send-phone-captcha`).click(function () {
        message.prompt({
            title: i18n('人机验证'),
            width: 420,
            html: `<img src="${authUrl('/user/captcha/image?action=phoneForgetCaptcha')}" data-acg-refresh="${authUrl('/user/captcha/image?action=phoneForgetCaptcha')}" class="prompt-image-code" alt="${i18n('更换验证码')}">`,
            inputAttributes: {
                onpaste: 'return false',
                oncopy: 'return false'
            },
            confirmButtonText: `${i18n('继续操作')}`,
            inputValidator: function (value) {
                return (!value && i18n("请输入验证码"));
            }
        }).then(res => {
            if (res.isConfirmed === true) {
                util.post(authUrl("/user/api/authentication/phoneForgetCaptcha"), {
                    captcha: res.value,
                    phone: $('input[name=username]').val()
                }, res => {
                    util.countDown(this, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });
}();
