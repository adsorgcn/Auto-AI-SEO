<?php
defined( 'ABSPATH' ) || exit;

/**
 * 图片 alt 生成模块。
 *
 * 只写 alt 一个字段(不碰 title/caption/description —— title 属性对 SEO 无益、
 * 对读屏软件是干扰)。写进 _wp_attachment_image_alt,即成为站点真数据:
 * 主题与其它插件都读它,本插件停用后 alt 也不会消失。
 *
 * 三档降级:看图 → 看不了就凭上下文推测 → 推测不出就**留空**。
 * 宁可空着,也不写一句同质化的废话 —— 把文章标题复制到七张图上,对图片搜索毫无价值,
 * 还可能被判为 alt 堆砌。
 */
class AASEO_Job_Alt extends AASEO_Job {

	public function slug() {
		return 'alt';
	}

	public function label() {
		return __( 'Image alt text', 'auto-ai-seo' );
	}

	public function description() {
		return __( 'Describe what each image actually shows, so every image gets its own alt text.', 'auto-ai-seo' );
	}

	public function model_kind() {
		return 'vision';
	}

	/** 实测值:600px 宽的截图约 200 入 / 15 出 */
	public function estimate_tokens() {
		return array( 'in' => 260, 'out' => 18 );
	}

	// ---------------------------------------------------------------- 候选

