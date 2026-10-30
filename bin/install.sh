#!/usr/bin/env bash
# Installs or updates portalium/yii2-developer in a Portalium project whose vendor/ is copied rather than managed by
# composer. Projects that use composer do not need this script (see README.md, "Installation").
#
# Usage: bash bin/install.sh <project-root>
#
# 1. Copies the package to <project>/vendor/portalium/yii2-developer (without .git and bin; files deleted here are
#    deleted there too).
# 2. Adds the portalium\developer\ (src/) and portalium\developer\tests\ (tests/) prefixes to
#    vendor/composer/autoload_psr4.php and autoload_static.php (left alone if present). composer dump-autoload is not
#    run, because it would rebuild the autoloader from a vendor/ composer does not know.
# Safe to run again. Configuration and the migration are manual; the steps are printed at the end.
set -euo pipefail

PROJECT="${1:-}"
if [[ -z "$PROJECT" || ! -f "$PROJECT/vendor/composer/autoload_static.php" ]]; then
    echo "Usage: bash bin/install.sh <project-root>  (vendor/composer/autoload_static.php not found)" >&2
    exit 1
fi
PROJECT="$(cd "$PROJECT" && pwd)"
PACKAGE="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$PROJECT/vendor/portalium/yii2-developer"

mkdir -p "$TARGET"
rsync -a --delete --exclude='.git/' --exclude='/bin/' --exclude='.DS_Store' "$PACKAGE/" "$TARGET/"
echo "Copied: $TARGET"

python3 -I - "$PROJECT/vendor/composer" <<'PY'
import sys, pathlib

composer = pathlib.Path(sys.argv[1])
# (prefix as written in the PHP array, i.e. with doubled backslashes; directory under vendor/)
entries = [
    ("portalium\\\\developer\\\\tests\\\\", "/portalium/yii2-developer/tests"),
    ("portalium\\\\developer\\\\", "/portalium/yii2-developer/src"),
]

def length(prefix):
    return len(prefix.replace("\\\\", "\\"))

psr4 = composer / "autoload_psr4.php"
text = psr4.read_text()
marker = "return array(\n"
if marker not in text:
    sys.exit("autoload_psr4.php has an unexpected format; add the prefixes by hand.")
for prefix, path in entries:
    quoted = "'" + prefix + "'"
    if quoted not in text:
        text = text.replace(marker, marker + "    " + quoted + " => array($vendorDir . '" + path + "'),\n", 1)
        print("Updated: autoload_psr4.php " + quoted)
psr4.write_text(text)

static = composer / "autoload_static.php"
text = static.read_text()
lengths = "public static $prefixLengthsPsr4 = array (\n"
dirs = "public static $prefixDirsPsr4 = array (\n"
if lengths not in text or dirs not in text:
    sys.exit("autoload_static.php has an unexpected format; add the prefixes by hand.")
for prefix, path in entries:
    quoted = "'" + prefix + "'"
    if quoted in text:
        continue
    head, rest = text.split(lengths, 1)
    block = "        'p' => \n        array (\n"
    end = rest.index("    );\n")
    line = "            " + quoted + " => " + str(length(prefix)) + ",\n"
    if block in rest[:end]:
        rest = rest.replace(block, block + line, 1)
    else:
        rest = block + line + "        ),\n" + rest
    text = head + lengths + rest
    text = text.replace(dirs, dirs + "        " + quoted + " => \n        array (\n"
                        "            0 => __DIR__ . '/..' . '" + path + "',\n        ),\n", 1)
    print("Updated: autoload_static.php " + quoted)
static.write_text(text)
PY

cat <<EOF

Remaining steps (first installation only):
  1. web/config/main.php → 'modules':
       'developer' => ['class' => 'portalium\\developer\\Module', 'title' => 'My API'],
  2. Migrations (where PHP runs, e.g. inside the PHP container):
       php portalium migrate --migrationPath=@vendor/portalium/yii2-developer/src/migrations --interactive=0
       php portalium migrate --migrationPath=@vendor/portalium/yii2-developer/src/migrations/menu --interactive=0
  3. Mark operations public: see $TARGET/README.md
EOF
