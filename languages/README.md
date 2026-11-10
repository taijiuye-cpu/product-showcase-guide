# 翻译

本目录用于存放主题的翻译文件（`.mo` / `.po` / `.pot`）。

- `product-showcase-guide.pot` —— 翻译模板，由源码中的翻译函数自动提取
- `zh_CN.po` / `zh_CN.mo` —— 简体中文翻译
- 其他语言同理，文件名用语言代码（如 `ja.mo`）

## 怎么翻译

1. 用 [Poedit](https://poedit.net/) 打开 `.pot` 文件
2. 翻译后保存为 `<语言代码>.po`
3. 用 Poedit 或 `msgfmt` 编译成 `.mo`：

   ```bash
   msgfmt zh_CN.po -o zh_CN.mo
   ```

4. 把 `.po` 和 `.mo` 一起放进本目录

## 怎么提取模板

装了 WP-CLI 的话：

```bash
wp i18n make-pot . languages/product-showcase-guide.pot
```

主题的文本域（text domain）是 `product-showcase-guide`。
