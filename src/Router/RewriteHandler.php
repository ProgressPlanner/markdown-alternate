<?php
/**
 * URL rewrite handler for markdown requests.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Router;

use WP_Post;
use WP_Term;
use MarkdownAlternate\Output\ContentRenderer;
use MarkdownAlternate\Output\TermRenderer;
use MarkdownAlternate\PostTypeSupport;
use MarkdownAlternate\TaxonomySupport;

/**
 * Handles URL rewriting and markdown request processing.
 */
class RewriteHandler {

    /**
     * Cached post for markdown request (Nginx compatibility).
     *
     * @var WP_Post|null
     */
    private ?WP_Post $markdown_post = null;

	/**
	 * Cached term for a term archive markdown request.
	 *
	 * @var WP_Term|null
	 */
	private ?WP_Term $markdown_term = null;

    /**
     * Cached ContentRenderer instance.
     *
     * @var ContentRenderer|null
     */
    private ?ContentRenderer $renderer = null;

    /**
     * Register all hooks for URL routing.
     *
     * @return void
     */
    public function register(): void {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        // Parse .md URLs directly from REQUEST_URI (Nginx compatibility)
        add_action('parse_request', [$this, 'parse_markdown_url']);
        // Prevent canonical redirect for .md URLs
        add_filter('redirect_canonical', [$this, 'prevent_markdown_redirect'], 10, 2);
        // Order: format parameter -> Accept negotiation -> markdown request (URL wins first)
        add_action('template_redirect', [$this, 'handle_format_parameter'], 1);
        add_action('template_redirect', [$this, 'handle_accept_negotiation'], 1);
        add_action('template_redirect', [$this, 'handle_markdown_request'], 1);
    }

    /**
     * Add rewrite rules for .md URLs.
     *
     * @return void
     */
    public function add_rewrite_rules(): void {
        // Specific rule for index.md (front page)
        add_rewrite_rule(
            '^index\.md$',
            'index.php?pagename=index&markdown_request=1',
            'top'
        );

        // Non-greedy pattern to capture nested page slugs correctly
        add_rewrite_rule(
            '(.+?)\.md$',
            'index.php?pagename=$matches[1]&markdown_request=1',
            'top'
        );
    }

    /**
     * Prevent canonical redirect for .md URLs.
     *
     * WordPress tries to redirect to the canonical URL when the current URL
     * doesn't match. We need to prevent this for .md requests.
     *
     * @param string $redirect_url  The redirect URL.
     * @param string $requested_url The requested URL.
     * @return string|false The redirect URL or false to prevent redirect.
     */
    public function prevent_markdown_redirect($redirect_url, $requested_url) {
        if (get_query_var('markdown_request')) {
            return false;
        }
        return $redirect_url;
    }

    /**
     * Parse markdown URLs directly from REQUEST_URI.
     *
     * Provides Nginx compatibility by detecting .md URLs before WordPress
     * rewrite rules are applied. Works on servers where add_rewrite_rule()
     * doesn't function properly (Nginx, some managed hosts).
     *
     * @param \WP $wp WordPress environment instance.
     * @return void
     */
    public function parse_markdown_url(\WP $wp): void {
		// Use esc_url_raw() rather than sanitize_text_field() here: the latter
		// strips every percent-encoded octet (%[a-f0-9]{2}), which destroys
		// non-ASCII slugs (e.g. å/ä/ö, encoded as %c3%a5/%c3%a4/%c3%b6) and makes
		// the post lookup below fail. esc_url_raw() preserves the encoding.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return;
        }

        // Check if URL ends with .md (case-sensitive, lowercase only)
        if (!preg_match('/^\/(.+)\.md$/', $path, $matches)) {
            return;
        }

        $path_without_md = $matches[1];

