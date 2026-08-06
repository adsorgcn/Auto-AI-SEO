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
	 * @param array  $args  额外支持 system:视觉模型同样吃 system 消息,
	 *                      把"用什么语言输出"放在这里比塞进用户提示词更难被忽略。
	 */
	public static function vision( $image_base64, $mime, $prompt, $args = array() ) {
		$messages = array();
		if ( ! empty( $args['system'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => (string) $args['system'] );
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => array(
				array(
					'type'      => 'image_url',
					'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $image_base64 ),
				),
				array( 'type' => 'text', 'text' => $prompt ),
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
			return new WP_Error( 'no_key', __( 'No API key configured.', 'ilang-auto-ai-seo' ) );
		}

		$models  = $args['models'] ? (array) $args['models'] : AASEO_Options::models( $args['kind'] );
		$timeout = $args['timeout'] > 0 ? (int) $args['timeout'] : AASEO_Probe::timeout_for( $args['kind'] );
		if ( ! $models ) {
			return new WP_Error( 'no_models', __( 'No models configured.', 'ilang-auto-ai-seo' ) );
		}

		$last = null;
		foreach ( $models as $i => $model ) {
			$started = microtime( true );
			$res     = self::call( $model, $messages, $args, $timeout );
			$seconds = microtime( true ) - $started;

			if ( ! is_wp_error( $res ) ) {
				AASEO_Usage::record( $args['job'], $model, $res['usage']['prompt_tokens'],
					$res['usage']['completion_tokens'], $seconds, true );
				// 每次成功都是一个标定样本 —— 超时与批量参数由此自我校准,不靠猜
				AASEO_Probe::record_sample( $args['kind'], $seconds,
					$res['usage']['prompt_tokens'], $res['usage']['completion_tokens'] );
				AASEO_Probe::note_success( $args['kind'] );
				$res['seconds']  = round( $seconds, 2 );
				$res['fallback'] = $i;   // 0 = 首选命中
				return $res;
			}

			// 失败也可能花了钱(空输出/被截断的调用照样计费)—— 有实际用量就照实记
			$err_data = $res->get_error_data();
			$spent    = isset( $err_data['usage'] ) && is_array( $err_data['usage'] ) ? $err_data['usage'] : array();
			AASEO_Usage::record(
				$args['job'],
				$model,
				isset( $spent['prompt_tokens'] ) ? (int) $spent['prompt_tokens'] : 0,
				isset( $spent['completion_tokens'] ) ? (int) $spent['completion_tokens'] : 0,
				$seconds,
				false
			);
			AASEO_Probe::note_failure( $args['kind'], $model, $res->get_error_code() );
			$last = $res;
		}
		return $last ? $last : new WP_Error( 'chain_failed', __( 'All models failed.', 'ilang-auto-ai-seo' ) );
	}

	const UNSUPPORTED_OPTION = 'aaseo_unsupported_params';

	/**
	 * 可选参数:能省时间/token,但不是每个模型都认。
	 * 服务商拒收时会自动摘掉并重试,且记住该模型不认它 —— 下次不再浪费一次调用。
	 */
	private static function optional_params() {
		return array(
			// 混合思考模型默认会思考,对"短输出、无需推理"的任务纯属浪费
			'enable_thinking' => false,
		);
	}

	/** 某模型已知不认的可选参数 */
	private static function unsupported_for( $model ) {
		$all = get_option( self::UNSUPPORTED_OPTION );
		$all = is_array( $all ) ? $all : array();
		return isset( $all[ $model ] ) ? (array) $all[ $model ] : array();
	}

	private static function remember_unsupported( $model, $param ) {
		$all              = get_option( self::UNSUPPORTED_OPTION );
		$all              = is_array( $all ) ? $all : array();
		$list             = isset( $all[ $model ] ) ? (array) $all[ $model ] : array();
		$list[]           = $param;
		$all[ $model ]    = array_values( array_unique( $list ) );
		update_option( self::UNSUPPORTED_OPTION, $all, false );
	}

	/** 单次调用。遇到"不支持某参数"会自动摘掉重试一次(evasive action)。 */
	private static function call( $model, array $messages, array $args, $timeout, $retry = true ) {
		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => (int) $args['max_tokens'],
			'temperature' => (float) $args['temperature'],
		);
		$known_bad = self::unsupported_for( $model );
		foreach ( self::optional_params() as $param => $value ) {
			if ( ! in_array( $param, $known_bad, true ) ) {
				$body[ $param ] = $value;
			}
		}

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

			/*
			 * 服务商拒收某个可选参数时,摘掉它重试一次,并记住这个模型不认它。
			 * 实测:视觉模型不接受 enable_thinking,而文本模型接受 —— 这种差异
			 * 无法预先穷举,只能让插件自己发现并适应,不该要求用户去配。
			 */
			if ( $retry ) {
				foreach ( array_keys( self::optional_params() ) as $param ) {
					if ( false !== stripos( $msg, $param ) ) {
						self::remember_unsupported( $model, $param );
						return self::call( $model, $messages, $args, $timeout, false );
					}
				}
			}

			$code = 429 === $status ? 'rate_limited' : ( in_array( $status, array( 401, 403 ), true ) ? 'auth' : 'api_error' );
			return new WP_Error( $code, $msg, array( 'status' => $status ) );
		}

		$choice = isset( $json['choices'][0] ) ? $json['choices'][0] : array();
		$text   = isset( $choice['message']['content'] ) ? trim( (string) $choice['message']['content'] ) : '';
		// 空输出/被截断也是**花了钱的**调用 —— usage 附在错误数据上,让上层照实记账,
		// 否则每日配额和成本面板会漏掉这部分真实消耗。
		$spent = array(
			'prompt_tokens'     => isset( $json['usage']['prompt_tokens'] ) ? (int) $json['usage']['prompt_tokens'] : 0,
			'completion_tokens' => isset( $json['usage']['completion_tokens'] ) ? (int) $json['usage']['completion_tokens'] : 0,
		);
		if ( '' === $text ) {
			return new WP_Error( 'empty', __( 'Model returned no content.', 'ilang-auto-ai-seo' ), array( 'usage' => $spent ) );
		}
		if ( isset( $choice['finish_reason'] ) && 'length' === $choice['finish_reason'] ) {
			return new WP_Error( 'truncated', __( 'Output truncated by max_tokens.', 'ilang-auto-ai-seo' ), array( 'usage' => $spent ) );
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
	 *
	 * 首尾引号必须用 /u 正则剥,**绝不能用 trim() 的字符清单**:
	 * trim() 的清单是按字节算的,「」《》 会贡献 {E3,80,8A,8B,8C,8D} 这些字节 ——
	 * 于是以"一"(E4 B8 80)结尾的中文丢掉末字节、以假名(E3 xx xx)开头的日文丢掉首字节,
	 * 产出非法 UTF-8。后果链:/u 正则全部返回 false → 语言判定误报 → 白烧一次修补调用
	 * → 修补结果再次被腐蚀 → 最终这张图永远拿不到 alt,每次尝试浪费 3-4 次付费调用。
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/<\|[^|]*\|>/u', '', $text );          // <|begin_of_box|> 等
		$text = preg_replace( '/^```[a-z]*\s*|\s*```$/mu', '', $text );
		$text = trim( strtok( $text, "\n" ) );
		$text = (string) preg_replace( '/^[\s"\'`「」『』《》]+|[\s"\'`「」『』《》]+$/u', '', $text );
		return trim( $text );
	}
}
