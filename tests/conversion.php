<?php
/**
 * WordPress block conversion regressions, loaded by tests/run.php.
 *
 * @package MarkdownAlternate
 */

use MarkdownAlternate\Converter\MarkdownConverter;
use MarkdownAlternate\Output\ContentRenderer;

$md_alt_tests['Cover images and inner headings remain separate blocks'] = static function () {
	$html = '<div class="wp-block-cover"><img class="wp-block-cover__image-background" src="https://example.com/cover.jpg" alt="Cover"><div class="wp-block-cover__inner-container"><h1>Heading</h1></div></div>';
	md_alt_assert_same( "![Cover](https://example.com/cover.jpg)\n\n# Heading", ( new MarkdownConverter() )->convert( $html ) );
};

$md_alt_tests['Visible inline images retain alt text, titles and inline spacing'] = static function () {
	$html = '<p>Before <img src="https://example.com/icon.png" alt="Icon" title="A title"> after.</p><p><img src="/image.png" alt=""></p>';
	md_alt_assert_same( "Before ![Icon](https://example.com/icon.png \"A title\") after.\n\n![](/image.png)", ( new MarkdownConverter() )->convert( $html ) );
};

$md_alt_tests['Images hidden on themselves or any ancestor are omitted without removing text'] = static function () {
	$html = '<p>Keep <img aria-hidden="true" src="/hidden.png" alt="Hidden">text.</p><div aria-hidden="true">Keep this text<span><img aria-hidden="false" src="/ancestor.png" alt="Hidden by ancestor"></span></div><p><img aria-hidden="false" src="/visible.png" alt="Visible"></p>';
	md_alt_assert_same( "Keep text.\n\nKeep this text\n\n![Visible](/visible.png)", ( new MarkdownConverter() )->convert( $html ) );
};

$md_alt_tests['Data URI images are omitted regardless of case or leading whitespace'] = static function () {
	foreach ( array( 'data:image/png;base64,AAAA', ' DATA:image/svg+xml;base64,BBBB' ) as $src ) {
		md_alt_assert_same( 'Text', ( new MarkdownConverter() )->convert( '<p>Text<img src="' . $src . '" alt="Inline bytes"></p>' ) );
	}
};

$md_alt_tests['Iframe-only embed blocks retain a titled link and their caption'] = static function () {
	$html = '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper"><iframe src="https://example.com/embed/video" title="Video title"></iframe></div><figcaption>A caption</figcaption></figure>';
	// Nested block wrappers may contribute additional blank lines.
	$output = preg_replace( '/\n{3,}/', "\n\n", ( new MarkdownConverter() )->convert( $html ) );
	md_alt_assert_same( "[Video title](https://example.com/embed/video)\n\nA caption", $output );
};

$md_alt_tests['Untitled embeds keep their URL and remain separated from surrounding text'] = static function () {
	md_alt_assert_same( "Before\n\n[https://example.com/video?a=1&b=2](https://example.com/video?a=1&b=2)\n\nAfter", ( new MarkdownConverter() )->convert( '<p>Before</p><iframe src="https://example.com/video?a=1&amp;b=2"></iframe><p>After</p>' ) );
	md_alt_assert_contains( '](/local/video)', ( new MarkdownConverter() )->convert( '<iframe src="/local/video" title="Local video"></iframe>' ) );
	md_alt_assert_same( '[//example.com/video](//example.com/video)', ( new MarkdownConverter() )->convert( '<iframe src="//example.com/video"></iframe>' ) );
};

$md_alt_tests['Embed attributes cannot introduce executable links or raw HTML'] = static function () {
	$converter = new MarkdownConverter();
	foreach ( array( 'javascript:alert(1)', 'data:text/html,bad', 'file:///private/file', 'about:blank', 'vbscript:bad' ) as $src ) {
		md_alt_assert_same( '', $converter->convert( '<iframe src="' . $src . '"></iframe>' ) );
	}
	md_alt_assert_same( '', $converter->convert( '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe><script>alert(1)</script><style>bad</style>' ) );
	$output = $converter->convert( '<iframe src="https://example.com/a(b) c" title="A [title] &lt;tag&gt;"></iframe>' );
	md_alt_assert_same( '[A \\[title\\] \\<tag\\>](https://example.com/a%28b%29%20c)', $output );
};

$md_alt_tests['Image and iframe code examples remain literal code'] = static function () {
	$html   = '<pre><code>&lt;img src="data:image/png;base64,AAAA"&gt;\n&lt;iframe src="https://example.com/embed"&gt;&lt;/iframe&gt;</code></pre>';
	$output = ( new MarkdownConverter() )->convert( $html );
	md_alt_assert_contains( '<img src="data:image/png;base64,AAAA">', $output );
	md_alt_assert_contains( '<iframe src="https://example.com/embed"></iframe>', $output );
};

$md_alt_tests['Previously cached conversion output regenerates after the renderer update'] = static function () {
	$post               = md_alt_test_fixture( 'ordinary' );
	$post->post_content = '<iframe src="https://example.com/embed" title="Video"></iframe>';
	set_transient(
		'md_alt_cache_17',
		array(
			'markdown' => 'Old output without the embed',
			'modified' => $post->post_modified,
			'version'  => '',
			'schema'   => 1,
		),
		DAY_IN_SECONDS
	);
	$renderer = new ContentRenderer();
	$output   = $renderer->render( $post );
	md_alt_assert_contains( '[Video](https://example.com/embed)', $output );
	md_alt_assert_same( $output, $renderer->render( $post ) );
	md_alt_assert_same( 1, $GLOBALS['md_alt_test_state']['renders'] );
};
