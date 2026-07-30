<?php
defined( 'ABSPATH' ) || exit;

/**
 * 归档页(分类/标签)描述模块。
 *
 * 归档页是搜索引擎眼里的"话题枢纽",但几乎没有站点给它写描述 —— 要么空着,
 * 要么主题拿第一篇文章截断凑数。这里由 AI 读**该栏目实际收录的文章**
 * (标题 + 本插件刚生成的文章描述)概括出"这个栏目整体在讲什么",
 * 而不是套"XX栏目,提供XX资讯"的万能模板 —— 模板恰恰是本产品要替换的东西。
 *
 * 只处理**收了至少 min_posts 篇文章**的 term:一篇文章的标签页是薄页,
 * 描述写得再好也改变不了薄,不为它花钱。
 */
class AASEO_Job_Term extends AASEO_Job {

	public function slug() {
		return 'term';
	}

	public function label() {
		return __( 'Archive descriptions', 'auto-ai-seo' );
	}

	public function description() {
		return __( 'Describe what each category or tag actually collects, based on the posts inside it.', 'auto-ai-seo' );
	}

	public function model_kind() {
		return 'text';
	}

	/** 输入是标题+描述清单,比整文便宜得多 */
	public function estimate_tokens() {
		return array( 'in' => 1200, 'out' => 70 );
	}

	// ---------------------------------------------------------------- 候选

