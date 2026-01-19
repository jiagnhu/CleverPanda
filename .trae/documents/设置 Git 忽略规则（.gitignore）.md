## 项目识别
- 本仓库为 Vite + Vue 3 项目（`package.json:7-10`），构建脚本为 `vite build`（`package.json:8`）。
- Vite 默认构建输出目录为 `dist/`（本项目未在 `vite.config.ts:8-34` 中覆盖），应忽略整个目录。

## 忽略规则（.gitignore 将添加如下）
```
# 依赖与构建产物
node_modules/
dist/

# 日志与调试输出
logs/
*.log
npm-debug.log*
yarn-debug.log*
pnpm-debug.log*

# 环境变量文件
.env
.env.*
!.env.example

# 系统与编辑器生成文件
.DS_Store
Thumbs.db
.idea/
.vscode/

# 临时与缓存
*.tsbuildinfo
coverage/
*.tmp
.pnpm-store/
```

## 执行步骤
- 在仓库根目录新增 `.gitignore`，写入上述规则。
- 若已有构建产物或依赖被 Git 跟踪，执行：`git rm -r --cached dist node_modules`，并根据需要清理其它已跟踪的临时文件。
- 运行 `git status` 确认忽略规则生效；随后创建一次提交以记录规则变更。

## 验证
- 构建后 `dist/` 不再出现在 `git status`。
- 安装依赖后 `node_modules/` 不再出现在 `git status`。
- 在 macOS 上 `.DS_Store` 不再出现在 `git status`。

## 注意事项
- 保留并提交 `pnpm-lock.yaml` 以确保可重复安装。
- 不忽略 `public/` 下的资源（含音频与字体），这些属于应用运行所需的静态资产。

请确认是否按此计划添加 `.gitignore` 并清理已跟踪的构建产物与临时文件。