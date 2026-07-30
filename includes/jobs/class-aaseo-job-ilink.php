<?php
defined( 'ABSPATH' ) || exit;

/**
 * 长尾内链建议模块。**只建议,不落地** —— 落地永远走人工审核(AASEO_Links::approve)。
 *
 * 与关键词内链插件的本质区别(这是本产品的最大差异点):
 * 锚文本是**文章里现成的描述性长尾短语**,不是塞进去的关键词。
 * AI 的任务是"在正文里找到那句本来就该是链接的话",逐字摘出 ——
 * 所以锚文本必须能在正文中原样找到,发明出来的一律丢弃。
 *
 * 克制的三道闸:
 *   · 相关性 1-5 分,**≥4 才入队**(边缘相关不值得打断读者);
 *   · 同一 source→target 只存在一条记录,被拒过的永不再提;
 *   · 每篇最多建议 3 条 —— 不设"每 N 字一链"这种伪科学配额。
 */
class AASEO_Job_Ilink extends AASEO_Job {

	const DONE_KEY = '_aaseo_ilink_done';

	public function slug() {
		return 'ilink';
	}

	public function label() {
		return __( 'Internal link suggestions', 'auto-ai-seo' );
	}

	public function description() {
		return __( 'Find long-tail phrases in each post that should link to another post. Suggestions wait for your review — nothing is applied automatically.', 'auto-ai-seo' );
	}

	public function model_kind() {
		return 'text';
	}

	/** 整文 + 20 个候选目标的清单 */
	public function estimate_tokens() {
		return array( 'in' => 3500, 'out' => 150 );
	}

	// ---------------------------------------------------------------- 候选

