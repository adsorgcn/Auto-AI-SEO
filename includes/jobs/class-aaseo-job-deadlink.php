<?php
defined( 'ABSPATH' ) || exit;

/**
 * 死链检测模块。机械部分负责检测,AI 只出场**判断模糊案例** ——
 * 这正是"AI 不让人做,只判断"的分工:
 *
 *   · 404/410/域名解析失败 → 机械判死,不花一分钱;
 *   · 2xx 直达 → 机械判活,同样免费;
 *   · 模糊地带才交给 AI:跳到了别家域名(域名被卖?)、深链接被重定向到
 *     首页(内容被撤的软 404?)—— 这些恰恰是人工检查最费眼的部分。
 *
 * 只报告**有把握死透的**。403/429/999 这类"拒绝机器人"一律不报 ——
 * 死链报告里混进一条误报,用户就不会再信整份报告。
 */
class AASEO_Job_Deadlink extends AASEO_Job {

	const CHECKED_KEY = '_aaseo_links_checked';
	const BROKEN_KEY  = '_aaseo_deadlinks';

	/** 每篇最多检查的外链数(超出的记录在案,下轮优先) */
	const MAX_LINKS = 15;

	/** 复查周期:链接不是检查一次就永远有效 */
	const RECHECK_DAYS = 30;

	public function slug() {
		return 'deadlink';
	}

	public function label() {
		return __( 'Broken link check', 'auto-ai-seo' );
	}

	public function description() {
		return __( 'Check outbound links; only confidently dead ones are reported — ambiguous cases are judged by AI, bot-blocking is never mistaken for death.', 'auto-ai-seo' );
	}

	public function model_kind() {
		return 'text';
	}

	/** 只有模糊案例才调 AI,均摊极低 */
	public function estimate_tokens() {
		return array( 'in' => 250, 'out' => 20 );
	}

	// ---------------------------------------------------------------- 候选

	private function post_types() {
		$types = apply_filters( 'aaseo_deadlink_post_types', array( 'post', 'page' ) );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
	}

	/** 没查过的,或上次检查已超过复查周期的 */
	private function recheck_meta_query() {
		return array(
			'relation' => 'OR',
			array( 'key' => self::CHECKED_KEY, 'compare' => 'NOT EXISTS' ),
			array(
				'key'     => self::CHECKED_KEY,
				'value'   => time() - self::RECHECK_DAYS * DAY_IN_SECONDS,
				'compare' => '<',
				'type'    => 'NUMERIC',
			),
		);
	}

