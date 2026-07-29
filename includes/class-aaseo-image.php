<?php
defined( 'ABSPATH' ) || exit;

/**
 * 把附件变成"能喂给视觉模型的字节"。
 *
 * 两条路:站点若有 Jetpack 的图片 CDN,直接让 CDN 端缩放(省掉本地图像处理与内存);
 * 否则用 GD 本地缩放。目标宽度取 600px —— 实测更小的尺寸会让含中文文字的截图识别质量
 * 明显下降,而更大只是徒增 token 与传输时间。
 *
 * 永不放大:原图比目标窄就原样送,放大不会凭空生出细节。
 */
class AASEO_Image {

	const TARGET_WIDTH = 600;
	const MAX_BYTES    = 3145728; // 3MB,超过就再压一档

	/**
	 * @param int $attachment_id
	 * @return array|WP_Error  array('base64'=>, 'mime'=>, 'bytes'=>, 'via'=>)
	 */
	public static function prepare( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$mime          = get_post_mime_type( $attachment_id );
		if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
			return new WP_Error( 'not_image', __( 'Not an image attachment.', 'auto-ai-seo' ) );
		}

		$raw = self::via_cdn( $attachment_id );
		$via = 'cdn';
		if ( is_wp_error( $raw ) ) {
			$raw = self::via_gd( $attachment_id );
			$via = 'gd';
		}
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		// CDN 可能返回不同格式(如 webp),以实际字节判断 mime 更可靠
		$sniffed = self::sniff_mime( $raw );
		return array(
			'base64' => base64_encode( $raw ),
			'mime'   => $sniffed ? $sniffed : $mime,
			'bytes'  => strlen( $raw ),
			'via'    => $via,
		);
	}

	/** 走 Jetpack 图片 CDN 缩放 */
	private static function via_cdn( $attachment_id ) {
		if ( ! function_exists( 'jetpack_photon_url' ) ) {
			return new WP_Error( 'no_cdn', 'Image CDN unavailable' );
		}
		$src = wp_get_attachment_url( $attachment_id );
		if ( ! $src ) {
			return new WP_Error( 'no_url', 'No attachment URL' );
		}
		$url      = jetpack_photon_url( $src, array( 'w' => self::TARGET_WIDTH ) );
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'cdn_status', 'CDN returned ' . wp_remote_retrieve_response_code( $response ) );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < 500 ) {
			return new WP_Error( 'cdn_empty', 'CDN returned too little data' );
		}
		return $body;
	}

	/** 本地 GD 缩放 */
	private static function via_gd( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'no_file', __( 'Image file not found locally.', 'auto-ai-seo' ) );
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > self::TARGET_WIDTH ) {
			$resized = $editor->resize( self::TARGET_WIDTH, null, false );
			if ( is_wp_error( $resized ) ) {
				return $resized;
			}
		}
		$editor->set_quality( 82 );

		$tmp = wp_tempnam( 'aaseo-img' );
		if ( ! $tmp ) {
			return new WP_Error( 'no_tmp', 'Cannot create temp file' );
		}
		$saved = $editor->save( $tmp );
		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $tmp );
			return $saved;
		}
		$path = isset( $saved['path'] ) ? $saved['path'] : $tmp;
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $path );
		if ( $path !== $tmp ) {
			wp_delete_file( $tmp );
		}
		if ( ! $raw ) {
			return new WP_Error( 'read_failed', 'Could not read resized image' );
		}
		return $raw;
	}

	/** 按魔术字节判断真实格式 */
	private static function sniff_mime( $raw ) {
		$head = substr( $raw, 0, 12 );
		if ( 0 === strpos( $head, "\x89PNG" ) ) {
			return 'image/png';
		}
		if ( 0 === strpos( $head, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( 0 === strpos( $head, 'GIF8' ) ) {
			return 'image/gif';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'image/webp';
		}
		return '';
	}

	/**
	 * 装饰性图片判定:长边过小的多是 logo / 图标 / 间隔图,
	 * 给它们生成描述是噪音 —— 无障碍规范也主张装饰图 alt 留空。
	 */
	public static function is_decorative( $attachment_id, $min_long_edge = 200 ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return false; // 尺寸未知,交给模型判断
		}
		return max( (int) $meta['width'], (int) $meta['height'] ) < (int) $min_long_edge;
	}

	/**
	 * 取该图在正文中的上下文段落。
	 *
	 * 有了上下文,视觉模型只需"大致认出画面",不必独自推断这是什么产品/什么步骤 ——
	 * 专有名词从上下文抄,识别小字不再是瓶颈,幻觉也更少。
	 */
	public static function context( $attachment_id, $chars = 200 ) {
		$parent = (int) get_post_field( 'post_parent', $attachment_id );
		if ( ! $parent ) {
			return '';
		}
		$content = (string) get_post_field( 'post_content', $parent );
		if ( '' === $content ) {
			return '';
		}
		$file = basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		if ( '' === $file ) {
			return '';
		}
		// 用文件名定位(经 CDN 改写后完整 URL 常常对不上,文件名更稳)
		$stem = preg_replace( '/\.[a-z0-9]+$/i', '', $file );
		$pos  = $stem ? mb_strpos( $content, $stem ) : false;
		if ( false === $pos ) {
			return '';
		}
		$start   = max( 0, $pos - $chars );
		$snippet = mb_substr( $content, $start, $chars * 2 );
		$snippet = wp_strip_all_tags( $snippet );
		$snippet = preg_replace( '/\s+/u', ' ', $snippet );
		return trim( $snippet );
	}
}
