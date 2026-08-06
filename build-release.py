# -*- coding: utf-8 -*-
"""wp.org 发行包:只装运行时需要的文件。

1.2.0 起插件不再打包 Action Scheduler(见 includes/class-aaseo-queue.php 的说明),
所以这个脚本也不再需要为它剔 tests/ docs/ install.sh 那一堆东西 —— 留着 SKIP 规则
只是为了以后万一再引入什么第三方目录时不用重写。
"""
import os, re, zipfile

ROOT = r'C:\Users\easts\Projects\auto-ai-seo'
VERSION = '1.2.0'
OUT = r'C:\Users\easts\Projects\ilang-auto-ai-seo-%s.zip' % VERSION
os.chdir(ROOT)

# 整个目录剔除
SKIP_DIRS = {
    '.git', '.github', '.claude', 'node_modules', '__pycache__',
    'tests', 'docs',
}
# 单个文件剔除
SKIP_FILES = {
    '.gitignore', '.gitattributes', '.editorconfig', 'CLAUDE.md',
    'README.md', 'README.zh-CN.md',          # GitHub 门面,不进发行包
    'build-release.py',                      # 打包脚本自己不进包
    'Gruntfile.js', 'package.json', 'package-lock.json',
    'composer.json', 'composer.lock',
    'phpcs.xml', 'codecov.yml',
}
SKIP_EXT = ('.zip', '.log', '.dist', '.sh', '.yml', '.yaml')
# 即使后缀命中也必须保留的(许可与出处,合规要用)
KEEP = {'license.txt', 'changelog.txt', 'readme.txt'}

n, dropped = 0, []
with zipfile.ZipFile(OUT, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk('.'):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for f in files:
            p = os.path.join(root, f)
            rel = os.path.relpath(p, '.').replace(os.sep, '/')
            if f in KEEP:
                pass
            elif f in SKIP_FILES or f.endswith(SKIP_EXT):
                dropped.append(rel)
                continue
            z.write(p, 'ilang-auto-ai-seo/' + rel)
            n += 1

print('打包 %d 个文件 -> %.1f KB' % (n, os.path.getsize(OUT) / 1024))
print('剔除 %d 个开发文件' % len(dropped))

# 完整性自检
with zipfile.ZipFile(OUT) as z:
    names = set(z.namelist())
    for must in ['ilang-auto-ai-seo/ilang-auto-ai-seo.php',
                 'ilang-auto-ai-seo/readme.txt',
                 'ilang-auto-ai-seo/LICENSE',
                 'ilang-auto-ai-seo/includes/class-aaseo-queue.php',
                 'ilang-auto-ai-seo/languages/ilang-auto-ai-seo-zh_CN.mo']:
        print('   %-46s %s' % (must.split('/')[-1], '在' if must in names else '缺失!!'))
    # 主文件里 require 的 includes/ 文件必须都在包里
    src = z.read('ilang-auto-ai-seo/ilang-auto-ai-seo.php').decode('utf-8', 'ignore')
    for r in re.findall(r"require(?:_once)?\s+AASEO_DIR\s*\.\s*'([^']+)'", src):
        key = 'ilang-auto-ai-seo/' + r
        if key not in names:
            print('   require 的文件缺失!!', r)
    # 版本号三处必须一致
    head = re.search(r'Version:\s*([0-9.]+)', src).group(1)
    const = re.search(r"AASEO_VERSION',\s*'([0-9.]+)'", src).group(1)
    stable = re.search(r'Stable tag:\s*([0-9.]+)',
                       z.read('ilang-auto-ai-seo/readme.txt').decode('utf-8', 'ignore')).group(1)
    print('   版本号 header=%s const=%s stable=%s %s'
          % (head, const, stable, '一致' if head == const == stable == VERSION else '不一致!!'))
    # libraries/ 必须为空手起飞:1.2.0 起不再打包 Action Scheduler,那是 MIT 发行包成立的前提。
    # 谁哪天把这个目录恢复回来,这里必须叫出来 —— 否则 33421 行 GPLv3 代码会被安静地打进
    # 一个自称 MIT 的包里,而且全部自检照样通过。
    bad = [x for x in names
           if x.endswith('.sh') or '/tests/' in x or '/.github/' in x or '/libraries/' in x]
    print('残留违禁文件:', bad if bad else '无')