        // Handle /index.md - could be front page or a page with slug "index"
        if ($path_without_md === 'index') {
            // First try to resolve as a regular page with slug "index"
            $clean_url = home_url('/index');
            $post_id   = url_to_postid($clean_url);

            // If no page with slug "index" exists, treat as front page
            if (!$post_id) {
                $page_on_front = get_option('page_on_front');
                if ($page_on_front) {
                    $post_id = (int) $page_on_front;
                } else {
                    // No static front page set - let WordPress show its normal behavior
                    return;
                }
            }
        } else {
            // Try to resolve the post by replacing .md with known permalink extensions,
            // then fall back to no extension (original behavior).
            $extensions_to_try = array_merge( UrlConverter::PERMALINK_EXTENSIONS, [ '' ] );
            $post_id = 0;

            foreach ($extensions_to_try as $ext) {
                $clean_url = home_url('/' . $path_without_md . $ext);
                $post_id   = url_to_postid($clean_url);

                if ($post_id) {
                    break;
                }
            }

            if (!$post_id) {
				// Not a post: try a term archive of a supported taxonomy.
				$term = $this->find_term( $path_without_md );
				if ( $term ) {
					$this->markdown_term                = $term;
					$wp->query_vars['markdown_request'] = '1';
					unset( $wp->query_vars['pagename'] );
				}
				return; // Otherwise let WordPress show its normal 404.
            }
        }

        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        if (!PostTypeSupport::is_supported($post->post_type)) {
            return;
        }

		// Noindexed and opted out: leave the request alone, like any other ineligible post.
		if ( PostTypeSupport::should_skip_noindexed( $post ) ) {
			return;
		}

        // Cache post for handle_markdown_request
        $this->markdown_post = $post;

