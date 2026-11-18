#!/usr/bin/env bash
#
# Deploys a release to the WordPress.org plugin directory (SVN).
#
# Run it with `bin/deploy-svn.sh` from a clean checkout, after tagging the
# release (v1.0.0 for version 1.0.0). What the plugin ships must be the same at
# HEAD as at the tag. It:
#
#   1. builds the plugin with bin/release.sh,
#   2. checks out the plugin's SVN repository to a temporary folder,
#   3. puts the build in trunk/, the images in .wordpress-org/ in assets/, and
#      a copy of trunk/ in tags/<version>/,
#   4. shows what will be committed, and asks before committing.
#
# It never handles the SVN password: svn asks for it. Your username is the one
# on your WordPress.org profile (case sensitive). It is Anlino unless SVN_USERNAME
# is set in the environment.
#
# Set DRY_RUN=1 to stop after showing the changes, without asking to commit.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
root="$(pwd)"

slug="endpaper"
svn_url="https://plugins.svn.wordpress.org/${slug}"
svn_username="${SVN_USERNAME:-Anlino}"

if ! command -v svn >/dev/null 2>&1; then
	echo "svn is not installed. On a Mac: brew install subversion" >&2
	exit 1
fi

# 1. The version, the tag and the working tree must agree.
version=$(sed -n 's/^ \* Version: *//p' endpaper.php | head -n 1 | tr -d '[:space:]')

if [ -z "${version}" ]; then
	echo "Could not read the version from endpaper.php." >&2
	exit 1
fi

tag="v${version}"

if ! git rev-parse -q --verify "refs/tags/${tag}^{commit}" >/dev/null; then
	echo "The tag ${tag} does not exist. Tag the approved commit and push the tag:" >&2
	echo "  git tag ${tag} <commit> && git push origin ${tag}" >&2
	exit 1
fi

# HEAD may be later than the tag, but only in files that are not shipped.
if ! git diff --quiet "${tag}" HEAD -- endpaper.php uninstall.php readme.txt LICENSE includes assets src package.json package-lock.json; then
	echo "What the plugin ships has changed since ${tag}. Check out ${tag}, or raise the version." >&2
	exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
	echo "There are uncommitted changes to tracked files. Commit or stash them first." >&2
	exit 1
fi

echo "Deploying ${slug} ${version} (${tag}, $(git rev-parse --short HEAD))."

# 2. Build.
bin/release.sh

build="${root}/dist/${slug}"

if [ ! -f "${build}/endpaper.php" ] || [ ! -f "${build}/readme.txt" ]; then
	echo "The build in dist/${slug} is incomplete." >&2
	exit 1
fi

stable_tag=$(sed -n 's/^Stable tag: *//p' "${build}/readme.txt" | head -n 1 | tr -d '[:space:]')

if [ "${stable_tag}" != "${version}" ]; then
	echo "Stable tag in readme.txt is ${stable_tag}, not ${version}." >&2
	exit 1
fi

# 3. Check out the SVN repository, away from the plugin's own folder.
work="$(mktemp -d "${TMPDIR:-/tmp}/${slug}-svn.XXXXXX")"
echo "Checking out ${svn_url} to ${work}"
svn checkout --quiet "${svn_url}" "${work}"

if [ -d "${work}/tags/${version}" ]; then
	echo "tags/${version} already exists in SVN. A tag is never changed: raise the version instead." >&2
	exit 1
fi

mkdir -p "${work}/trunk" "${work}/assets" "${work}/tags"

# trunk: exactly the build, and nothing else.
rsync -a --delete --exclude='.svn' --exclude='.DS_Store' "${build}/" "${work}/trunk/"

# assets: the images shown on the plugin's page, from .wordpress-org.
rsync -a --delete --exclude='.svn' --exclude='.DS_Store' --exclude='.fuse_hidden*' "${root}/.wordpress-org/" "${work}/assets/"

cd "${work}"

# Tell svn about new and removed files.
svn add --force --quiet trunk assets

svn status | sed -n 's/^! *//p' | while IFS= read -r missing; do
	svn rm --quiet "${missing}"
done

# Images are served with the right type only if svn knows it.
find assets -maxdepth 1 -name '*.png' -exec svn propset --quiet svn:mime-type image/png {} +
find assets -maxdepth 1 \( -name '*.jpg' -o -name '*.jpeg' \) -exec svn propset --quiet svn:mime-type image/jpeg {} +

# The tag is a copy of trunk.
svn cp --quiet trunk "tags/${version}"

# 4. Show it, and ask.
echo
echo "About to commit to ${svn_url}:"
svn status | sed 's/^/  /' | head -n 80
echo
echo "$(svn status | wc -l | tr -d ' ') changes (the list above shows the first 80)."
echo "The checkout is in ${work} if you want to look around."

if [ "${DRY_RUN:-0}" = "1" ]; then
	echo "DRY_RUN is set, so nothing was committed."
	exit 0
fi

read -r -p "Commit ${slug} ${version} to WordPress.org as ${svn_username}? [y/N] " answer

if [ "${answer}" != "y" ] && [ "${answer}" != "Y" ]; then
	echo "Nothing was committed."
	exit 0
fi

svn commit --username "${svn_username}" -m "Release ${version}"

echo
echo "Committed. Check https://wordpress.org/plugins/${slug}/ (search results can take up to 72 hours)."
