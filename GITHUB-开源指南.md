# GitHub 开源发布完全指南（新手向）

这份文档记录了本项目从「本地代码」到「GitHub 上可公开下载」的全过程。
如果你以后还要开源别的项目，照着做一遍就会了。

---

## 一、开始之前要搞懂的 5 件事

| 概念 | 大白话 |
|---|---|
| **仓库（Repository）** | 一个项目的全部文件 + 版本历史的容器。有 Public（所有人可见）和 Private（仅自己可见）两种。 |
| **分支（Branch）** | 项目的平行版本线。`main` 是正式版，平时开发用别的分支，改完再合并回去。 |
| **提交（Commit）** | 一次存档。写清楚"改了什么"，以后能回溯。 |
| **标签（Tag）** | 给某个提交打一个版本标记，比如 `v1.0.0`。发版用。 |
| **Release（发行版）** | 基于 Tag 打包的可下载文件。别人下载你的主题，走的就是这里。 |

---

## 二、本项目实际做了哪些事

### 1. 整理代码（去掉不该公开的东西）

一个能给别人用的项目，得先"打扫干净"：

- **删掉不该有的功能** —— 本项目删掉了支付、会员、订单、优惠券、物流、小程序后端
  （不是怕别人用，是**基础版本来就不该有这些**，也不含任何密钥）
- **删掉私人信息** —— 服务器密码、个人域名、真实图床链接、第三方品牌商标
- **换成中性占位** —— `style.css` 里的 `example.com`、`你的名字` 换成真实值
- **加兼容层** —— 删掉的功能在模板里有调用点，用 `inc/core-compat.php` 提供
  "功能关闭状态"的等价实现，保证前台不出错

### 2. 补齐开源必备文件

一个规范的 WordPress 主题仓库应该有这些（缺了不影响运行，但影响专业度和可信度）：

```
├── LICENSE              许可证（GPL v2，WordPress 强制要求）
├── README.md            项目说明（最重要，别人第一眼看这个）
├── CHANGELOG.md         更新日志
├── CONTRIBUTING.md      贡献指南（别人怎么提 PR）
├── CODE_OF_CONDUCT.md   行为准则
├── SECURITY.md          安全漏洞怎么报告
├── .gitignore           哪些文件不要提交
├── .gitattributes       换行符规则
├── readme.txt           WordPress.org 格式的说明（以后上主题目录要用）
├── screenshot.png       主题截图（1200×900，4:3）
├── doc/images/          README 里用的配图
├── languages/           翻译目录
└── .github/             Issue/PR 模板 + 自动检查工作流
```

### 3. 初始化 Git 仓库并提交

```bash
cd product-showcase-guide
git init -b main          # 初始化，分支叫 main
git config user.name "你的名字"
git config user.email "你的邮箱"
git add -A                # 把所有文件放进暂存区
git commit -m "提交说明"   # 存档
```

### 4. 推到 GitHub

```bash
git remote add origin https://github.com/你的用户名/仓库名.git
git push -u origin main
```

### 5. 打版本标签并发布

```bash
git tag -a v1.0.0 -m "v1.0.0 首个版本"
git push origin v1.0.0
```

然后到 GitHub 网页 → **Releases** → **Draft a new release** → 选 tag → 写说明
→ 传 `product-showcase-guide-1.0.0.zip` → **Publish release**。

本项目用 `.github/workflows/release.yml` 自动做了后两步：
推 tag 之后，GitHub 自动检查版本号一致性、自动打包 zip、自动发布 Release。

---

## 三、常见问题

### Q：Git 提示 Authentication failed？

GitHub 已不支持密码推送。要么用 **Personal Access Token**（当作密码用），
要么用 SSH 密钥。推荐 SSH：

```bash
ssh-keygen -t ed25519 -C "你的邮箱"     # 生成密钥
cat ~/.ssh/id_ed25519.pub                # 复制公钥内容
```

然后到 GitHub → **Settings → SSH and GPG keys** → **New SSH key** → 粘贴公钥。

之后 `git remote set-url origin git@github.com:你的用户名/仓库名.git`。

### Q：怎么授权我（AI）帮你推送？

两种方式：

1. **Device Flow（推荐，最安全）**
   浏览器打开 `https://github.com/login/device`，输入一次性代码，
   点 Authorize。授权只针对这一个操作，token 不落在你机器上。

2. **Personal Access Token**
   GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens
   → 勾选 `repo` 权限 → 生成。
   ⚠️ token 等同于密码，别发到公开场合。

### Q：为什么我的代码里没有 `.git` 也能用？

`.git` 目录是 Git 的"账本"，只存在于你的开发机上，**不参与分发**。
别人下载 zip 时拿不到它 —— 这是好事，能防止别人直接改你的历史。

### Q：以后更新了主题，怎么发新版？

