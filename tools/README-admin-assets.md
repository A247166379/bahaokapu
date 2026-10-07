# 后台资源重生成

此工具仅用于开发维护，不是 public route，不应通过 HTTP 访问。生产运行不需要 Node.js、npm 或这些开发依赖。部署包可保留工具源码供维护，但不要打包 `node_modules`；站点 Nginx 应继续禁止 `/tools/` 访问。

依赖版本在 `admin-assets.package.json` 固定，完整依赖树由 `admin-assets.package-lock.json` 锁定。脚本使用常规 `require('terser')`、`require('clean-css')`、`require('acorn')`，不依赖个人目录或临时目录的绝对路径。

在站点根目录准备独立开发依赖，再校验当前产物：

```sh
BUILD_DEPS_DIR="$(mktemp -d)"
cp tools/admin-assets.package.json "$BUILD_DEPS_DIR/package.json"
cp tools/admin-assets.package-lock.json "$BUILD_DEPS_DIR/package-lock.json"
npm ci --prefix "$BUILD_DEPS_DIR" --ignore-scripts --no-audit --no-fund
NODE_PATH="$BUILD_DEPS_DIR/node_modules" node tools/build-admin-assets.cjs --check
```

`--check` 不写文件：产物逐字节相等时退出 0，有差异或错误退出非 0，同时输出三个产物的 SHA256。真正重生成时移除 `--check`；默认根目录是脚本所在 `tools` 的父目录，也支持 `--root DIRECTORY` 或 `--root=DIRECTORY`。依赖安装在其他开发目录时，相应设置 `NODE_PATH` 即可。完成后可删除独立的开发依赖目录。

生成范围固定：

- `assets/common/js/_.js`：仅替换唯一顶层 `Form` 类，来自 `assets/common/js/component/form.js`；其余第三方 bundle 字节保留。
- `assets/admin/js/_material.js`：来自 `assets/admin/js/material.js`，Terser compress/mangle，移除注释。
- `assets/common/css/_material.css`：依次合并 `md-tokens.css`、`md-components.css`，CleanCSS level 1。图标样式单独加载，不加入此 bundle。

源文件完成修改后重生成、核对差异，运行相关 UI 回归，并更新实际资源缓存版本。工具不会改模板缓存版本、部署站点、安装服务或调用业务 API。
