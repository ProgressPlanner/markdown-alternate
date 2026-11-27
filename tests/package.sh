#!/usr/bin/env bash
# Verify both distribution paths contain the same installable plugin files.
# Run from a committed checkout with PHP, Git, rsync, unzip and diff available.
set -euo pipefail

package_test_dir="$(mktemp -d)"
trap 'rm -rf "$package_test_dir"' EXIT
mkdir -p "$package_test_dir/archive" "$package_test_dir/release"
git archive --format=zip HEAD > "$package_test_dir/source.zip"
unzip -q "$package_test_dir/source.zip" -d "$package_test_dir/archive"
rsync -a --exclude-from=.distignore ./ "$package_test_dir/release/"
diff -r "$package_test_dir/archive" "$package_test_dir/release"

for package in "$package_test_dir/archive" "$package_test_dir/release"; do
    for excluded in .claude .distignore .env.example .git .gitattributes .github .gitignore .planning bin logs tests composer.json composer.lock phpstan-baseline.neon phpstan.neon.dist vendor/league/html-to-markdown/.github vendor/league/html-to-markdown/composer.json vendor/league/html-to-markdown/phpstan.neon.dist vendor/yahnis-elsts/plugin-update-checker/composer.json; do
        if [[ -e "$package/$excluded" ]]; then
            echo "Unexpected development file: $excluded" >&2
            exit 1
        fi
    done
    for required in markdown-alternate.php readme.txt LICENSE languages/markdown-alternate.pot vendor/composer/installed.json vendor/composer/installed.php vendor/composer/LICENSE vendor/league/html-to-markdown/LICENSE vendor/yahnis-elsts/plugin-update-checker/license.txt vendor/yahnis-elsts/plugin-update-checker/languages/plugin-update-checker-nl_NL.mo; do
        test -f "$package/$required"
    done
    php -r '
        require $argv[1] . "/vendor/autoload.php";
        foreach (array(
            "MarkdownAlternate\\Plugin",
            "MarkdownAlternate\\Output\\ContentRenderer",
            "League\\HTMLToMarkdown\\HtmlConverter",
            "YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory",
        ) as $class) {
            if (!class_exists($class)) {
                fwrite(STDERR, "Missing runtime class: " . $class . PHP_EOL);
                exit(1);
            }
        }
        $converter = new League\HTMLToMarkdown\HtmlConverter();
        if ($converter->convert("<p>Hello <strong>world</strong>.</p>") !== "Hello **world**.") {
            exit(1);
        }
    ' "$package"
done

echo "Both archives exclude development files and retain working dependencies."
