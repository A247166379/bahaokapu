!function () {
    util.bindButtonUpload(".avatar-input", Storefront.apiUrl("/user/api/upload/send?mime=image"), result => {
        $('input[name=avatar]').val(result.url);
        $('.avatar-img').attr("src", result.url);
        message.success(i18n("上传完成，需要保存才会生效哦"));
    });
    $('.save-data').click(function () {
        util.post(Storefront.apiUrl("/user/api/security/personal"), util.getFormData('.form-data'), () => {
            message.success(i18n("已生效"));
        });
    });
}();
