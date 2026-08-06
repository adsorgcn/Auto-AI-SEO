<?php
defined( 'ABSPATH' ) || exit;

/**
 * META 描述模块:先判定,只重写差的。
 *
 * 这是产品灵魂("把 匹配/模板/截断 换成 AI 理解")兑现得最直接的地方:
 * 搜索结果里的描述本该回答"这篇文章能给我什么",而绝大多数站点给的是
 * 正文开头 160 字的机械截断 —— 半句话、带样板文案、什么都没回答。
 *
 * 流程(每篇一次,判定结果落标记,内容不变就永不重复花钱):
 *   1. 现有描述(目标 postmeta → 手写摘要)非空 → 先过免费的机械检查,
 *      再交给 AI 判定:合格 → 不动,只打标记;不合格 → 重写。
 *   2. 什么都没有 → 搜索引擎现在看到的就是正文截断,按产品准则直接生成,
 *      不浪费一次"判定"调用。
 *   3. 判定与重写是两次独立调用,刻意不合并 —— 合并的提示词会诱导模型
 *      "既然都来了就重写一下",判定就形同虚设。克制才是优质产品思路。
 */
class AASEO_Job_Meta extends AASEO_Job {

	public function slug() {
		return 'meta';
	}

	public function label() {
		return __( 'Meta descriptions', 'ilang-auto-ai-seo' );
	}

	public function description() {
		return __( 'Judge each post’s search description; rewrite only the ones that fail — from an understanding of the whole article, not a truncation.', 'ilang-auto-ai-seo' );
	}

	public function model_kind() {
		return 'text';
	}

	/** 判定 + 部分重写的混合均值:整文入场约 2-4k token */
	public function estimate_tokens() {
		return array( 'in' => 3000, 'out' => 70 );
	}

	// ---------------------------------------------------------------- 候选

	/** 本模块处理的文章类型 */
	private function post_types() {
		$types = apply_filters( 'aaseo_meta_post_types', array( 'post', 'page' ) );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
	}

	/**
	 * 还没有判定标记的已发布文章。标记即排除条件 —— 判定过的(无论好坏)不再出现,
	 * 文章被编辑时 AASEO_Meta::invalidate() 清标记,自动重新入列。
	 */
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
				array( 'key' => AASEO_Meta::JUDGED_KEY, 'compare' => 'NOT EXISTS' ),
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
				array( 'key' => AASEO_Meta::JUDGED_KEY, 'compare' => 'NOT EXISTS' ),
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

		$title   = (string) $post->post_title;
		$content = $this->prepare_content( $post );

		// 现有描述:目标键优先,其次人工摘要(主题的回落链也是这个顺序)
		$existing    = AASEO_Meta::get( $post->ID );
		$from_meta   = '' !== $existing;
		$current     = '' !== $existing ? $existing : trim( wp_strip_all_tags( (string) $post->post_excerpt, true ) );

		/*
		 * 正文是空壳(如 "Thank You" 之类的表单落地页)时不硬写:
		 * 30 字的原料写不出合格描述,只会每轮生成→校验失败→白烧一次调用。
		 * 打上标记按"无内容"跳过;哪天页面充实了,save_post 清标记自然重新入列。
		 */
		if ( mb_strlen( $content ) < 60 && '' === $current ) {
			AASEO_Meta::mark_judged( $post->ID, 'stub' );
			AASEO_Jobs::note_skip( $this->slug(), 'no_content' );
			return 'skipped';
		}

		if ( '' !== $current ) {
			$verdict = $this->judge( $title, $current, $content );
			if ( is_wp_error( $verdict ) ) {
				return $verdict; // 判定调用失败 → 不打标记,留在候选集下次再试
			}
			if ( 'good' === $verdict ) {
				/*
				 * 合格:一个字都不改 —— 这是"先判定"存在的意义。
				 * 描述在摘要里而目标键为空时,把它抄进目标键(原样,人的文字),
				 * 让输出层拿得到;这不是改写,是搬运。
				 */
				if ( ! $from_meta ) {
					AASEO_Meta::save( $post->ID, $current, 'good' );
				} else {
					AASEO_Meta::mark_judged( $post->ID, 'good' );
				}
				AASEO_Jobs::note_skip( $this->slug(), 'desc_ok' );
				return 'skipped';
			}
		}

