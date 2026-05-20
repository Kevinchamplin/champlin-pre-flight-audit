#!/usr/bin/env bash
# Build WP.org-ready variant.
# Strips PUC, renames to "WP 7.0 Compatibility Auditor" (slug: wp-7-compatibility-auditor)
# to dodge trademark scrutiny, produces a ready-to-submit zip in dist/.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
SLUG="champlin-pre-flight-audit"
NAME="Champlin Pre-Flight Audit"
OUT_DIR="$REPO_ROOT/dist"

mkdir -p "$OUT_DIR"
rm -f "$OUT_DIR/$SLUG.zip"

echo "==> Staging source in $BUILD_DIR/$SLUG"
mkdir -p "$BUILD_DIR/$SLUG"
rsync -a \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude '.DS_Store' \
  --exclude '.editorconfig' \
  --exclude '.github' \
  --exclude 'scripts/' \
  --exclude 'dist/' \
  --exclude 'vendor/plugin-update-checker/' \
  "$REPO_ROOT/" "$BUILD_DIR/$SLUG/"

echo "==> Renaming main file"
mv "$BUILD_DIR/$SLUG/wp-7-readiness-check.php" "$BUILD_DIR/$SLUG/$SLUG.php"

echo "==> Stripping PUC block + renaming plugin metadata"
python3 - <<PYEOF
import re
p = "$BUILD_DIR/$SLUG/$SLUG.php"
src = open(p).read()
pattern = re.compile(r"/\*\*\s*\n \* Auto-update from GitHub releases\..*?\n\}\s*\n", re.DOTALL)
new = pattern.sub('', src, count=1)
new = new.replace("Plugin Name:       WP 7 Readiness Check", "Plugin Name:       $NAME")
new = new.replace("'wp-7-readiness-check'", "'$SLUG'")
new = new.replace("tools_page_wp-7-readiness-check", "tools_page_$SLUG")
new = new.replace("page=wp-7-readiness-check", "page=$SLUG")
# Update the Text Domain header to match the new slug
new = new.replace("Text Domain:       wp-7-readiness-check", "Text Domain:       $SLUG")
new = new.replace(
    "Plugin URI:        https://champlinenterprises.com/wordpress-7-0-readiness-checklist.html",
    "Plugin URI:        https://champlinenterprises.com/wp-7-readiness-plugin.html"
)
open(p, "w").write(new)
print("  Main file rewritten")
PYEOF

echo "==> Updating readme.txt"
python3 - <<PYEOF
import re
p = "$BUILD_DIR/$SLUG/readme.txt"
src = open(p).read()
src = src.replace("=== WP 7 Readiness Check ===", "=== $NAME ===")
# WP.org build has no PUC — strip the v1.0.5 PUC changelog entry (replace with a generic distribution-channel note)
puc_entry_pattern = re.compile(r"= 1\.0\.5 =.*?(?== 1\.0\.4 =)", re.DOTALL)
src = puc_entry_pattern.sub(
    "= 1.0.5 =\n* Initial WordPress.org release. WordPress.org's native plugin-update channel handles updates for this distribution.\n\n",
    src
)
note = (
    "= About this distribution =\n\n"
    "This is the WordPress.org distribution of the plugin. A self-hosted variant is available at "
    "https://github.com/Kevinchamplin/wp-7-readiness-check for users who prefer to install directly from GitHub. "
    "The audit logic, autofixes, and snapshot system are identical; only the update mechanism differs.\n\n"
)
src = src.replace("= What it checks =", note + "= What it checks =")
open(p, "w").write(src)
print("  readme.txt updated (v1.0.5 PUC entry stripped)")
PYEOF

echo "==> Updating text-domain references"
find "$BUILD_DIR/$SLUG" -type f -name "*.php" -exec \
  sed -i.bak "s/'wp-7-readiness-check'/'$SLUG'/g" {} +
find "$BUILD_DIR/$SLUG" -name "*.bak" -delete

echo "==> Verifying no PUC refs remain"
if grep -rl "plugin-update-checker\|PucFactory\|YahnisElsts" "$BUILD_DIR/$SLUG" 2>/dev/null; then
    echo "FAIL: PUC references still present"
    exit 1
fi
echo "  Clean"

echo "==> PHP syntax check"
ERR=0
while IFS= read -r f; do
  if ! php -l "$f" > /dev/null 2>&1; then
    php -l "$f"
    ERR=1
  fi
done < <(find "$BUILD_DIR/$SLUG" -name "*.php")
[ $ERR -eq 0 ] && echo "  All clean"

echo "==> Zipping"
cd "$BUILD_DIR"
COPYFILE_DISABLE=1 zip -qr "$OUT_DIR/$SLUG.zip" "$SLUG"

echo ""
echo "=== BUILD COMPLETE ==="
ls -lh "$OUT_DIR/$SLUG.zip"
echo ""
unzip -l "$OUT_DIR/$SLUG.zip" | head -12
echo ""
echo "Plugin header preview:"
unzip -p "$OUT_DIR/$SLUG.zip" "$SLUG/$SLUG.php" | head -16

rm -rf "$BUILD_DIR"
