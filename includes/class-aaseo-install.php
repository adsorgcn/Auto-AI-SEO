<?php
defined( 'ABSPATH' ) || exit;

/**
 * 安装/升级/停用。
 *
 * 只建一张表(用量记账)。队列不归我们管:有 Action Scheduler 就用它的表,
 * 没有就用 WordPress 自带的 cron —— 两种情况我们都不需要建表。
 * 表用 get_charset_collate() —— 托管平台上可能是 "latin1 标签装 UTF-8 字节"的惯例,
 * 跟着平台走,不要写死 utf8mb4。
 *
 * 关于卸载:**本插件不提供自动删数据的卸载例程**。插件的产出(图片 alt、页面描述)
 * 是站点的内容资产,不是插件的私有数据;见过插件在卸载时抹掉全站阅读数据的事故。
 * 想清理的用户可用 CLI 显式执行。
 */
class AASEO_Install {

	const DB_VERSION = '3';
	const DB_OPTION  = 'aaseo_db_version';

	public static function activate() {
		self::create_tables();
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function deactivate() {
		/*
		 * 停用 = 用户在喊停。**两个 hook 必须都点名清**,不能只清工作项的 hook:
		 * 入队链(aaseo_enqueue_*)挂在另一个 hook 下,只清 aaseo_run_* 会留下
		 * 一节带着合法版本号的链条 —— 插件一重新启用,它就从原游标接着扫、
		 * 接着入队、接着花钱,用户根本没按过"开始"。(评审确认的真实路径。)
		 *
		 * AS 路径上 group 就能一网打尽(工作项、链节、requeue_later 的延迟单都带 group);
		 * cron 路径**没有 group 这个概念**,只能按 hook 清 —— 所以这里把两个 hook 都交给
		 * AASEO_Queue,由它按当前驱动各清各的。
		 */
		foreach ( AASEO_Jobs::instance()->all() as $job ) {
			AASEO_Queue::cancel_group(
				array( $job->hook(), AASEO_Jobs::chain_hook( $job->slug() ) ),
				$job->group()
			);
		}
	}

	/**
	 * 升级例程。**队列迁移必须在这里做,不能指望 deactivate()。**
	 *
	 * WordPress 更新插件时走的是 deactivate_plugins( $plugin, true ) —— silent=true
	 * **不触发停用钩子**,所以 deactivate() 里那段清队列的逻辑在升级路径上根本不执行。
	 * 1.1.0 打包过 Action Scheduler,升到 1.2.0 后那批 pending 的动作就烂在 AS 的表里,
	 * 站上哪天装了 WooCommerce,AS 一初始化就会把它们全跑掉 —— 子类的 handle_one 不查
	 * 自己的完成标记,等于重复调 AI,实打实花钱。
	 *
	 * 所以这里只**留一个标记**,真正的清理交给 AASEO_Queue::purge_legacy()
	 * (升级这一刻站上多半已经没有 AS 了,现在清也够不着那张表)。
	 * $from 为空 = 全新安装,没有任何遗留,**不打标记**。
	 */
	public static function maybe_upgrade() {
		$from = (string) get_option( self::DB_OPTION );
		if ( $from === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		if ( '' !== $from && version_compare( $from, '3', '<' ) ) {
			update_option( AASEO_Queue::PURGE_OPTION, 1, false );
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$usage   = AASEO_Usage::table();

		dbDelta( "CREATE TABLE $usage (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job varchar(40) NOT NULL DEFAULT '',
			model varchar(120) NOT NULL DEFAULT '',
			prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
			completion_tokens int(10) unsigned NOT NULL DEFAULT 0,
			cost_micro bigint(20) unsigned NOT NULL DEFAULT 0,
			seconds decimal(6,2) NOT NULL DEFAULT 0,
			ok tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created (created_at),
			KEY job_model (job,model)
		) $charset;" );

		// 内链建议审核队列。pair 唯一(含已拒绝) —— 拒过的永不再提
		$links = AASEO_Links::table();
		dbDelta( "CREATE TABLE $links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			anchor text NOT NULL,
			relevance tinyint(3) unsigned NOT NULL DEFAULT 0,
			reason text NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			decided_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY pair (source_id,target_id)
		) $charset;" );
	}
}
