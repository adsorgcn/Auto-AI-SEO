<?php
/**
 * Plugin Name: Auto-AI-SEO
 * Plugin URI: https://github.com/adsorgcn/Auto-AI-SEO
 * Description: SEO that understands your content, not just matches it. AI-powered descriptions, image alt text, internal links and structured data for WordPress — in any language.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: 静水流深 (adsorgcn)
 * Author URI: https://github.com/adsorgcn
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: auto-ai-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'AASEO_VERSION', '0.1.0' );
define( 'AASEO_FILE', __FILE__ );
define( 'AASEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AASEO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Action Scheduler —— 后台任务队列(WooCommerce/Automattic 出品,git subtree 打包)。
 *
 * 必须在 plugins_loaded priority 0 之前 include(官方要求),所以放在这里直接 require。
 * 它自己会在 plugins_loaded:0 注册版本、在 init:1 初始化;若站上有多个插件各自打包了
 * 不同版本,它会挑最新的加载 —— 因此我们所有 as_* 调用都要 function_exists() 防御。
 */
require_once AASEO_DIR . 'libraries/action-scheduler/action-scheduler.php';

require_once AASEO_DIR . 'includes/class-aaseo-options.php';
require_once AASEO_DIR . 'includes/class-aaseo-install.php';
require_once AASEO_DIR . 'includes/class-aaseo-usage.php';
require_once AASEO_DIR . 'includes/class-aaseo-client.php';
require_once AASEO_DIR . 'includes/class-aaseo-probe.php';
require_once AASEO_DIR . 'includes/class-aaseo-image.php';
require_once AASEO_DIR . 'includes/abstract-aaseo-job.php';
require_once AASEO_DIR . 'includes/class-aaseo-jobs.php';
require_once AASEO_DIR . 'includes/class-aaseo-robots.php';
require_once AASEO_DIR . 'includes/class-aaseo-meta.php';
require_once AASEO_DIR . 'includes/class-aaseo-links.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-alt.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-meta.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-term.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-ilink.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-deadlink.php';

register_activation_hook( __FILE__, array( 'AASEO_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AASEO_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'aaseo_boot' );

function aaseo_boot() {
	// 不调 load_plugin_textdomain():WP 4.6+ 对 wp.org 托管的插件会自动加载翻译。
	AASEO_Install::maybe_upgrade();
	AASEO_Jobs::init();
	// 机械模块:不进队列,直接挂钩子(它没有候选集,也不花 token)
	AASEO_Robots::init();
	AASEO_Meta::init();

	if ( is_admin() ) {
		require_once AASEO_DIR . 'admin/class-aaseo-admin.php';
		AASEO_Admin::init();
	}

	/**
	 * 功能模块在此注册。每个模块继承 AASEO_Job,只需实现"找出待处理的对象"与"处理一条";
	 * 排队、断点续跑、失败重试、配额闸门、用量记账由骨架统一提供。
	 *
	 * @param AASEO_Jobs $registry
	 */
	do_action( 'aaseo_register_jobs', AASEO_Jobs::instance() );
}

add_action( 'aaseo_register_jobs', 'aaseo_register_builtin_jobs' );

function aaseo_register_builtin_jobs( $registry ) {
	$registry->register( new AASEO_Job_Alt() );
	$registry->register( new AASEO_Job_Meta() );
	$registry->register( new AASEO_Job_Term() );
	$registry->register( new AASEO_Job_Ilink() );
	$registry->register( new AASEO_Job_Deadlink() );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AASEO_DIR . 'includes/class-aaseo-cli.php';
	WP_CLI::add_command( 'aaseo', 'AASEO_CLI' );
}
