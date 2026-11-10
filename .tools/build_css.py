#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
构建合并 CSS 包。

用法：
    python .tools/build_css.py

做什么：
    把 assets/css/ 下的源文件按顺序拼成一个 min.css，放进 assets/css/dist/。
    压缩只做「去注释 + 合并多余空白」这种安全处理，不改选择器语义。

为什么需要它：
    主题运行时优先加载 dist 里的合并包（一个请求，比加载 4 个源文件快），
    但 dist 是构建产物 —— 改了源 CSS 必须重新跑这个脚本，否则线上还是旧的。
    主题本身有「包比源文件旧就自动退回源文件」的兜底，所以忘了跑也不会出错。

不变量：
    · 只改 assets/css/dist/ 下的文件，源文件永不改动
    · 合并顺序必须和 functions.php 里的 enqueue 顺序一致
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSS = os.path.join(ROOT, "assets", "css")
DIST = os.path.join(CSS, "dist")

# 包名 → 源文件顺序（与 functions.php 的 enqueue 顺序一致）
# 注意：style-refresh.css 是**可选换肤层**，不在包里 ——
#       需要深色时由主题单独 enqueue 它，默认走 main.css 的浅色。
BUNDLES = {
    "app.min.css": [
        "main.css",
        "web-shop.css",
    ],
    "showcase.min.css": [
        "main.css",
        "web-shop.css",
        "style-showcase.css",
    ],
    "single.min.css": [
        "main.css",
        "web-shop.css",
        "single-two-col.css",
    ],
}


def minify(text: str) -> str:
    """保守压缩：去块注释、去掉注释行、合并空白。不改语义。

    ⚠️ 不做激进压缩（不删最后一个分号、不合并选择器）——
       激进压缩器在 data: URI、attr() 之类的地方很容易改坏语义。
       这里只做「去注释 + 折叠空白 + 行尾分号」三件事。
    """
    # 去 /* */ 注释
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    # 逐行处理：去掉行首尾空白；注释行整行丢弃
    out = []
    for line in text.split("\n"):
        line = line.strip()
        if not line or line.startswith("//"):
            continue
        out.append(line)
    text = "\n".join(out)
    # 折叠行内多余空白（不动引号内的内容 —— 保守起见只压连续空格/制表符）
    text = re.sub(r"[ \t]{2,}", " ", text)
    text = re.sub(r" *([{};:,]) *", r"\1", text)
    # 去掉分号与花括号之间的空白
    text = re.sub(r"\s*;\s*}", "}", text)
    text = re.sub(r"\{ ", "{", text)
    text = re.sub(r" \}", "}", text)
    # 合并成单行（CSS 里换行没有语义）
    text = re.sub(r"\n+", "", text)
    return text.strip()


def main() -> int:
    if not os.path.isdir(DIST):
        os.makedirs(DIST)

    manifest = {}
    for bundle, sources in BUNDLES.items():
        chunks = []
        missing = []
        for src in sources:
            path = os.path.join(CSS, src)
            if not os.path.exists(path):
                missing.append(src)
                continue
            with open(path, "r", encoding="utf-8") as f:
                chunks.append(f.read())

        if missing:
            print(f"  [SKIP] {bundle}: 缺少源文件 {', '.join(missing)}")
            continue

        merged = minify("\n".join(chunks))
        out_path = os.path.join(DIST, bundle)
        with open(out_path, "w", encoding="utf-8", newline="\n") as f:
            f.write(merged)
            f.write("\n")   # 末尾换行 —— CI 会检查，缺了会红

        raw = sum(os.path.getsize(os.path.join(CSS, s)) for s in sources)
        size = os.path.getsize(out_path)
        import gzip
        gz = len(gzip.compress(merged.encode("utf-8"), 9))

        manifest[bundle] = {
            "sources": sources,
            "bytes": size,
            "gzip": gz,
            "raw_bytes": raw,
        }
        print(f"  {bundle:20s} {raw//1024:>4d}KB -> {size//1024:>4d}KB  (gzip {gz//1024}KB)")

    import json
    with open(os.path.join(DIST, "manifest.json"), "w", encoding="utf-8", newline="\n") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=1)
        f.write("\n")

    print("\n完成。dist/manifest.json 已更新。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
