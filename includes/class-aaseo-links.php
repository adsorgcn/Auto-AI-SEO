<?php
defined( 'ABSPATH' ) || exit;

/**
 * 内链建议的存储与应用。
 *
 * 建议一律进审核队列,**人不点头一个字不改** —— 这是硬性设计,不是可选项:
 * 改动正文是全插件风险最高的操作,AI 的职责止于"把判断做完给人看"
 * (锚文本、目标、相关性、理由),批准与否是人的事。
 *
 * 应用时的替换只发生在 HTML 标签之外、且不在既有 <a> 内的文本段里 ——
 * 宁可放弃(状态 stale)也不产生嵌套链接或改坏标签属性。
 */
class AASEO_Links {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aaseo_links';
	}

	/*
	 * 本表是插件私有表,WP 没有对应的高层 API;直接查询是这里的正确做法,
	 * 缓存由调用方(后台页/任务)的使用模式决定 —— 审核队列要求强一致,不缓存。
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery
	 */

	/** 同一对 source→target 只允许存在一条记录,含已拒绝的 —— 拒过的永不再提 */
	public static function pair_exists( $source_id, $target_id ) {
		global $wpdb;
		$t = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE source_id = %d AND target_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$source_id,
			$target_id
		) );
	}

	public static function add( $source_id, $target_id, $anchor, $relevance, $reason ) {
		global $wpdb;
		if ( self::pair_exists( $source_id, $target_id ) ) {
			return false;
		}
		return (bool) $wpdb->insert( self::table(), array(
			'source_id'  => (int) $source_id,
			'target_id'  => (int) $target_id,
			'anchor'     => (string) $anchor,
			'relevance'  => (int) $relevance,
			'reason'     => (string) $reason,
			'status'     => 'pending',
			'created_at' => current_time( 'mysql', true ),
		) );
	}

	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id
		) );
	}

	/** @return object[] */
	public static function pending( $limit = 100 ) {
		global $wpdb;
		$t = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE status = 'pending' ORDER BY relevance DESC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$limit
		) );
	}

	public static function counts() {
		global $wpdb;
		$t    = self::table();
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$t} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array(
			'status'     => (string) $status,
			'decided_at' => current_time( 'mysql', true ),
		), array( 'id' => (int) $id ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	/**
	 * 批准并落地一条建议。
	 *
	 * @return true|WP_Error
	 */
	public static function approve( $id ) {
		$row = self::get( $id );
		if ( ! $row || 'pending' !== $row->status ) {
			return new WP_Error( 'gone', __( 'Suggestion no longer pending.', 'auto-ai-seo' ) );
		}
		$post = get_post( (int) $row->source_id );
		$url  = get_permalink( (int) $row->target_id );
		if ( ! $post || 'publish' !== $post->post_status || ! $url ) {
			self::set_status( $id, 'stale' );
			return new WP_Error( 'stale', __( 'Source or target post is gone; suggestion marked stale.', 'auto-ai-seo' ) );
		}

		$new = self::apply_to_content( $post->post_content, (string) $row->anchor, $url );
		if ( '' === $new ) {
			// 正文已被编辑,锚文本找不到安全落点 —— 标 stale,不硬塞
			self::set_status( $id, 'stale' );
			return new WP_Error( 'no_anchor', __( 'Anchor text no longer found in the post (content changed?); suggestion marked stale.', 'auto-ai-seo' ) );
		}

		// wp_update_post 期望"已加斜杠"的数据($_POST 形态),不包 wp_slash 会吃掉反斜杠
		$res = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::set_status( $id, 'applied' );
		return true;
	}

	public static function reject( $id ) {
		$row = self::get( $id );
		if ( ! $row || 'pending' !== $row->status ) {
			return new WP_Error( 'gone', __( 'Suggestion no longer pending.', 'auto-ai-seo' ) );
		}
		self::set_status( $id, 'rejected' ); // 拒过的 pair 永不再提(pair_exists 含全部状态)
		return true;
	}

	/**
	 * 在正文中给锚文本第一处安全出现加上链接。
	 *
	 * "安全" = 在 HTML 标签之外的纯文本段里,且不在任何既有 <a>…</a> 之内。
	 * 按标签切分逐段找,找不到返回 '' —— 交由调用方标 stale,绝不硬塞。
	 *
	 * @return string 替换后的完整正文;找不到安全落点返回 ''
	 */
	public static function apply_to_content( $content, $anchor, $url ) {
		if ( '' === trim( $anchor ) ) {
			return '';
		}
		$parts   = preg_split( '/(<[^>]*>)/u', (string) $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$depth_a = 0;
		foreach ( (array) $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '/^<a[\s>]/i', $part ) ) {
					++$depth_a;
				} elseif ( preg_match( '/^<\/a\s*>/i', $part ) ) {
					$depth_a = max( 0, $depth_a - 1 );
				}
				continue;
			}
			if ( $depth_a > 0 ) {
				continue; // 已在链接里的文字不动 —— 嵌套 <a> 是非法 HTML
			}
			$pos = function_exists( 'mb_strpos' ) ? mb_strpos( $part, $anchor ) : strpos( $part, $anchor );
			if ( false === $pos ) {
				continue;
			}
			$len         = function_exists( 'mb_strlen' ) ? mb_strlen( $anchor ) : strlen( $anchor );
			$parts[ $i ] = mb_substr( $part, 0, $pos )
				. '<a href="' . esc_url( $url ) . '">' . $anchor . '</a>'
				. mb_substr( $part, $pos + $len );
			return implode( '', $parts );
		}
		return '';
	}

	/** 正文里是否已经链接了这个目标(同一目标一篇文章只链一次) */
	public static function already_links( $content, $target_id ) {
		$url = get_permalink( (int) $target_id );
		if ( $url && false !== strpos( (string) $content, $url ) ) {
			return true;
		}
		// 兜底:?p=ID / ID.html 两种历史形态
		return (bool) preg_match( '/href="[^"]*(\?p=' . (int) $target_id . '|\/' . (int) $target_id . '\.html)"/', (string) $content );
	}
}
