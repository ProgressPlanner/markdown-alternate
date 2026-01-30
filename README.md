# Markdown Alternate

A WordPress plugin that provides markdown versions of posts and pages for LLMs and users who prefer clean, structured content over HTML.

## Features

- Access any post at `/post-slug.md`
- Access any page at `/page-slug.md`
- Nested pages work: `/parent/child.md`
- Date-based permalinks supported: `/2024/01/my-post.md`
- Content negotiation: Use `Accept: text/markdown` header on any post/page URL
- Zero configuration required

## Installation

### For Users

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/markdown-alternate/`
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Visit any post or page URL with `.md` extension

### For Developers

Clone the repository and install dependencies:

```bash
git clone https://github.com/joostdevalk/markdown-alternate.git
cd markdown-alternate
composer install
```

## Usage

### Via URL Extension

Simply append `.md` to any post or page URL:

```bash
# Get a post as markdown
curl https://example.com/hello-world.md

# Get a nested page as markdown
curl https://example.com/about/team.md
```

### Via Content Negotiation

Request markdown using the `Accept` header on the original URL:

```bash
curl -H "Accept: text/markdown" https://example.com/hello-world/
```

### Markdown Output Format

```markdown
# Post Title

**Date:** January 30, 2026
**Author:** Author Name
**Featured Image:** https://example.com/image.jpg

[Post content converted to markdown]

---
**Categories:** Category A, Category B
**Tags:** tag1, tag2, tag3
```

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Pretty permalinks enabled (Settings > Permalinks - any option except "Plain")

## Development

### Project Structure

```
markdown-alternate/
├── markdown-alternate.php    # Main plugin file (bootstrap)
├── composer.json             # PSR-4 autoloading configuration
├── readme.txt                # WordPress.org plugin readme
├── README.md                 # This file
├── src/
│   └── Plugin.php            # Core plugin orchestrator
└── vendor/                   # Composer autoloader (generated)
```

### Running Tests

```bash
composer install
# Tests will be added in future versions
```

## License

GPL-2.0-or-later

See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for the full license text.

## Author

[Joost de Valk](https://joost.blog)
