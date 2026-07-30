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
		global $wpdb;
		/*
		 * 原子认领:两个管理员同时点批准,只有一个 UPDATE 能命中 pending ——
		 * 输的一方得到"已不在待审",而不是同一条链接被塞进正文两次。
		 */
		$claimed = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE {$wpdb->prefix}aaseo_links SET status = 'applying' WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id
		) );
		if ( 1 !== (int) $claimed ) {
			return new WP_Error( 'gone', __( 'Suggestion no longer pending.', 'auto-ai-seo' ) );
		}
		$row  = self::get( $id );
		$post = $row ? get_post( (int) $row->source_id ) : null;
		$url  = $row ? get_permalink( (int) $row->target_id ) : '';

		// 目标必须还发布着 —— 给读者一个 404 链接比不加链接糟糕得多
		if ( ! $post || 'publish' !== $post->post_status || ! $url
			|| 'publish' !== get_post_status( (int) $row->target_id ) ) {
			self::set_status( $id, 'stale' );
			return new WP_Error( 'stale', __( 'Source or target post is gone; suggestion marked stale.', 'auto-ai-seo' ) );
		}
		// 建议入队后正文可能被人手动加过同目标的链接 —— 同一目标一篇只链一次
		if ( self::already_links( $post->post_content, (int) $row->target_id ) ) {
			self::set_status( $id, 'stale' );
			return new WP_Error( 'dupe', __( 'Post already links to this target; suggestion marked stale.', 'auto-ai-seo' ) );
		}

		$new = self::apply_to_content( $post->post_content, (string) $row->anchor, $url );
		if ( '' === $new ) {
			// 正文已被编辑,锚文本找不到安全落点 —— 标 stale,不硬塞
			self::set_status( $id, 'stale' );
			return new WP_Error( 'no_anchor', __( 'Anchor text no longer found in the post (content changed?); suggestion marked stale.', 'auto-ai-seo' ) );
		}

		/*
		 * kses 连坐守卫:wp_update_post 会让**整篇**正文重新过一遍 kses ——
		 * 批准者没有 unfiltered_html(DISALLOW_UNFILTERED_HTML 加固、多站点的普通管理员)时,
		 * 正文里原有的 iframe/script(别人当年合法存进去的)会被静默剥掉。
		 * "只改一个链接"的承诺不能靠运气:kses 会动到链接以外的任何东西就中止。
		 */
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$would_be = wp_kses_post( $new );
			$expected = self::apply_to_content( wp_kses_post( $post->post_content ), (string) $row->anchor, $url );
			if ( '' === $expected || $would_be !== $expected ) {
				self::set_status( $id, 'pending' ); // 退回待审,让有权限的人来批
				return new WP_Error( 'kses', __( 'This post contains markup (e.g. iframes) that your account cannot re-save; approving would strip it. Ask an administrator with unfiltered_html to approve this one.', 'auto-ai-seo' ) );
			}
		}

		// wp_update_post 期望"已加斜杠"的数据($_POST 形态),不包 wp_slash 会吃掉反斜杠
		$res = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ), true );
		if ( is_wp_error( $res ) ) {
			self::set_status( $id, 'pending' ); // 写库失败退回待审,不吞建议
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
		$anchor = trim( (string) $anchor );
		if ( '' === $anchor ) {
			return '';
		}
		/*
		 * 匹配模式:空白一律容错为 \s+、& 容错为 &amp; —— 锚文本摘自"渲染后的可读文本",
		 * 原文里可能是换行或实体。除这两类外逐字精确,绝不模糊。
		 */
		$tokens  = preg_split( '/\s+/u', $anchor );
		$pattern = implode( '\s+', array_map( 'preg_quote', $tokens ) );
		$pattern = str_replace( '&', '(?:&|&amp;)', $pattern );
		$pattern = '/' . str_replace( '/', '\/', $pattern ) . '/u';

		/*
		 * 全程字节寻址(strpos/strlen/substr):UTF-8 的精确子串拼接按字节做是安全的,
		 * 而 mb_* 与字节函数混用在无 mbstring 的主机上会字符/字节错位,直接改坏正文
		 * (WP 只填补 mb_substr/mb_strlen,不填补 mb_strpos —— 混用必错位)。
		 */
		$parts    = preg_split( '/(<[^>]*>)/u', (string) $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$depth_a  = 0;
		$in_dead  = ''; // 当前所在的不可落链元素(script/style/textarea/noscript)
		foreach ( (array) $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( '' !== $in_dead ) {
					if ( preg_match( '/^<\/' . $in_dead . '\s*>/i', $part ) ) {
						$in_dead = '';
					}
					continue;
				}
				if ( preg_match( '/^<(script|style|textarea|noscript)\b/i', $part, $dm ) ) {
					$in_dead = strtolower( $dm[1] );
					continue;
				}
				if ( preg_match( '/^<a[\s>]/i', $part ) ) {
					++$depth_a;
				} elseif ( preg_match( '/^<\/a\s*>/i', $part ) ) {
					$depth_a = max( 0, $depth_a - 1 );
				}
				continue;
			}
			/*
			 * script/style/textarea 的**内容**在这个切分下也是"文本段",但它们不是给读者看的
			 * 文字 —— JSON-LD 里恰好复述了正文句子时,链接绝不能插进 JSON 里。
			 * 审核界面给人看的上下文来自 wp_strip_all_tags(它会连 script 体一起剥掉),
			 * 落地位置必须与人审的位置一致,否则人工审核就被架空了。
			 */
			if ( $depth_a > 0 || '' !== $in_dead ) {
				continue;
			}
			if ( ! preg_match( $pattern, $part, $hm, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$pos          = (int) $hm[0][1]; // 字节偏移(PREG_OFFSET_CAPTURE 一律按字节)
			$hit          = $hm[0][0];       // 命中的原文形态(保留原有换行/实体)
			$parts[ $i ]  = substr( $part, 0, $pos )
				. '<a href="' . esc_url( $url ) . '">' . $hit . '</a>'
				. substr( $part, $pos + strlen( $hit ) );
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
