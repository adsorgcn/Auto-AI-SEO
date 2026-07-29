<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI 客户端:OpenAI 兼容接口 + 模型降级链 + 用量记账。
 *
 * 降级链的设计取自"视觉层永远是增强、绝不是硬依赖"这条纪律:
 * 链上任一模型可用就返回;全链失败返回 WP_Error,由调用方决定退到更便宜的路径还是放弃。
 * 绝不因为 AI 不可用而阻塞 WordPress 本身的行为。
 */
class AASEO_Client {

	/**
	 * 文本请求。
	 *
	 * @param string $system
	 * @param string $user
	 * @param array  $args  job(记账用) / max_tokens / temperature / timeout / models
	 * @return array|WP_Error  array('text'=>, 'model'=>, 'usage'=>, 'seconds'=>)
	 */
	public static function text( $system, $user, $args = array() ) {
		$messages = array();
		if ( '' !== trim( (string) $system ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $system );
		}
		$messages[] = array( 'role' => 'user', 'content' => $user );
		$args       = wp_parse_args( $args, array( 'kind' => 'text' ) );
		return self::request( $messages, $args );
	}

	/**
	 * 视觉请求。
	 *
	 * @param string $image_base64  已缩放好的图片(base64,不含 data: 前缀)
	 * @param string $mime
	 * @param string $prompt
	 */
	public static function vision( $image_base64, $mime, $prompt, $args = array() ) {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $image_base64 ),
					),
					array( 'type' => 'text', 'text' => $prompt ),
				),
			),
		);
		$args = wp_parse_args( $args, array( 'kind' => 'vision' ) );
		return self::request( $messages, $args );
	}

	/**
	 * 沿模型链依次尝试。每次失败都记一条(ok=0)用量,便于后台展示"降级到第几档"。
	 */
	private static function request( array $messages, array $args ) {
		$args = wp_parse_args( $args, array(
			'kind'        => 'text',
			'job'         => '',
			'max_tokens'  => 200,
			'temperature' => 0.3,
			'timeout'     => 0,     // 0 = 用探针建议值
			'models'      => null,  // null = 用配置里的链
		) );

		if ( ! AASEO_Options::is_configured() ) {
			return new WP_Error( 'no_key', __( 'No API key configured.', 'auto-ai-seo' ) );
		}

		$models  = $args['models'] ? (array) $args['models'] : AASEO_Options::models( $args['kind'] );
		$timeout = $args['timeout'] > 0 ? (int) $args['timeout'] : AASEO_Probe::timeout_for( $args['kind'] );
		if ( ! $models ) {
			return new WP_Error( 'no_models', __( 'No models configured.', 'auto-ai-seo' ) );
		}

		$last = null;
		foreach ( $models as $i => $model ) {
			$started = microtime( true );
			$res     = self::call( $model, $messages, $args, $timeout );
			$seconds = microtime( true ) - $started;

			if ( ! is_wp_error( $res ) ) {
				AASEO_Usage::record( $args['job'], $model, $res['usage']['prompt_tokens'],
					$res['usage']['completion_tokens'], $seconds, true );
				$res['seconds']  = round( $seconds, 2 );
				$res['fallback'] = $i;   // 0 = 首选命中
				return $res;
			}

			AASEO_Usage::record( $args['job'], $model, 0, 0, $seconds, false );
			AASEO_Probe::note_failure( $args['kind'], $model, $res->get_error_code() );
			$last = $res;
		}
		return $last ? $last : new WP_Error( 'chain_failed', __( 'All models failed.', 'auto-ai-seo' ) );
	}

	/** 单次调用 */
	private static function call( $model, array $messages, array $args, $timeout ) {
		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => (int) $args['max_tokens'],
			'temperature' => (float) $args['temperature'],
		);
		/**
		 * 混合思考模型(Qwen3 系)默认会思考,对这类"短输出、无需推理"的任务纯属浪费时间与
		 * token,故显式关闭。不认识该字段的服务商会忽略它。
		 */
		$body['enable_thinking'] = false;

		$response = wp_remote_post(
			trailingslashit( AASEO_Options::get( 'api_base' ) ) . 'v1/chat/completions',
			array(
				'timeout' => (int) $timeout,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . AASEO_Options::get( 'api_key' ),
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// 区分超时与其它网络错误 —— 超时是降级/降速的信号,认证失败不该重试
			$msg  = $response->get_error_message();
			$code = ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) )
				? 'timeout' : 'http_error';
			return new WP_Error( $code, $msg );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$msg = isset( $json['message'] ) ? $json['message']
				: ( isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $status );
			$code = 429 === $status ? 'rate_limited' : ( in_array( $status, array( 401, 403 ), true ) ? 'auth' : 'api_error' );
			return new WP_Error( $code, $msg, array( 'status' => $status ) );
		}

		$choice = isset( $json['choices'][0] ) ? $json['choices'][0] : array();
		$text   = isset( $choice['message']['content'] ) ? trim( (string) $choice['message']['content'] ) : '';
		if ( '' === $text ) {
			return new WP_Error( 'empty', __( 'Model returned no content.', 'auto-ai-seo' ) );
		}
		if ( isset( $choice['finish_reason'] ) && 'length' === $choice['finish_reason'] ) {
			return new WP_Error( 'truncated', __( 'Output truncated by max_tokens.', 'auto-ai-seo' ) );
		}

		$usage = isset( $json['usage'] ) ? $json['usage'] : array();
		return array(
			'text'  => $text,
			'model' => $model,
			'usage' => array(
				'prompt_tokens'     => isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0,
				'completion_tokens' => isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0,
			),
		);
	}

	/**
	 * 清洗模型输出:剥掉代码块、引号、内部控制标记,只取第一行。
	 * 实测有模型会输出 <|begin_of_box|> 这类内部标记,不清洗会直接写进页面。
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/<\|[^|]*\|>/u', '', $text );          // <|begin_of_box|> 等
		$text = preg_replace( '/^```[a-z]*\s*|\s*```$/mu', '', $text );
		$text = trim( strtok( $text, "\n" ) );
		return trim( $text, " \t\"'`「」《》" );
	}
}
