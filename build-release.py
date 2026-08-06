# -*- coding: utf-8 -*-
"""wp.org 发行包:只装运行时需要的文件。

git 仓库里的 action-scheduler subtree 保持上游原样(否则日后 subtree pull 会冲突),
开发树只在**打包这一步**剔除 —— wp.org 明确禁止 install.sh 这类文件进包。
"""
import os, re, zipfile

ROOT = r'C:\Users\easts\Projects\auto-ai-seo'
OUT = r'C:\Users\easts\Projects\ilang-auto-ai-seo-1.1.0.zip'
os.chdir(ROOT)

# 整个目录剔除
SKIP_DIRS = {
    '.git', '.github', '.claude', 'node_modules', '__pycache__',
    'tests',   # AS 的 PHPUnit 测试(install.sh 就在这里面,是被拒的直接原因)
    'docs',    # AS 的文档站源码
}
# 单个文件剔除
SKIP_FILES = {
    '.gitignore', '.gitattributes', '.editorconfig', 'CLAUDE.md',
    'README.md', 'README.zh-CN.md',          # GitHub 门面,不进发行包
    'Gruntfile.js', 'package.json', 'package-lock.json',
    'composer.json', 'composer.lock',        # AS 自带 autoloader,运行时不需要
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
print('剔除 %d 个开发文件,其中被 wp.org 点名的:' % len(dropped))
for d in dropped:
    if d.endswith('.sh') or 'install' in d.lower():
        print('   ', d)

# 完整性自检:AS 入口 require 的文件必须都在包里
with zipfile.ZipFile(OUT) as z:
    names = set(z.namelist())
    src = z.read('ilang-auto-ai-seo/libraries/action-scheduler/action-scheduler.php').decode('utf-8', 'ignore')
    reqs = re.findall(r"require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*'([^']+)'", src)
    print('AS 入口 require 的文件:')
    for r in reqs:
        key = 'ilang-auto-ai-seo/libraries/action-scheduler' + r
        print('   %-46s %s' % (r, '在' if key in names else '缺失!!'))
    for must in ['ilang-auto-ai-seo/ilang-auto-ai-seo.php', 'ilang-auto-ai-seo/readme.txt',
                 'ilang-auto-ai-seo/LICENSE',
                 'ilang-auto-ai-seo/libraries/action-scheduler/license.txt',
                 'ilang-auto-ai-seo/languages/ilang-auto-ai-seo-zh_CN.mo']:
        print('   %-46s %s' % (must.split('/')[-1], '在' if must in names else '缺失!!'))
    bad = [x for x in names if x.endswith('.sh') or '/tests/' in x or '/.github/' in x]
    print('残留违禁文件:', bad if bad else '无')
