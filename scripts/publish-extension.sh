#!/usr/bin/env bash
#
# Publish a bundled Magento module (living in this monorepo) to its own package repo via
# `git subtree split`, so the repo root == the package root and Packagist auto-updates from
# that repo's webhook.
#
# Usage:
#   scripts/publish-extension.sh <module-path> <git-remote> [tag] [tag message]
#
# Examples:
#   # push latest committed code to the package repo's main (Packagist picks up dev-main):
#   scripts/publish-extension.sh app/code/ParkkTech/FastMagentoCheckout fmcheckout
#
#   # push main AND cut a release tag (Packagist indexes the new version):
#   scripts/publish-extension.sh app/code/ParkkTech/FastMagentoCheckout fmcheckout v1.0.0 "First stable"
#   scripts/publish-extension.sh app/code/ParkkTech/FastMagento          fastmagento  v2.3.0
#
# Notes:
#   - Publishes only what is COMMITTED in the monorepo (subtree split ignores the working tree).
#     Commit + push your monorepo changes first.
#   - The <git-remote> must already exist (git remote -v). It is the package repo, not origin.
#   - Pushes the split to the remote's `main` branch.
#   - Each package is independent: this touches only the one you name.
#
set -euo pipefail

MODULE_PATH="${1:-}"
REMOTE="${2:-}"
TAG="${3:-}"
TAG_MSG="${4:-}"

die() { echo "❌ $*" >&2; exit 1; }

[ -n "$MODULE_PATH" ] && [ -n "$REMOTE" ] \
    || die "Usage: $0 <module-path> <git-remote> [tag] [tag message]"

ROOT="$(git rev-parse --show-toplevel)" || die "not inside a git repository"
cd "$ROOT"

MODULE_PATH="${MODULE_PATH%/}"
[ -d "$MODULE_PATH" ] || die "module path not found: $MODULE_PATH"
[ -f "$MODULE_PATH/composer.json" ] || die "no composer.json in $MODULE_PATH — is it the package root?"
git remote get-url "$REMOTE" >/dev/null 2>&1 \
    || die "unknown git remote '$REMOTE' — add it: git remote add $REMOTE <url>"

# Subtree split only publishes committed content; warn on a dirty module tree.
if ! git diff --quiet -- "$MODULE_PATH" || ! git diff --cached --quiet -- "$MODULE_PATH"; then
    echo "⚠️  $MODULE_PATH has uncommitted changes — only COMMITTED content is published." >&2
    read -r -p "   Continue anyway? [y/N] " ans
    case "$ans" in y|Y) ;; *) die "aborted" ;; esac
fi

PKG="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["name"] ?? "?";' \
        "$MODULE_PATH/composer.json" 2>/dev/null || echo "?")"
SPLIT_BRANCH="_publish_split_$$"

echo "📦 Publishing ${PKG}"
echo "   module : ${MODULE_PATH}"
echo "   remote : ${REMOTE} ($(git remote get-url "$REMOTE"))"
[ -n "$TAG" ] && echo "   tag    : ${TAG}"

cleanup() { git branch -D "$SPLIT_BRANCH" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "→ splitting subtree…"
git subtree split --prefix="$MODULE_PATH" -b "$SPLIT_BRANCH" >/dev/null
SPLIT_SHA="$(git rev-parse "$SPLIT_BRANCH")"
echo "   split commit: ${SPLIT_SHA}"

echo "→ pushing to ${REMOTE}:main…"
git push "$REMOTE" "$SPLIT_BRANCH:main"

if [ -n "$TAG" ]; then
    echo "→ tagging ${TAG}…"
    git tag -a "$TAG" "$SPLIT_SHA" -m "${TAG_MSG:-$PKG $TAG}"
    git push "$REMOTE" "$TAG"
fi

echo "✅ Published ${PKG}. Packagist will auto-update from the ${REMOTE} repo webhook."