	public function find_candidates( $limit, $cursor ) {
		$cursor = (int) $cursor;
		$clause = function ( $where ) use ( $cursor ) {
			global $wpdb;
			return $cursor > 0 ? $where . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $cursor ) : $where;
		};
		add_filter( 'posts_where', $clause );
		$q = new WP_Query( array(
			'post_type'              => $this->post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => (int) $limit,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => $this->recheck_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		) );
		remove_filter( 'posts_where', $clause );
		return array_map( 'intval', $q->posts );
	}

	public function count_candidates() {
		$q = new WP_Query( array(
			'post_type'      => $this->post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $this->recheck_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		) );
		return (int) $q->found_posts;
	}

	// ---------------------------------------------------------------- 处理

	/**
	 * @return true|'skipped'|WP_Error
	 */
	public function handle_one( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			AASEO_Jobs::note_skip( $this->slug(), 'gone' );
			return 'skipped';
		}

		$urls = $this->external_urls( $post->post_content );
		update_post_meta( $post->ID, self::CHECKED_KEY, time() );
		if ( ! $urls ) {
			delete_post_meta( $post->ID, self::BROKEN_KEY );
			AASEO_Jobs::note_skip( $this->slug(), 'no_links_out' );
			return 'skipped';
		}

		$broken = array();
		foreach ( array_slice( $urls, 0, self::MAX_LINKS ) as $url ) {
			$verdict = $this->check_url( $url );
			if ( 'dead' === $verdict['state'] ) {
				$broken[] = array(
					'url'     => $url,
					'why'     => $verdict['why'],
					'checked' => time(),
				);
			}
		}

		if ( $broken ) {
			update_post_meta( $post->ID, self::BROKEN_KEY, $broken );
			return true; // "done" = 发现并报告了死链
		}
		delete_post_meta( $post->ID, self::BROKEN_KEY ); // 全部健在 → 清掉旧报告
		AASEO_Jobs::note_skip( $this->slug(), 'links_ok' );
		return 'skipped';
	}

	/** 正文里的外部链接(去重;站内与短链不查 —— 那是站自己的事) */
	private function external_urls( $content ) {
		if ( ! preg_match_all( '/href=["\'](https?:\/\/[^"\']+)["\']/i', (string) $content, $m ) ) {
			return array();
		}
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out  = array();
		foreach ( array_unique( $m[1] ) as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $host && $host !== $home ) {
				$out[] = html_entity_decode( $url, ENT_QUOTES );
			}
		}
		return $out;
	}

	/**
	 * 查一条链接。
	 *
	 * @return array array( 'state' => 'alive'|'dead', 'why' => string )
	 */
	private function check_url( $url ) {
		$args = array(
			'timeout'             => 6,
			'redirection'         => 3,
			'limit_response_size' => 65536,
			'user-agent'          => 'Mozilla/5.0 (compatible; AutoAISEO-LinkCheck/' . AASEO_VERSION . '; +' . home_url( '/' ) . ')',
		);
		$res = wp_remote_head( $url, $args );
		if ( is_wp_error( $res ) || 405 === (int) wp_remote_retrieve_response_code( $res ) ) {
			// 不少服务器不理 HEAD;换 GET 再试一次才有资格下结论
			$res = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			// 域名解析失败是确定性死亡;超时/连接抖动不是 —— 下个复查周期自然再验
			if ( false !== stripos( $msg, 'resolve' ) || false !== stripos( $msg, 'getaddrinfo' ) ) {
				return array( 'state' => 'dead', 'why' => __( 'domain no longer resolves', 'auto-ai-seo' ) );
			}
			return array( 'state' => 'alive', 'why' => 'transient: ' . $msg );
		}

		$code  = (int) wp_remote_retrieve_response_code( $res );
		$final = $this->final_url( $res, $url );

		if ( in_array( $code, array( 404, 410 ), true ) ) {
			return array( 'state' => 'dead', 'why' => 'HTTP ' . $code );
		}
		if ( $code >= 400 ) {
			// 403/429/999… 拒绝的是机器人,不是读者。宁可漏报,不误报。
			return array( 'state' => 'alive', 'why' => 'bot-blocked HTTP ' . $code );
		}

		// 2xx,但要看它把读者带到了哪 —— 域名被卖和软 404 都顶着 200 的壳
		$orig_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$final_host = strtolower( (string) wp_parse_url( $final, PHP_URL_HOST ) );
		$orig_path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$final_path = (string) wp_parse_url( $final, PHP_URL_PATH );

		$cross_domain   = '' !== $final_host && ! $this->same_registrable( $orig_host, $final_host );
		$deep_to_root   = mb_strlen( trim( $orig_path, '/' ) ) > 0 && '' === trim( $final_path, '/' );
		if ( ! $cross_domain && ! $deep_to_root ) {
			return array( 'state' => 'alive', 'why' => 'HTTP ' . $code );
		}

		// 模糊地带 —— AI 只判断这一小撮
		return $this->judge( $url, $final, $res );
	}

	/** AI 判断:跳去了别家域名 / 深链接落到了首页 —— 到底是搬家还是死了 */
	private function judge( $url, $final, $res ) {
		$body  = (string) wp_remote_retrieve_body( $res );
		$title = preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $m ) ? trim( wp_strip_all_tags( $m[1] ) ) : '';

		$ai = AASEO_Client::text(
			'You judge whether an outbound link still serves its readers. Answer with a single word.',
			'Original link: ' . $url . "\n"
			. 'It now lands on: ' . $final . "\n"
			. 'Landing page title: ' . ( '' !== $title ? mb_substr( $title, 0, 150 ) : '(none)' ) . "\n\n"
			. 'DEAD means: parked/for-sale domain, generic error or "content removed" page,'
			. ' or a redirect that dumps the reader on an unrelated homepage.'
			. ' ALIVE means the content plausibly moved here (same brand, same topic).'
			. ' When in doubt, ALIVE.' . "\n"
			. 'Answer exactly one word: DEAD or ALIVE.',
			array( 'job' => $this->slug() . '-judge', 'max_tokens' => 8, 'kind' => 'text' )
		);
		if ( is_wp_error( $ai ) ) {
			return array( 'state' => 'alive', 'why' => 'judge unavailable' ); // 判定不了就不定罪
		}
		$word = strtolower( trim( (string) AASEO_Client::clean( $ai['text'] ) ) );
		if ( 0 === strpos( $word, 'dead' ) ) {
			return array(
				'state' => 'dead',
				'why'   => sprintf(
					/* translators: %s: final URL */
					__( 'AI-judged dead — now lands on %s', 'auto-ai-seo' ),
					$final
				),
			);
		}
		return array( 'state' => 'alive', 'why' => 'AI-judged moved/alive' );
	}

	/** 重定向后的最终地址(WP HTTP API 把重定向历史放进 http_response) */
	private function final_url( $res, $fallback ) {
		if ( isset( $res['http_response'] ) && is_object( $res['http_response'] ) ) {
			$obj = $res['http_response']->get_response_object();
			if ( $obj && ! empty( $obj->url ) ) {
				return (string) $obj->url;
			}
		}
		return (string) $fallback;
	}

	/** 可注册域比较:blog.example.com 与 www.example.com 算同域,搬 CDN 不算死 */
	private function same_registrable( $a, $b ) {
		$tail = function ( $h ) {
			$parts = explode( '.', $h );
			$n     = count( $parts );
			return $n >= 2 ? $parts[ $n - 2 ] . '.' . $parts[ $n - 1 ] : $h;
		};
		return $tail( $a ) === $tail( $b );
	}
}