	/**
	 * 没有 alt 的图片附件。分页取,绝不一次性取全量 —— 大媒体库会撞查询长度上限。
	 */
	public function find_candidates( $limit, $offset ) {
		$q = new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'posts_per_page'         => (int) $limit,
			'offset'                 => (int) $offset,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			),
		) );
		return array_map( 'intval', $q->posts );
	}

	public function count_candidates() {
		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			),
		) );
		return (int) $q->found_posts;
	}

	// ---------------------------------------------------------------- 处理

	/**
	 * @return true|'skipped'|WP_Error
	 */
	public function handle_one( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		// 已经有人写过 alt 就不动(人工优先)
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( (string) $existing ) ) {
			return 'skipped';
		}

		$min_edge = (int) AASEO_Options::job( $this->slug(), 'min_long_edge', 200 );
		if ( AASEO_Image::is_decorative( $attachment_id, $min_edge ) ) {
			return 'skipped'; // 装饰图,按无障碍规范 alt 应留空
		}

		$title   = $this->parent_title( $attachment_id );
		$context = AASEO_Image::context( $attachment_id );

		// 第一档:看图
		$img = AASEO_Image::prepare( $attachment_id );

		/*
		 * 图片文件不存在(孤儿附件记录:数据库有行、磁盘无文件),同时又没有任何上下文可依据 ——
		 * 这不是"处理失败",是**输入本身是坏的**,再重试一百次也一样。
		 * 记为跳过并说明原因,不占用重试次数、不在界面上冒充失败。
		 */
		if ( is_wp_error( $img )
			&& in_array( $img->get_error_code(), array( 'no_file', 'no_url', 'cdn_status', 'cdn_empty' ), true )
			&& '' === $context && '' === $title ) {
			AASEO_Jobs::note_skip( $this->slug(), 'missing_file' );
			return 'skipped';
		}

		if ( ! is_wp_error( $img ) ) {
			$res = AASEO_Client::vision(
				$img['base64'],
				$img['mime'],
				$this->prompt( $title, $context ),
				array( 'job' => $this->slug(), 'max_tokens' => 120 )
			);
			if ( ! is_wp_error( $res ) ) {
				$alt = $this->validate( AASEO_Client::clean( $res['text'] ), $title );
				if ( $alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
					return true;
				}
			}
		}

		// 第二档:看不了图,但有上下文可推测 —— 用便宜的文本模型
		if ( '' !== $context || '' !== $title ) {
			$res = AASEO_Client::text(
				$this->system_line(),
				$this->text_only_prompt( $title, $context, $attachment_id ),
				array( 'job' => $this->slug() . '-text', 'max_tokens' => 120, 'kind' => 'text' )
			);
			if ( ! is_wp_error( $res ) ) {
				$alt = $this->validate( AASEO_Client::clean( $res['text'] ), $title );
				if ( $alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
					return true;
				}
			}
		}

		// 第三档:留空。不写烂 alt。
		return new WP_Error( 'no_alt', __( 'Could not describe this image; left empty rather than guessing.', 'auto-ai-seo' ) );
	}

	// ---------------------------------------------------------------- 提示词

	/** alt 的长度上限:读屏软件会整句朗读,过长反而妨碍无障碍使用 */
	private function max_chars() {
		return (int) AASEO_Options::job( $this->slug(), 'max_chars', 100 );
	}

	private function system_line() {
		return sprintf(
			/* translators: %s: language name */
			'You write image alt text in %s. Output only the description itself — no quotes, no explanation, no prefix.',
			AASEO_Options::site_language()
		);
	}

	private function prompt( $title, $context ) {
		$lang  = AASEO_Options::site_language();
		$site  = trim( (string) AASEO_Options::get( 'site_context' ) );
		$lines = array();

		if ( '' !== $site ) {
			$lines[] = 'Site: ' . $site;
		}
		if ( '' !== $title ) {
			$lines[] = 'Article title: ' . $title;
		}
		if ( '' !== $context ) {
			$lines[] = 'Surrounding text: ' . $context;
		}
		$lines[] = sprintf(
			'This image appears in that article. Write its alt attribute in %1$s.'
			. "\nRules:"
			. "\n- Name what is actually visible: the page, panel, form fields, values or objects on screen."
			. "\n- Start directly with the subject. Never open with \"This image shows\", \"A screenshot of\","
			. ' or any equivalent — assistive technology already announces that it is an image.'
			. "\n- One clause, at most %2\$d characters. Shorter is better."
			. "\n- Do not restate the article title, and do not explain why the image matters."
			. "\n- Output the description only.",
			$lang,
			(int) $this->max_chars()
		);
		return implode( "\n", $lines );
	}

	private function text_only_prompt( $title, $context, $attachment_id ) {
		$lang = AASEO_Options::site_language();
		$file = basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		$bits = array_filter( array( $title ? 'Article title: ' . $title : '', $context ? 'Surrounding text: ' . $context : '', $file ? 'File name: ' . $file : '' ) );
		return implode( "\n", $bits ) . "\n" . sprintf(
			'The image itself could not be read. From the context above, write one short, plausible alt attribute in %s '
			. 'describing what this illustration most likely shows. Stay general rather than inventing specific values. '
			. 'Output only the description.',
			$lang
		);
	}

	// ---------------------------------------------------------------- 校验

	/**
	 * 把模型输出挡在写库之前。任一条不过就返回 '' —— 交由调用方降级或留空。
	 *
	 * 这几条不是凭空设的,是实测中真见过的失败形态:
	 *   · 纯 OCR 类模型会把提示词原样复述回来
	 *   · 有模型输出带 <|begin_of_box|> 这类内部控制标记(clean() 已剥,这里兜底)
	 *   · 最危险的是照抄文章标题 —— 那等于回到模板填充,一篇文章七张图 alt 全一样
	 */
	private function validate( $text, $title ) {
		$text = $this->strip_filler( trim( (string) $text ) );
		if ( '' === $text ) {
			return '';
		}
		// 硬上限:超出 1.5 倍才判废(留一点余量,不为几个字丢掉一条好描述)
		if ( mb_strlen( $text ) > (int) ( $this->max_chars() * 1.5 ) ) {
			return '';
		}
		if ( preg_match( '/<\|.*?\|>|\[\/?INST\]/u', $text ) ) {
			return '';
		}
		// 复述提示词的迹象
		foreach ( array( 'alt attribute', 'Output only', 'Article title', 'Surrounding text', 'alt 属性', '只输出' ) as $needle ) {
			if ( false !== mb_stripos( $text, $needle ) ) {
				return '';
			}
		}
		// 照抄标题
		if ( '' !== $title ) {
			$a = $this->fold( $text );
			$b = $this->fold( $title );
			if ( '' !== $b && ( $a === $b || ( mb_strlen( $b ) > 8 && $a === mb_substr( $b, 0, mb_strlen( $a ) ) && mb_strlen( $a ) >= mb_strlen( $b ) * 0.9 ) ) ) {
				return '';
			}
		}
		return $text;
	}

	/**
	 * 剥掉"这是一张…的图片"这类开场白 —— 读屏软件已经会宣告"图片",再说一遍是纯冗余。
	 * 提示词已要求不要这么写,这里是兜底。
	 */
	private function strip_filler( $text ) {
		$patterns = array(
			// 中文
			'/^(该|这|此)?(张)?(图片?|图像|截图|示意图|配图)(显示|展示|中显示|里显示)了?(的是)?[：:，,]?\s*/u',
			'/^(这|那)是(一张)?[^，,。.]{0,12}?(的)?(截图|图片|图像|示意图)[：:，,]\s*/u',
			// 英文
			'/^(the\s+|this\s+|a\s+|an\s+)?(image|picture|photo|screenshot|figure)\s+(shows?|showing|of|depicts?|displays?)\s*/i',
			'/^(screenshot|image|photo)\s+of\s+/i',
		);
		foreach ( $patterns as $p ) {
			$stripped = preg_replace( $p, '', $text );
			if ( null !== $stripped && '' !== trim( $stripped ) ) {
				$text = trim( $stripped );
			}
		}
		return $text;
	}

	private function fold( $s ) {
		$s = mb_strtolower( (string) $s );
		return trim( preg_replace( '/[\s\p{P}]+/u', '', $s ) );
	}

	private function parent_title( $attachment_id ) {
		$parent = (int) get_post_field( 'post_parent', $attachment_id );
		return $parent ? (string) get_post_field( 'post_title', $parent ) : '';
	}
}