	/** 只处理文章:页面(关于/联系)不需要内链建议 */
	private function post_types() {
		$types = apply_filters( 'aaseo_ilink_post_types', array( 'post' ) );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
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
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => self::DONE_KEY, 'compare' => 'NOT EXISTS' ),
			),
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
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => self::DONE_KEY, 'compare' => 'NOT EXISTS' ),
			),
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

		$text = $this->readable_text( $post );
		if ( mb_strlen( $text ) < 300 ) {
			// 短文没有值得当锚文本的长尾短语,也没有打断读者的余地
			update_post_meta( $post->ID, self::DONE_KEY, 'short:' . time() );
			AASEO_Jobs::note_skip( $this->slug(), 'no_content' );
			return 'skipped';
		}

		$targets = $this->shortlist( $post );
		if ( ! $targets ) {
			update_post_meta( $post->ID, self::DONE_KEY, 'no_targets:' . time() );
			AASEO_Jobs::note_skip( $this->slug(), 'no_targets' );
			return 'skipped';
		}

		$res = AASEO_Client::text(
			'You suggest internal links for a blog. Respond with a JSON array only — no code fences, no commentary.',
			$this->prompt( $post, $text, $targets ),
			array( 'job' => $this->slug(), 'max_tokens' => 500, 'kind' => 'text' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$added = $this->store_suggestions( $post, $res['text'], $targets );
		update_post_meta( $post->ID, self::DONE_KEY, 'done:' . time() . ':' . $added );
		if ( 0 === $added ) {
			AASEO_Jobs::note_skip( $this->slug(), 'no_links' );
			return 'skipped'; // 没有 ≥4 分的建议 —— 这是合格输出,不是失败
		}
		return true;
	}

	// ---------------------------------------------------------------- 预筛

	/**
	 * 机械预筛候选目标:共享 分类/标签 数排序,取前 20。
	 * 不把全站文章都塞给 AI —— 那是拿 token 换懒惰;预筛是免费的。
	 */
	private function shortlist( $post ) {
		$term_ids = wp_get_object_terms( $post->ID, array( 'category', 'post_tag' ), array( 'fields' => 'ids' ) );
		if ( is_wp_error( $term_ids ) || ! $term_ids ) {
			return array();
		}
		$q = new WP_Query( array(
			'post_type'              => $this->post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => 60,
			'post__not_in'           => array( $post->ID ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'relation' => 'OR',
				array( 'taxonomy' => 'category', 'terms' => $term_ids ),
				array( 'taxonomy' => 'post_tag', 'terms' => $term_ids ),
			),
		) );

		$mine  = array_fill_keys( array_map( 'intval', $term_ids ), true );
		$score = array();
		foreach ( $q->posts as $pid ) {
			$theirs  = wp_get_object_terms( $pid, array( 'category', 'post_tag' ), array( 'fields' => 'ids' ) );
			$overlap = 0;
			foreach ( (array) $theirs as $tid ) {
				if ( isset( $mine[ (int) $tid ] ) ) {
					++$overlap;
				}
			}
			if ( $overlap > 0 ) {
				$score[ (int) $pid ] = $overlap;
			}
		}
		arsort( $score );
		$out = array();
		foreach ( array_slice( array_keys( $score ), 0, 20 ) as $pid ) {
			// 已存在记录的 pair(含被拒)与正文已链接的目标,连清单都不进 —— 省 token 也防重复建议
			if ( AASEO_Links::pair_exists( $post->ID, $pid ) || AASEO_Links::already_links( $post->post_content, $pid ) ) {
				continue;
			}
			$out[ $pid ] = array(
				'title' => (string) get_the_title( $pid ),
				'desc'  => AASEO_Meta::get( $pid ),
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------- 提示词与解析

	private function prompt( $post, $text, array $targets ) {
		$lines   = array();
		$lines[] = 'Article (id ' . $post->ID . '): ' . $post->post_title;
		$lines[] = 'Article text: ' . mb_substr( $text, 0, 7000 );
		$lines[] = '';
		$lines[] = 'Candidate posts to link to:';
		foreach ( $targets as $pid => $t ) {
			$lines[] = 'id=' . $pid . ' | ' . $t['title'] . ( '' !== $t['desc'] ? ' — ' . $t['desc'] : '' );
		}
		$lines[] = '';
		$lines[] = 'Suggest internal links FROM this article TO candidates, as a JSON array:'
			. "\n" . '[{"target": <id>, "anchor": "<phrase copied verbatim from the article text>", "relevance": <1-5>, "why": "<one short sentence>"}]'
			. "\nRules:"
			. "\n- The anchor MUST be copied character-for-character from the article text above. Never invent or paraphrase it."
			. "\n- The anchor is a descriptive long-tail phrase a reader would want expanded (8-40 characters), never a bare keyword or the candidate's title."
			. "\n- relevance 5 = the candidate answers exactly what that sentence is about; 4 = clearly complementary; 3 or less = do not include."
			. "\n- At most 3 suggestions. One per target. An empty array [] is a perfectly good answer — most articles need no new links."
			. "\n- JSON array only.";
		return implode( "\n", $lines );
	}

	/**
	 * 解析并落库。每一条都过四道硬校验,不合格静默丢弃:
	 * 目标必须在预筛清单里(防幻觉 id)、相关性 ≥4、锚文本长度 8-60、
	 * 锚文本必须能在正文里原样找到安全落点(用与 approve 相同的算法预演一次)。
	 */
	private function store_suggestions( $post, $raw, array $targets ) {
		$raw = trim( (string) $raw );
		$raw = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $raw ); // 摘掉可能的代码栅栏
		$arr = json_decode( $raw, true );
		if ( ! is_array( $arr ) ) {
			return 0;
		}
		$added = 0;
		foreach ( array_slice( $arr, 0, 3 ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$target = isset( $s['target'] ) ? (int) $s['target'] : 0;
			$anchor = isset( $s['anchor'] ) ? trim( (string) $s['anchor'] ) : '';
			$rel    = isset( $s['relevance'] ) ? (int) $s['relevance'] : 0;
			$why    = isset( $s['why'] ) ? trim( (string) $s['why'] ) : '';

			if ( ! isset( $targets[ $target ] ) || $rel < 4 ) {
				continue;
			}
			$len = mb_strlen( $anchor );
			if ( $len < 8 || $len > 60 ) {
				continue;
			}
			// 预演落地:找不到安全落点的建议存了也没用,现在就丢
			if ( '' === AASEO_Links::apply_to_content( $post->post_content, $anchor, 'https://x/' ) ) {
				continue;
			}
			if ( AASEO_Links::add( $post->ID, $target, $anchor, min( 5, $rel ), mb_substr( $why, 0, 300 ) ) ) {
				++$added;
			}
		}
		return $added;
	}

	/** 正文转可读文本(锚文本将从这里摘取,所以保持与前台渲染一致的字词) */
	private function readable_text( $post ) {
		$text = (string) $post->post_content;
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text, true );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
