<?php
/**
 * Plugin Name: iLang Auto-AI-SEO
 * Plugin URI: https://github.com/adsorgcn/Auto-AI-SEO
 * Description: SEO that understands your content, not just matches it. AI-powered descriptions, image alt text, internal links and structured data for WordPress — in any language.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: 静水流深 (adsorgcn)
 * Author URI: https://github.com/adsorgcn
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: ilang-auto-ai-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'AASEO_VERSION', '1.2.0' );
define( 'AASEO_FILE', __FILE__ );
define( 'AASEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AASEO_URL', plugin_dir_url( __FILE__ ) );

/*
 * 这里曾经 require 一份打包进来的 Action Scheduler。1.2.0 起改成**可选依赖**,理由有两条,
 * 都很实在:
 *   · 官方 Plugin Check 对那份第三方代码报 312 个 ERROR(文本域、转义、prepare……),
 *     全不是我们写的,却全算在这个插件头上;
 *   · 它是 GPLv3,会把整个发行包一起拽成 GPLv3。
 * 现在由 AASEO_Queue 判断:站上有别的插件提供 Action Scheduler 就照旧用它,
 * 没有就用 WordPress 自带的 cron 兜底 —— 功能一条不减,只是慢一些。
 */
require_once AASEO_DIR . 'includes/class-aaseo-options.php';
require_once AASEO_DIR . 'includes/class-aaseo-queue.php';
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
require_once AASEO_DIR . 'includes/class-aaseo-schema.php';
require_once AASEO_DIR . 'includes/class-aaseo-upload.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-alt.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-meta.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-term.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-ilink.php';
require_once AASEO_DIR . 'includes/jobs/class-aaseo-job-deadlink.php';

register_activation_hook( __FILE__, array( 'AASEO_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AASEO_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'aaseo_boot' );

/*
 * 清掉 1.1.x 遗留在 Action Scheduler 表里的孤儿动作 —— 那批动作会在用户某天装上
 * WooCommerce 之后自己复活并重复调 AI(花钱)。完整来龙去脉见 AASEO_Queue::purge_legacy()。
 *
 * **优先级必须是 init:1。** 不能挂 action_scheduler_init:那个钩子在 plugins_loaded:1 就烧掉了,
 * 那时模块还没注册(注册在 aaseo_boot 里),要清的 hook 名一个都拿不到。
 * 也不能挂在 AASEO_Jobs 的 attach_handlers(init:5)之后 —— 早清一步,省得那一轮里
 * 刚好有到期的孤儿被 AS 捞走。
 */
add_action( 'init', array( 'AASEO_Queue', 'purge_legacy' ), 1 );

/*
 * 加载自带的 languages/ 翻译。
 *
 * **Plugin Check 会为这一行报 discouraged 警告,那条警告在这里不成立,别照着删。**
 * 查过核心源码:WP_Textdomain_Registry::get() 的回退只扫 WP_LANG_DIR/plugins/
 * (即 translate.wordpress.org 下发的语言包),而**插件自带的 languages/ 目录
 * 只有 load_plugin_textdomain() 会通过 set_custom_path() 登记进去**。
 * 删掉这一行,中文用户装上后就只能看英文 —— 直到社区某天翻译完为止。
 *
 * 挂 init 而非 plugins_loaded:WP 6.7 起过早加载文本域会触发 just-in-time 告警。
 */
add_action( 'init', function () {
	load_plugin_textdomain( 'ilang-auto-ai-seo', false, dirname( plugin_basename( AASEO_FILE ) ) . '/languages' );
} );

/*
 * 插件列表那一行的入口。装完插件用户的眼睛就在这里,让他去菜单里翻是多余的一步;
 * 没配 key 时把"下一步做什么"直接写在链接上。
 */
add_filter( 'plugin_action_links_' . plugin_basename( AASEO_FILE ), function ( $links ) {
	$configured = AASEO_Options::is_configured();
	$link       = sprintf(
		'<a href="%s"%s>%s</a>',
		esc_url( admin_url( 'admin.php?page=ilang-auto-ai-seo' ) ),
		$configured ? '' : ' style="font-weight:600"',
		esc_html( $configured ? __( 'Settings', 'ilang-auto-ai-seo' ) : __( 'Set up', 'ilang-auto-ai-seo' ) )
	);
	array_unshift( $links, $link );
	return $links;
} );

/*
 * 支持与反馈出口。没有出口就等于收不到反馈 —— 两个渠道各对一批人:
 * 普通用户习惯 wp.org 论坛,开发者习惯 GitHub。只放两条,不把这行做成广告位。
 */
add_filter( 'plugin_row_meta', function ( $links, $file ) {
	if ( plugin_basename( AASEO_FILE ) !== $file ) {
		return $links;
	}
	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener">%s</a>',
		esc_url( 'https://wordpress.org/support/plugin/ilang-auto-ai-seo/' ),
		esc_html__( 'Support', 'ilang-auto-ai-seo' )
	);
	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener">%s</a>',
		esc_url( aaseo_issue_url() ),
		esc_html__( 'Report an issue or suggest a feature', 'ilang-auto-ai-seo' )
	);
	return $links;
}, 10, 2 );

/**
 * GitHub 新建 issue 链接,**预填环境信息**。
 *
 * 缺版本号的 bug 报告基本没法处理,而让用户自己去翻 WP 版本、PHP 版本是在为难人 ——
 * 这些我们本来就知道,替他们填好即可。只填版本类信息:站点地址、密钥、文章内容一概不带。
 */
function aaseo_issue_url() {
	$body = sprintf(
		"\n\n---\nPlugin %s | WordPress %s | PHP %s | Locale %s",
		AASEO_VERSION,
		get_bloginfo( 'version' ),
		PHP_VERSION,
		get_locale()
	);
	return add_query_arg(
		array( 'body' => rawurlencode( $body ) ),
		'https://github.com/adsorgcn/Auto-AI-SEO/issues/new'
	);
}

function aaseo_boot() {
	AASEO_Install::maybe_upgrade();
	// 记录"这一刻是不是 Action Scheduler 在执行动作" —— 失败时抛不抛异常全看它,见 is_as_executing()
	AASEO_Queue::init();
	AASEO_Jobs::init();
	// 机械模块:不进队列,直接挂钩子(它没有候选集,也不花 token)
	AASEO_Robots::init();
	AASEO_Meta::init();
	AASEO_Schema::init();
	AASEO_Upload::init();

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
