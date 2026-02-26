<?php
/**
 * Core plugin orchestrator.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate;

use MarkdownAlternate\Discovery\AlternateLinkHandler;
use MarkdownAlternate\Integration\YoastLlmsTxt;
use MarkdownAlternate\Router\RewriteHandler;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Main plugin class implementing singleton pattern.
 */
class Plugin {

    /**
     * Plugin instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Router instance.
     *
     * @var RewriteHandler
     */
    private $router;

    /**
     * Discovery handler instance.
     *
     * @var AlternateLinkHandler
     */
    private $discovery;

    /**
     * Get plugin instance.
     *
     * @return Plugin
     */
    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton.
     */
    private function __construct() {
        $this->router = new RewriteHandler();
        $this->router->register();

        $this->discovery = new AlternateLinkHandler();
        $this->discovery->register();

        PucFactory::buildUpdateChecker(
            'https://github.com/ProgressPlanner/markdown-alternate/',
            MARKDOWN_ALTERNATE_FILE,
            'markdown-alternate'
        );

        add_action( 'plugins_loaded', [ $this, 'register_integrations' ] );
    }

    /**
     * Register integrations with third-party plugins.
     *
     * @return void
     */
    public function register_integrations(): void {
        if ( defined( 'WPSEO_VERSION' ) ) {
            ( new YoastLlmsTxt() )->register();
        }
    }

    /**
     * Plugin activation callback.
     *
     * Registers rewrite rules and flushes to persist them.
     *
     * @return void
     */
    public static function activate(): void {
        $handler = new RewriteHandler();
        $handler->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation callback.
     *
     * Flushes rewrite rules to remove .md URL endpoints.
     *
     * @return void
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