```bash
# 1. 改代码
# 2. 同步版本号（style.css 里的 Version 要和 tag 一致）
sed -i 's/^Version: .*/Version: 1.0.1/' style.css

git add -A
git commit -m "fix: 修复某个问题"
git tag -a v1.0.1 -m "v1.0.1"
git push origin main --tags
```

推完 tag，GitHub Actions 会自动打包并发布 Release。

### Q：文件多大能推到 GitHub？

单文件 100MB 以内、整个仓库建议 1GB 以内。本项目 2.6MB，很轻松。
图片多的话用 Git LFS。

### Q：要不要写 .gitignore？

要。至少忽略这些：

```
wp-config.php        # 绝对不能提交！
*.sql                # 数据库导出
node_modules/
vendor/
.env
.DS_Store
```

---

## 四、给使用者的建议（写 README 时）

一个好的 README 让人在 30 秒内决定"要不要用"：

1. **第一屏说清楚这是什么** —— 一句话 + 一张截图
2. **列出功能** —— 用表格，别用长段落
3. **明确写出不包含什么** —— 比只说"有什么"更诚实，也避免纠纷
4. **给安装步骤** —— 编号、简短
5. **给目录结构** —— 别人能快速定位文件
6. **FAQ** —— 把最常见的问题列出来
7. **免责声明** —— 尤其涉及商品、医疗、金融、成人内容的项目

---

## 五、涉及成人内容项目的特别提醒

本项目是"商品展示与导购"工具，演示数据已全部改成虚构内容。

如果你以后要开源成人/情趣用品相关的主题，注意：

- **不要用真实品牌名** —— 那是别人的商标
- **不要写真实功效宣称** —— "65% 用户反馈有效"这类是虚假宣传，广告法有风险
- **演示数据用虚构内容** —— 品类结构保留，商品名/描述换成中性示例
- **在 README 里写明** "演示数据为虚构内容，不对应任何真实商品"
- **仓库描述保持中性** —— "商品导购主题"比"情趣用品商城"更利于传播，
  也降低被误判的风险
- **GitHub 内容政策** 允许软件项目本身，主题定位写在文档里即可；
  风险点在于**图片和文案**，不在代码

---

## 六、本项目的最终状态

| 项 | 值 |
|---|---|
| 仓库 | https://github.com/taijiuye-cpu/product-showcase-guide |
| Release 下载 | https://github.com/taijiuye-cpu/product-showcase-guide/releases/tag/v1.0.0 |
| 许可证 | GPL v2 or later（GitHub 识别为 `GPL-2.0`） |
| 版本 | 1.0.0 |
| 仓库文件数 | 91（含 .github / doc / 说明文档） |
| 安装包文件数 | 72（38 个 PHP），zip 约 700KB |
| 主题目录名 | `product-showcase-guide` |
| 文本域（Text Domain） | `product-showcase-guide` |
| PHP 要求 | 7.4+ |
| WordPress 要求 | 5.9+（已测 7.1） |
| CI | PHP 语法检查（7.4 / 8.3 矩阵）+ 换行检查 + 发版自动打包 zip |

### 已配置的自动流程

| 触发 | 工作流 | 做什么 |
|---|---|---|
| push / PR 到 main | `php-lint.yml` | 逐个 PHP 文件语法检查 + 文本文件末尾换行检查 |
| 推 `v*` tag | `release.yml` | 校验 tag 与 style.css 版本号一致 → 打包带主题目录前缀的 zip → 自动发布 Release |
| 手动触发 / 改动 workflow | `repo-setup.yml` | 设置仓库描述与 topics、开启 secret scanning |

### 本次发布踩过的三个坑（供以后参考）

1. **Device Flow 拿到的 token 没有仓库写权限**
   GitHub 把返回的 token 降级成了 `ghu_` 前缀的 GitHub App user-to-server token，
   它的写权限由 App 的 installation 决定，加 OAuth scope 无效。
   → **解法：用 SSH 公钥推送**（走账号权限，最直接，也最省事）。

2. **workflow 里 `permissions: metadata: write` 会让整个 workflow 校验失败**
   `metadata` 不是 `permissions` 键的有效 scope（有效的是 contents / issues /
   pull-requests / actions / security-events 等）。
   症状很隐蔽：workflow 显示 failure，但 job 列表是空的 —— 因为它根本没进到执行阶段。
   → **解法：只写有效 scope**；查证入口是
   [Workflow syntax → permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions)。

3. **`zip -r xxx.zip .` 打出来的包 WordPress 装不上**
   WordPress 后台上传主题要求 zip 内**第一层就是主题目录**，
   `zip -r ... .` 却是把当前目录内容直接打进去，没有前缀目录。
   → **解法：用 `git archive --prefix=主题名/ --format=zip`**，结构天然正确，
   并且可以用 `':(exclude)路径'` 精确排除仓库专属文件。
