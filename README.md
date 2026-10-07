# 八号卡铺

**开源数字商品自动发卡平台。** 面向个人店铺，提供商品管理、卡密发货、支付插件、订单查询和五种语言的店铺页面。

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP 8.2](https://img.shields.io/badge/PHP-8.2-blue.svg)](composer.json)
[![下载程序包](https://img.shields.io/badge/下载-安装包-orange.svg)](https://github.com/A247166379/bahaokapu/releases)

[下载安装包](https://github.com/A247166379/bahaokapu/releases) · [宝塔安装说明](安装说明.txt) · [问题反馈](https://github.com/A247166379/bahaokapu/issues)

## 关于项目

八号卡铺基于 [lizhipay/acg-faka](https://github.com/lizhipay/acg-faka) 二次开发，围绕数字商品销售调整了店铺展示、后台配置、支付管理和多语言内容管理。

本项目的开源介绍方式参考 [独角数卡](https://github.com/hiouttime/dujiaoka)。这里发布的是八号卡铺自己的源码，运行环境和安装步骤请以本仓库说明为准。

## 功能

| 模块 | 功能说明 |
| --- | --- |
| 商品管理 | 商品分类、商品图标、价格、库存、规格和下单表单 |
| 订单与发货 | 卡密自动发货、人工发货、已付款缺货订单补发、联系方式查询订单 |
| 支付管理 | 支付方式与插件分别配置；内置 USDT-TRC20，其他渠道需另行安装相应兼容插件 |
| USDT 配置 | 波场 TRON 主网收款地址、TronGrid 连接检测、自动汇率开关或手填固定汇率 |
| 多语言店铺 | 简体中文、繁体中文、英文、俄语、越南语；商品名称和店铺内容分别设置 |
| 首页内容 | 轮播图片上传、替换和删除，标题、说明、按钮及跳转链接管理 |
| 联系方式 | 微信、QQ、Telegram 和邮件入口 |
| 访问统计 | 访问趋势、访问设备、国家地区和访问来源分页切换 |

支付插件需要填写自己的商户参数或钱包配置；程序包不包含支付账户、真实卡密或业务数据库。

## 宝塔安装

推荐新站使用 Linux + 宝塔面板，选择以下环境：

| 项目 | 设置 |
| --- | --- |
| Web 服务 | Nginx |
| PHP | 8.2 |
| 数据库 | MySQL 8.0；隔离安装验证使用过 MySQL 5.7 |
| PHP 扩展 | GD（支持 WebP）、bcmath、mbstring、fileinfo、curl、pdo_mysql、zip、dom；启用 OpenSSL |
| 上传限制 | `upload_max_filesize=50M`、`post_max_size=64M` |

1. 在宝塔安装 Nginx、MySQL、PHP 8.2，并启用上述 PHP 扩展。
2. 新建网站和一个**空数据库**，在 [Releases](https://github.com/A247166379/bahaokapu/releases) 下载八号卡铺安装包，上传到网站目录并解压。
3. 网站运行目录直接包含 `index.php`、`app`、`config` 和 `vendor`。删除宝塔默认的 `index.html`。
4. 按 [安装说明.txt](安装说明.txt) 将 [nginx-site.conf.example](nginx-site.conf.example) 合入网站配置，使用实际 PHP 8.2 socket 和自己的域名。
5. 将网站文件所有者设置为 `www`，确保安装说明列出的目录可写。
6. 访问网站，按页面填写数据库信息，测试连接，再设置管理员邮箱、昵称和密码，开始安装。
7. 安装完成后访问 `/admin`，设置商品、轮播内容、联系方式和支付参数。

**完整安装包已包含依赖，无需 Composer、npm 或手动导入 SQL。** 新站安装必须使用空数据库；安装完成后保留 `kernel/Install/Lock`。具体 Nginx 配置和权限步骤见 [安装说明.txt](安装说明.txt)。

## 后台使用

- **商品管理**：填写商品资料和五种语言的名称，上传适合小图标展示的商品图片，添加库存卡密或选择人工发货。
- **首页轮播**：每条记录对应一张图片，可设置图片、文字、按钮、链接、排序和显示状态。背景图建议 1400×500，文字在后台填写，便于分别设置语言。
- **支付管理**：先配置支付插件的商户参数，再在支付方式中选择对应插件。未配置就绪的支付方式在前台置灰，不能点击。
- **USDT**：填写 TRON 主网收款地址；开启自动汇率时自动获取，关闭后填写固定汇率。使用连接检测检查 TronGrid 配置。
- **订单管理**：查看付款与发货状态。已付款但库存不足的订单保留待发货状态，可在后台填写发货内容补发。
- **联系方式**：填写客服资料；前台邮件图标使用这里保存的邮箱，通过访客的邮件客户端发信。

访客可通过联系方式查询订单。未设置查询密码的订单按联系方式提供查询和卡密读取；联系方式是查询凭据，请避免公开实际订单使用的联系方式。

## 源码与维护

```text
app/                     控制器、业务服务、工具及页面模板
assets/                  后台与前台静态资源
config/                  程序配置；公开版本保留空数据库配置
database/                数据库迁移脚本
kernel/                  程序内核、安装逻辑及新站 SQL
scripts/                 支付扫描及维护脚本
tools/                   环境检查、资源构建等维护工具
vendor/                  已随程序包提供的 PHP 依赖
index.php                网站入口
nginx-site.conf.example  宝塔 Nginx 配置示例
安装说明.txt              简洁安装步骤
LICENSE                  MIT 许可证
```

公开仓库请在**独立的干净源码副本**维护。不要从已安装的线上目录直接提交代码，提交前检查数据库配置、支付参数和文件内容，不要上传服务器凭据、真实订单、卡密、日志或用户上传资料。

`.gitignore` 排除了常见运行文件；已被 Git 跟踪的配置仍需人工检查。空目录中的 `.gitkeep` 用于保留安装所需目录。

现有安装包已在隔离环境完成网页安装、图片上传、订单补发和并发限流等检查。支付平台连接、真实付款及回调仍需站点维护者使用自己的配置验证。

## 更新与反馈

版本安装包在 [Releases](https://github.com/A247166379/bahaokapu/releases) 发布，问题和功能建议可提交到 [Issues](https://github.com/A247166379/bahaokapu/issues)。反馈请说明 PHP / MySQL 版本、复现步骤和经过脱敏的报错内容。

升级现有站点前，先备份数据库、配置和上传文件，在测试环境验证后再更新。新站安装流程不用于覆盖已有业务数据库。

## 开源许可与鸣谢

本项目使用 [MIT License](LICENSE)，保留原作者 `Copyright (c) 2021 lizhipay`。允许在遵守许可证、保留版权和许可声明的前提下使用、修改及分发。

- 原始程序：[lizhipay/acg-faka](https://github.com/lizhipay/acg-faka)。
- 开源展示参考：[hiouttime/dujiaoka](https://github.com/hiouttime/dujiaoka)。
- PHP 依赖和前端资源：各自保留其许可证，详见相应目录。
- IP 地理数据：[DB-IP IP to Country Lite](https://db-ip.com/db/download/ip-to-country-lite)，使用 CC BY 4.0；详见 [数据许可说明](app/Data/GeoIP/LICENSE.txt)。

第三方品牌和图标属于各自权利人。软件按许可证中的“按现状”条款提供。
