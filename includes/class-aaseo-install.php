<?php
defined( 'ABSPATH' ) || exit;

/**
 * 安装/升级/停用。
 *
 * 只建一张表(用量记账)。队列由 Action Scheduler 自己建表管理。
 * 表用 get_charset_collate() —— 托管平台上可能是 "latin1 标签装 UTF-8 字节"的惯例,
 * 跟着平台走,不要写死 utf8mb4。
 *
 * 关于卸载:**本插件不提供自动删数据的卸载例程**。插件的产出(图片 alt、页面描述)
 * 是站点的内容资产,不是插件的私有数据;见过插件在卸载时抹掉全站阅读数据的事故。
 * 想清理的用户可用 CLI 显式执行。
 */
class AASEO_Install {

	const DB_VERSION = '1';
	const DB_OPTION  = 'aaseo_db_version';

	public static function activate() {
		self::create_tables();
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function deactivate() {
		// 停用时清掉本插件排在 Action Scheduler 里的待执行任务,免得停用后还在后台跑
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( AASEO_Jobs::instance()->all() as $job ) {
				as_unschedule_all_actions( $job->hook() );
			}
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
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
	}
}
