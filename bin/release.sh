#!/usr/bin/env bash
#
# Builds the zip for WordPress.org: dist/endpaper-<version>.zip
#
# Run it with `bin/release.sh`. It checks that the version is the same
# everywhere, makes a production build, copies only what the plugin needs to
# run (not the source, tests, tooling or screenshots) and zips it.
#
# The production build replaces build/. If `npm start` is running, stop it
# first, and start it again afterwards.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

slug="endpaper"

# 1. The version must be the same in every place it is written.
plugin_header=$(sed -n 's/^ \* Version: *//p' endpaper.php | head -n 1 | tr -d '[:space:]')
plugin_constant=$(sed -n "s/^define( 'ENDPAPER_VERSION', '\\(.*\\)' );/\\1/p" endpaper.php | head -n 1)
readme_tag=$(sed -n 's/^Stable tag: *//p' readme.txt | head -n 1 | tr -d '[:space:]')
package_version=$(node -p "require('./package.json').version")

echo "Version in endpaper.php header:   ${plugin_header}"
echo "Version in ENDPAPER_VERSION:      ${plugin_constant}"
echo "Stable tag in readme.txt:         ${readme_tag}"
echo "Version in package.json:          ${package_version}"

for found in "${plugin_constant}" "${readme_tag}" "${package_version}"; do
	if [ "${found}" != "${plugin_header}" ]; then
		echo "The versions above do not match. Fix them and run this again." >&2
		exit 1
	fi
done

version="${plugin_header}"

if ! grep -q "^= ${version} =" readme.txt; then
	echo "readme.txt has no changelog entry for ${version}." >&2
	exit 1
fi

# 2. A production build. (`npm start` leaves a development build with source
#    maps in build/, which must not be shipped.)
npm run build

if find build -name '*.map' | grep -q .; then
	echo "build/ has source maps, so it is not a production build." >&2
	exit 1
fi

# 3. Copy only what the plugin needs to run.
rm -rf dist
mkdir -p "dist/${slug}"

cp endpaper.php uninstall.php readme.txt LICENSE "dist/${slug}/"
cp -R includes assets build "dist/${slug}/"

find "dist/${slug}" -name '.DS_Store' -delete

# 4. Zip it, with the plugin folder at the top.
(cd dist && zip -qr "${slug}-${version}.zip" "${slug}")

echo
echo "Built dist/${slug}-${version}.zip"