		// 走到这里:要么什么都没有(搜索引擎正在看正文截断),要么判定不合格 → 重写
		if ( '' === $content ) {
			// 有描述但被判差,而正文是空的 —— 没有原料,不硬写。
			// 也要打标记:不打就永远留在候选集,每一轮都白花一次判定调用。
			AASEO_Meta::mark_judged( $post->ID, 'stub' );
			AASEO_Jobs::note_skip( $this->slug(), 'no_content' );
			return 'skipped';
		}

		$res = AASEO_Client::text(
			$this->system_line(),
			$this->write_prompt( $title, $content ),
			array( 'job' => $this->slug(), 'max_tokens' => 220, 'kind' => 'text' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$desc = $this->validate( AASEO_Client::clean( $res['text'] ), $title );
		if ( '' === $desc ) {
			return new WP_Error( 'bad_output', __( 'Generated description failed validation; post left for the next run.', 'ilang-auto-ai-seo' ) );
		}

		AASEO_Meta::save( $post->ID, $desc, 'written' );
		return true;
	}

	// ---------------------------------------------------------------- 判定

	/**
	 * 先免费的机械检查,再花钱的 AI 判定。
	 *
	 * @return 'good'|'bad'|WP_Error
	 */
	private function judge( $title, $current, $content ) {
		/*
		 * 机械截断检测(免费):现有描述就是正文开头的复制品 —— 判定它不需要 AI,
		 * 归一化后做前缀比对即可。这正是本产品要替换的东西,直接判差。
		 */
		if ( '' !== $content && $this->is_truncation( $current, $content ) ) {
			return 'bad';
		}
		// 过短的"描述"起不到描述作用(如一两个词),也不必花钱判定
		if ( mb_strlen( $current ) < 20 ) {
			return 'bad';
		}

		$res = AASEO_Client::text(
			'You review search result descriptions (meta descriptions). Answer with a single word.',
			$this->judge_prompt( $title, $current, $content ),
			array( 'job' => $this->slug() . '-judge', 'max_tokens' => 12, 'kind' => 'text' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$word = strtolower( trim( (string) AASEO_Client::clean( $res['text'] ) ) );
		if ( 0 === strpos( $word, 'bad' ) ) {
			return 'bad';
		}
		/*
		 * 读不懂判定结果时按"合格"处理:宁可放过一条平庸的描述,
		 * 也不因为解析失败去改动别人的内容 —— 判定不清就不动手,与冲突守卫同一哲学。
		 */
		return 'good';
	}

	/** 归一化后判断 $desc 是否就是 $content 的开头截断 */
	private function is_truncation( $desc, $content ) {
		$d = $this->fold( $desc );
		$c = $this->fold( $content );
		if ( '' === $d || '' === $c ) {
			return false;
		}
		// 截断器常在结尾加省略号,fold 已剥标点;前 80% 命中开头即视为截断
		$probe = mb_substr( $d, 0, max( 10, (int) ( mb_strlen( $d ) * 0.8 ) ) );
		return 0 === mb_strpos( $c, $probe );
	}

	// ---------------------------------------------------------------- 提示词

	/** 描述长度上限:中日韩搜索结果约展示 80 字,拉丁文字约 155 字符 */
	private function max_chars() {
		$short  = strtolower( substr( get_locale(), 0, 2 ) );
		$narrow = in_array( $short, array( 'zh', 'ja', 'ko' ), true );
		return (int) AASEO_Options::job( $this->slug(), 'max_chars', $narrow ? 80 : 155 );
	}

	private function system_line() {
		return sprintf(
			'You write meta descriptions in %s. Output only the description itself — one line, no quotes, no explanation.',
			AASEO_Options::site_language()
		);
	}

	private function judge_prompt( $title, $current, $content ) {
		return 'Article title: ' . $title . "\n"
			. 'Current description: ' . $current . "\n"
			. 'Article (may be truncated): ' . mb_substr( $content, 0, 4000 ) . "\n\n"
			. 'Is the current description doing its job in search results?' . "\n"
			. 'It fails only if it: is cut off mid-sentence, copies the article opening verbatim,'
			. ' misrepresents the article, is in the wrong language, or tells the searcher nothing'
			. ' about what the article actually offers.' . "\n"
			. 'A plain but accurate summary passes. When in doubt, it passes.' . "\n"
			. 'Answer exactly one word: GOOD or BAD.';
	}

	private function write_prompt( $title, $content ) {
		$lang  = AASEO_Options::site_language();
		$site  = trim( (string) AASEO_Options::get( 'site_context' ) );
		$lines = array();
		if ( '' !== $site ) {
			$lines[] = 'Site: ' . $site;
		}
		$lines[] = 'Article title: ' . $title;
		$lines[] = 'Article: ' . mb_substr( $content, 0, 8000 );
		$lines[] = '';
		/*
		 * 语言要求压在最后一行 —— 与 alt 模块同一个实测教训:
		 * 提示词主体是英文时,模型会跟着提示词的语言走。
		 */
		$lines[] = 'Write the meta description for this article.'
			. "\nRules:"
			. "\n- Say what the reader concretely gets: the answer, the numbers, the method — not that the article \"discusses\" it."
			. "\n- Start with the substance. Never open with \"This article\", \"本文\", \"Learn about\" or any equivalent."
			. "\n- One line, at most " . (int) $this->max_chars() . ' characters. A complete sentence, never cut off.'
			. "\n- Do not copy the article opening, do not restate the title, no keyword stuffing."
			. "\n- Output the description only."
			. "\n- Write it in " . $lang . '. This is required even though these instructions are in English.';
		return implode( "\n", $lines );
	}

	// ---------------------------------------------------------------- 校验

	/** 模型输出挡在写库之前;不合格返回 '' */
	private function validate( $text, $title ) {
		$text = trim( (string) $text );
		if ( '' === $text || mb_strlen( $text ) < 20 ) {
			return '';
		}
		if ( mb_strlen( $text ) > (int) ( $this->max_chars() * 1.5 ) ) {
			return '';
		}
		if ( preg_match( '/<\|.*?\|>|\[\/?INST\]/u', $text ) ) {
			return '';
		}
		foreach ( array( 'meta description', 'Output only', 'Article title', 'Article:', '只输出' ) as $needle ) {
			$hit = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $needle ) : stripos( $text, $needle );
			if ( false !== $hit ) {
				return '';
			}
		}
		// 照抄标题 = 零信息增量
		if ( '' !== $title && $this->fold( $text ) === $this->fold( $title ) ) {
			return '';
		}
		// 语言跑偏(拉丁字母语言不做此判断,见 site_script 注释)
		$script = AASEO_Options::site_script();
		if ( '' !== $script && ! preg_match( '/' . $script . '/u', $text ) ) {
			return '';
		}
		return $text;
	}

	/** 归一化:小写 + 去空白与标点(与 alt 模块同款,用于抄袭比对) */
	private function fold( $s ) {
		$s = (string) $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return trim( (string) preg_replace( '/[\s\p{P}]+/u', '', $s ) );
	}

	/** 正文转纯文本:剥短代码与标签,折叠空白 */
	private function prepare_content( $post ) {
		$content = (string) $post->post_content;
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content, true );
		return trim( (string) preg_replace( '/\s+/u', ' ', $content ) );
	}
}