	private function taxonomies() {
		$tax = apply_filters( 'aaseo_term_taxonomies', array( 'category', 'post_tag' ) );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $tax ) ) );
	}

	/** 少于这个帖量的 term 不处理(薄页不值得花钱) */
	private function min_posts() {
		return max( 1, (int) AASEO_Options::job( $this->slug(), 'min_posts', 2 ) );
	}

	/**
	 * 游标分页走 terms_clauses(get_terms 没有"term_id 小于 N"的原生参数)。
	 * 判定标记复用 AASEO_Meta::JUDGED_KEY —— 语义相同:看过就不再看,直到被失效。
	 */
	public function find_candidates( $limit, $cursor ) {
		$cursor = (int) $cursor;
		$min    = $this->min_posts();
		$clause = function ( $clauses ) use ( $cursor, $min ) {
			global $wpdb;
			$clauses['where'] .= $wpdb->prepare( ' AND tt.count >= %d', $min );
			if ( $cursor > 0 ) {
				$clauses['where'] .= $wpdb->prepare( ' AND t.term_id < %d', $cursor );
			}
			return $clauses;
		};
		add_filter( 'terms_clauses', $clause );
		$ids = get_terms( array(
			'taxonomy'   => $this->taxonomies(),
			'fields'     => 'ids',
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'number'     => (int) $limit,
			'hide_empty' => true,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => AASEO_Meta::JUDGED_KEY, 'compare' => 'NOT EXISTS' ),
			),
		) );
		remove_filter( 'terms_clauses', $clause );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	public function count_candidates() {
		$min    = $this->min_posts();
		$clause = function ( $clauses ) use ( $min ) {
			global $wpdb;
			$clauses['where'] .= $wpdb->prepare( ' AND tt.count >= %d', $min );
			return $clauses;
		};
		add_filter( 'terms_clauses', $clause );
		// fields=count 让数据库做 COUNT(*) —— 后台任务表每次渲染都会调这个方法
		$n = get_terms( array(
			'taxonomy'   => $this->taxonomies(),
			'fields'     => 'count',
			'hide_empty' => true,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => AASEO_Meta::JUDGED_KEY, 'compare' => 'NOT EXISTS' ),
			),
		) );
		remove_filter( 'terms_clauses', $clause );
		return is_wp_error( $n ) ? 0 : (int) $n;
	}

	// ---------------------------------------------------------------- 处理

	/**
	 * @return true|'skipped'|WP_Error
	 */
	public function handle_one( $term_id ) {
		$term = get_term( (int) $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			AASEO_Jobs::note_skip( $this->slug(), 'gone' );
			return 'skipped';
		}
		if ( (int) $term->count < $this->min_posts() ) {
			// 入队后帖量掉下线(文章被删/改分类)—— 不写,也不打标记:回头帖量回来自然再见
			AASEO_Jobs::note_skip( $this->slug(), 'thin' );
			return 'skipped';
		}

		$posts = $this->term_posts( $term );
		if ( ! $posts ) {
			AASEO_Jobs::note_skip( $this->slug(), 'no_content' );
			return 'skipped';
		}

		// 现有描述:目标 term meta → term 原生描述字段
		$existing  = trim( (string) get_term_meta( $term->term_id, AASEO_Meta::term_meta_key(), true ) );
		$from_meta = '' !== $existing;
		$current   = '' !== $existing ? $existing : trim( wp_strip_all_tags( (string) $term->description, true ) );

		if ( '' !== $current ) {
			$verdict = $this->judge( $term, $current, $posts );
			if ( is_wp_error( $verdict ) ) {
				return $verdict;
			}
			if ( 'good' === $verdict ) {
				if ( ! $from_meta ) {
					update_term_meta( $term->term_id, AASEO_Meta::term_meta_key(), $current );
				}
				update_term_meta( $term->term_id, AASEO_Meta::JUDGED_KEY, 'good:' . time() );
				AASEO_Jobs::note_skip( $this->slug(), 'desc_ok' );
				return 'skipped';
			}
		}

		$res = AASEO_Client::text(
			$this->system_line(),
			$this->write_prompt( $term, $posts ),
			array( 'job' => $this->slug(), 'max_tokens' => 220, 'kind' => 'text' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$desc = $this->validate( AASEO_Client::clean( $res['text'] ), $term->name );
		if ( '' === $desc ) {
			return new WP_Error( 'bad_output', __( 'Generated description failed validation; term left for the next run.', 'auto-ai-seo' ) );
		}

		update_term_meta( $term->term_id, AASEO_Meta::term_meta_key(), $desc );
		update_term_meta( $term->term_id, AASEO_Meta::JUDGED_KEY, 'written:' . time() );
		return true;
	}

	/**
	 * 该 term 下最新的文章:标题 + 本插件生成的文章描述(现成的高质量摘要,免费复用)。
	 *
	 * @return array array( array( 'title' => ..., 'desc' => ... ), ... )
	 */
	private function term_posts( $term ) {
		$q = new WP_Query( array(
			'post_type'              => 'any',
			'post_status'            => 'publish',
			'posts_per_page'         => 20,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array( 'taxonomy' => $term->taxonomy, 'terms' => (int) $term->term_id ),
			),
		) );
		$out = array();
		foreach ( $q->posts as $pid ) {
			$out[] = array(
				'title' => (string) get_the_title( $pid ),
				'desc'  => AASEO_Meta::get( $pid ),
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------- 判定与提示词

	/** @return 'good'|'bad'|WP_Error */
	private function judge( $term, $current, $posts ) {
		if ( mb_strlen( $current ) < 20 ) {
			return 'bad'; // 一两个词的"描述"不必花钱判定
		}
		$res = AASEO_Client::text(
			'You review archive page descriptions. Answer with a single word.',
			'Archive: ' . $term->name . "\n"
			. 'Current description: ' . $current . "\n"
			. 'Posts in this archive: ' . $this->post_lines( $posts, 12 ) . "\n\n"
			. 'Does the description tell a searcher what this archive actually collects?'
			. ' It fails only if it: is generic boilerplate that could describe any site,'
			. ' misrepresents the posts, is in the wrong language, or is cut off.'
			. ' A plain but accurate summary passes. When in doubt, it passes.' . "\n"
			. 'Answer exactly one word: GOOD or BAD.',
			array( 'job' => $this->slug() . '-judge', 'max_tokens' => 12, 'kind' => 'text' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$word = strtolower( trim( (string) AASEO_Client::clean( $res['text'] ) ) );
		return 0 === strpos( $word, 'bad' ) ? 'bad' : 'good'; // 读不懂按合格,不因解析失败改内容
	}

	private function max_chars() {
		$short  = strtolower( substr( get_locale(), 0, 2 ) );
		$narrow = in_array( $short, array( 'zh', 'ja', 'ko' ), true );
		return (int) AASEO_Options::job( $this->slug(), 'max_chars', $narrow ? 80 : 155 );
	}

	private function system_line() {
		return sprintf(
			'You write archive page descriptions in %s. Output only the description itself — one line, no quotes, no explanation.',
			AASEO_Options::site_language()
		);
	}

	private function write_prompt( $term, $posts ) {
		$lang  = AASEO_Options::site_language();
		$site  = trim( (string) AASEO_Options::get( 'site_context' ) );
		$tax   = 'category' === $term->taxonomy ? 'category' : 'tag';
		$lines = array();
		if ( '' !== $site ) {
			$lines[] = 'Site: ' . $site;
		}
		$lines[] = ucfirst( $tax ) . ' name: ' . $term->name;
		$lines[] = 'Posts it collects (newest first):';
		$lines[] = $this->post_lines( $posts, 20 );
		$lines[] = '';
		$lines[] = 'Write the meta description for this archive page.'
			. "\nRules:"
			. "\n- Say what this collection actually covers, drawn from the posts above — the recurring topics, the concrete value."
			. "\n- Never write boilerplate that could fit any site (\"provides the latest news and tips about…\")."
			/*
			 * 实测教训:只禁"本栏目"开场,模型会集体退到下一个套话 ——
			 * 六个归档页全部"涵盖…"开头,等于换了个词的模板。把覆盖动词整类禁掉。
			 */
			. "\n- Start with the most distinctive concrete topic itself. Never open with \"This category\","
			. ' "本栏目", or a covering verb — 涵盖/包含/收录/汇集/"Covers"/"Includes" are all banned as the first word.'
			. "\n- One line, at most " . (int) $this->max_chars() . ' characters. A complete sentence, never cut off.'
			. "\n- Output the description only."
			. "\n- Write it in " . $lang . '. This is required even though these instructions are in English.';
		return implode( "\n", $lines );
	}

	private function post_lines( $posts, $limit ) {
		$lines = array();
		foreach ( array_slice( $posts, 0, (int) $limit ) as $p ) {
			$lines[] = '· ' . $p['title'] . ( '' !== $p['desc'] ? ' — ' . $p['desc'] : '' );
		}
		return implode( "\n", $lines );
	}

	// ---------------------------------------------------------------- 校验

	private function validate( $text, $term_name ) {
		$text = trim( (string) $text );
		/*
		 * 提示词已禁覆盖动词开场,这里是机械兜底(与 alt 的 strip_filler 同一经验:
		 * 提示词管不死的交给剥离)。只剥双字动词 —— 单字"含"可能是"含税"等词首,不碰。
		 * 剥掉后紧跟的主题列表在语法上自然成立:"涵盖 A、B及C教程" → "A、B及C教程"。
		 */
		$text = trim( (string) preg_replace( '/^(涵盖|包含|收录|汇集|提供|介绍)[\s:：]*/u', '', $text ) );
		if ( '' === $text || mb_strlen( $text ) < 20 ) {
			return '';
		}
		if ( mb_strlen( $text ) > (int) ( $this->max_chars() * 1.5 ) ) {
			return '';
		}
		if ( preg_match( '/<\|.*?\|>|\[\/?INST\]/u', $text ) ) {
			return '';
		}
		foreach ( array( 'meta description', 'Output only', 'Posts it collects', '只输出' ) as $needle ) {
			$hit = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $needle ) : stripos( $text, $needle );
			if ( false !== $hit ) {
				return '';
			}
		}
		if ( '' !== $term_name && $this->fold( $text ) === $this->fold( $term_name ) ) {
			return '';
		}
		$script = AASEO_Options::site_script();
		if ( '' !== $script && ! preg_match( '/' . $script . '/u', $text ) ) {
			return '';
		}
		return $text;
	}

	private function fold( $s ) {
		$s = (string) $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return trim( (string) preg_replace( '/[\s\p{P}]+/u', '', $s ) );
	}
}