        $wp->query_vars['p']                = $post->ID;
        $wp->query_vars['markdown_request'] = '1';
        // Remove incorrect pagename that the rewrite rule may have set
        unset($wp->query_vars['pagename']);
    }

	/**
	 * Find the term of a supported taxonomy whose archive URL is this path.
	 *
	 * The last path segment is the term slug; the match is confirmed against
	 * the term's own archive link, so nested terms, custom taxonomy bases and
	 * multilingual URLs resolve to the right term.
	 *
	 * @param string $path_without_md Request path, including any home subdirectory, without the leading slash and .md extension.
	 * @return WP_Term|null The term, or null if no supported term archive has this URL.
	 */
	private function find_term( string $path_without_md ): ?WP_Term {
		$taxonomies = TaxonomySupport::get_supported_taxonomies();
		if ( ! $taxonomies ) {
			return null;
		}
		$segments = explode( '/', trim( $path_without_md, '/' ) );
		$slug     = sanitize_title( rawurldecode( (string) end( $segments ) ) );
		if ( '' === $slug ) {
			return null;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'slug'       => $slug,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return null;
		}
		// REQUEST_URI already includes the home path on subdirectory installations.
		$requested = untrailingslashit( rawurldecode( '/' . $path_without_md ) );
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			if ( untrailingslashit( rawurldecode( (string) wp_parse_url( $link, PHP_URL_PATH ) ) ) === $requested ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Validate a term archive for markdown output.
	 *
	 * @param WP_Term $term The term to validate.
	 * @return bool True if valid for markdown output, false otherwise.
	 */
	private function validate_term_for_markdown( WP_Term $term ): bool {
		return TaxonomySupport::is_supported( $term->taxonomy ) && ! TaxonomySupport::should_skip_noindexed( $term );
	}

	/**
	 * Serve the markdown response for a term archive.
	 *
	 * @param WP_Term $term The term to serve.
	 * @return void
	 */
	private function serve_term_response( WP_Term $term ): void {
		/**
		 * Filters whether a term archive's markdown may be stored by shared caches.
		 *
		 * Disable for archives whose output depends on the current customer,
		 * such as product prices.
		 *
		 * @since 1.3.0
		 *
		 * @param bool    $enabled Whether output may be cached. Default true.
		 * @param WP_Term $term    The term being served.
		 */
		if ( ! apply_filters( 'markdown_alternate_term_cache_enabled', true, $term ) ) {
			nocache_headers();
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'Customer-dependent Markdown output' );
		}

		$markdown = ( new TermRenderer() )->render( $term );
		$link     = get_term_link( $term );

		$this->send_markdown_headers( is_wp_error( $link ) ? '' : $link, $markdown );
		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown response, like posts.
		exit;
	}

    /**
     * Add custom query vars.
     *
     * @param array $vars Existing query vars.
     * @return array Modified query vars.
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'markdown_request';
        $vars[] = 'format';
        return $vars;
    }

    /**
     * Get cached ContentRenderer instance.
     *
     * @return ContentRenderer The renderer instance.
     */
    private function get_renderer(): ContentRenderer {
        if ($this->renderer === null) {
            $this->renderer = new ContentRenderer();
        }

        return $this->renderer;
    }

    /**
     * Validate post for markdown output.
     *
     * @param WP_Post $post The post to validate.
     * @return bool True if valid for markdown output, false otherwise.
     */
    private function validate_post_for_markdown(WP_Post $post): bool {
        if (!PostTypeSupport::is_supported($post->post_type)) {
            return false;
        }

        if (get_post_status($post) !== 'publish') {
            return false;
        }

		if ( PostTypeSupport::should_skip_noindexed( $post ) ) {
			return false;
		}

        return true;
    }

    /**
     * Serve markdown response for a post.
     *
     * @param WP_Post $post The post to serve.
     * @return void
     */
    private function serve_markdown_response(WP_Post $post): void {
        if (post_password_required($post)) {
            status_header(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'This content is password protected.';
            exit;
        }

		// Keep customer-dependent output out of HTTP and WordPress page caches too.
		if ( ! apply_filters( 'markdown_alternate_cache_enabled', true, $post ) ) {
			nocache_headers();
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'Customer-dependent Markdown output' );
		}

        $markdown = $this->get_renderer()->render($post);

        $this->set_response_headers($post, $markdown);
        echo $markdown;
        exit;
    }

    /**
     * Handle format query parameter fallback.
     *
     * Serves markdown when ?format=markdown is present on singular content.
     * URL (.md) takes precedence over query parameter.
     *
     * @return void
     */
	public function handle_format_parameter(): void {
		// Skip if already a markdown request (URL takes precedence over query parameter).
		if ( get_query_var( 'markdown_request' ) ) {
			return;
		}

		if ( get_query_var( 'format' ) !== 'markdown' ) {
			return;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term && $this->validate_term_for_markdown( $term ) ) {
				$this->serve_term_response( $term );
			}
			return;
		}

		$post = null;

		if ( is_singular() ) {
			$post = get_queried_object();
		} elseif ( 'page' === get_option( 'show_on_front' ) && $this->is_front_page_format_request() ) {
			// Static front page: the extra ?format= query var can prevent
			// WordPress from recognising the request as singular, so it falls
			// back to the blog-post index.
			$page_on_front = (int) get_option( 'page_on_front' );
			if ( $page_on_front ) {
				$post = get_post( $page_on_front );
			}
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! $this->validate_post_for_markdown( $post ) ) {
			return;
		}

		$this->serve_markdown_response( $post );
	}

	/**
	 * Check whether a format request targets the static front page.
	 *
	 * The extra query variable can make the root request look like a posts
	 * index. A real posts page must not be replaced by the static front page.
	 *
	 * @return bool Whether this is the front page or its root URL fallback.
	 */
	private function is_front_page_format_request(): bool {
		if ( is_front_page() ) {
			return true;
		}

		if ( ! is_home() || is_paged() || get_query_var( 'page' ) || get_query_var( 'page_id' ) || get_query_var( 'pagename' ) ) {
			return false;
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return is_string( $request_path )
			&& rtrim( $request_path, '/' ) === rtrim( (string) $home_path, '/' );
	}

    /**
     * Handle markdown requests.
     *
     * Processes requests for .md URLs and serves markdown content.
     *
     * @return void
     */
    public function handle_markdown_request(): void {
        if (!get_query_var('markdown_request')) {
            return;
        }

		// esc_url_raw() preserves percent-encoded octets so the trailing-slash
		// redirect works for non-ASCII slugs (see parse_markdown_url()).
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        // Enforce lowercase .md extension — reject wrong case and let WordPress 404.
        if (
            !preg_match('/\.md$/', $request_uri) &&
            preg_match('/\.md$/i', $request_uri)
        ) {
            return;
        }

        // Redirect trailing slash: /slug.md/ → /slug.md
        if (str_ends_with($request_uri, '.md/')) {
            $path = wp_parse_url($request_uri, PHP_URL_PATH);
            $path = ($path !== false && $path !== null) ? rtrim($path, '/') : '';
            $query = wp_parse_url($request_uri, PHP_URL_QUERY);
            $redirect_url = home_url($path);
            if (!empty($query)) {
                $redirect_url .= '?' . $query;
            }
            wp_safe_redirect(esc_url_raw($redirect_url), 301);
            exit;
        }

		// A term archive found by parse_markdown_url().
		if ( $this->markdown_term instanceof WP_Term ) {
			if ( ! $this->validate_term_for_markdown( $this->markdown_term ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				return;
			}
			$this->serve_term_response( $this->markdown_term );
		}

        // Use cached post from parse_markdown_url (Nginx) or queried object (Apache).
        $post = $this->markdown_post ?? get_queried_object();

        if (!$post instanceof WP_Post) {
            return;
        }

		if ( ! $this->validate_post_for_markdown( $post ) ) {
			// A skipped noindexed page still resolves through the pagename rewrite
			// rule, and returning here would render its HTML template at the .md
			// URL. Send a 404 instead, the answer a skipped noindexed post gets.
			if ( PostTypeSupport::should_skip_noindexed( $post ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
			return;
		}

        $this->serve_markdown_response($post);
    }

    /**
     * Set all required HTTP headers for markdown response.
     *
     * Sets 200 status, Content-Type, Vary, Link (canonical), X-Content-Type-Options,
     * and X-Markdown-Tokens headers.
     *
     * @param WP_Post $post     The post being served.
     * @param string  $markdown The rendered markdown content.
     * @return void
     */
    private function set_response_headers(WP_Post $post, string $markdown): void {
		$this->send_markdown_headers( (string) get_permalink( $post ), $markdown );
	}

	/**
	 * Send the markdown response headers.
	 *
	 * @param string $canonical_url The HTML URL of the content.
	 * @param string $markdown      The rendered markdown content.
	 * @return void
	 */
	private function send_markdown_headers( string $canonical_url, string $markdown ): void {
        // Override any 404 status WordPress may have set.
        status_header(200);

        header('Content-Type: text/markdown; charset=UTF-8');

        // Ensures caches distinguish HTML and markdown responses for the same URL.
        header('Vary: Accept');

		if ( '' !== $canonical_url ) {
			header( 'Link: <' . $canonical_url . '>; rel="canonical"' );
		}

        // Prevent MIME sniffing.
        header('X-Content-Type-Options: nosniff');

        // Estimated token count (~4 chars per token).
        header('X-Markdown-Tokens: ' . (int) (strlen($markdown) / 4));
    }

    /**
     * Handle Accept header content negotiation.
     *
     * Redirects to .md URL when Accept: text/markdown is present on HTML URLs.
     * URL always wins over Accept header - .md URLs serve markdown regardless.
     *
     * @return void
     */
    public function handle_accept_negotiation(): void {
        // Skip if already a markdown request (URL wins over Accept header).
        if (get_query_var('markdown_request')) {
            return;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '';
        if (strpos($accept, 'text/markdown') === false) {
            return;
        }

		// Never redirect to a .md URL that would 404: serve the HTML instead.
		if ( is_singular() ) {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Post && PostTypeSupport::should_skip_noindexed( $queried ) ) {
				return;
			}
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$queried = get_queried_object();
			if ( ! $queried instanceof WP_Term || ! $this->validate_term_for_markdown( $queried ) ) {
				return;
			}
		}

        $canonical = $this->get_current_canonical_url();
        if (!$canonical) {
            return;
        }

        $md_url = UrlConverter::convert_to_markdown_url($canonical);
        $md_url = esc_url_raw($md_url);
        if ($md_url === '') {
            return;
        }

		// Prevent shared and WordPress page caches from storing this redirect.
		// Caches that correctly honour Vary can opt out of both layers:
		// Use the markdown_alternate_disable_page_cache_on_redirect filter to return false.
		if ( apply_filters( 'markdown_alternate_disable_page_cache_on_redirect', true ) ) {
			header( 'Cache-Control: private' );

			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'Content negotiation redirect' );
		}

        // 303 See Other: redirect to markdown URL with Vary for cache correctness.
        header('Vary: Accept');
        wp_safe_redirect($md_url, 303);
        exit;
    }


    /**
     * Get canonical URL for current content.
     *
     * Supports singular posts/pages, category/tag archives, author archives,
     * and date archives.
     *
     * @return string|null The canonical URL or null if not determinable.
     */
    private function get_current_canonical_url(): ?string {
        // Singular posts and pages
        if (is_singular()) {
            $post = get_queried_object();
            return $post ? get_permalink($post) : null;
        }

        // Category, tag, and custom taxonomy archives
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (!$term) {
                return null;
            }
            $link = get_term_link($term);
            return is_wp_error($link) ? null : $link;
        }

        // Author archives
        if (is_author()) {
            return get_author_posts_url(get_queried_object_id());
        }

        // Date archives
        if (is_date()) {
            if (is_year()) {
                return get_year_link(get_query_var('year'));
            }
            if (is_month()) {
                return get_month_link(get_query_var('year'), get_query_var('monthnum'));
            }
            if (is_day()) {
                return get_day_link(get_query_var('year'), get_query_var('monthnum'), get_query_var('day'));
            }
        }

        return null;
    }

}
