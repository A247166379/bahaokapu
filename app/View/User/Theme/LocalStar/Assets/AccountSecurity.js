/* Existing password and contact-binding endpoints, with the same verification. */
!function () {
    const action = String(getVar('buyer_security_action') || '');
    if (!['password', 'email', 'phone'].includes(action)) return;
    const api = path => Storefront.apiUrl('/user/api/security/' + path);
    $('.form-data').on('submit', event => { event.preventDefault(); $('.save-data').trigger('click'); });
    if (action === 'password') {
        $('.save-data').on('click', () => {
            util.post(api('password'), util.getFormData('.form-data'), () => {
                message.success(i18n('修改成功'));
                setTimeout(() => location.reload(), 1500);
            });
        });
        return;
    }
    $('.send-captcha').on('click', function () {
        const captcha = '/user/captcha/image?action=' + action + 'BindNew';
        message.prompt({
            title: i18n('人机验证'),
            width: Math.min(420, window.innerWidth - 32),
            html: '<img src="' + captcha + '" data-acg-refresh="' + captcha + '" class="prompt-image-code" alt="' + i18n('更换验证码') + '">',
            inputAttributes: {onpaste: 'return false', oncopy: 'return false'},
            confirmButtonText: i18n('继续操作'),
            inputValidator: value => !value && i18n('请输入验证码')
        }).then(result => {
            if (!result.isConfirmed) return;
            const data = {captcha: result.value};
            data[action] = $('input[name=' + action + ']').val();
            util.post(api(action + 'BindNew'), data, () => {
                util.countDown(this, 60);
                message.success(i18n('验证码发送成功'));
            });
        });
    });
    $('.save-data').on('click', () => {
        message.prompt({
            title: i18n('身份验证'),
            input: 'password',
            html: '<span>' + i18n('为保证账号安全，请输入登录密码以确认修改') + '</span>',
            inputAttributes: {autocomplete: 'current-password', onpaste: 'return false'},
            confirmButtonText: i18n('确认修改'),
            inputValidator: value => !value && i18n('请输入登录密码')
        }).then(result => {
            if (!result.isConfirmed) return;
            const data = util.getFormData('.form-data');
            data.password = result.value;
            util.post(api(action), data, () => {
                message.success(i18n('绑定成功'));
                setTimeout(() => location.reload(), 1500);
            });
        });
    });
}();
