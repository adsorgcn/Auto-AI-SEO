<?php
defined( 'ABSPATH' ) || exit;

/**
 * 功能模块基类 —— 骨架的唯一扩展点。
 *
 * 一个 Job 代表一类批量工作(生成图片 alt、重写描述、生成归档页描述……)。
 * 子类只需回答三个问题:
 *   1. 哪些对象需要处理?          → find_candidates()
 *   2. 怎么处理其中一个?           → handle_one()
 *   3. 处理一条大约花多少 token?    → estimate_tokens()  (供跑前预估)
 *
 * 排队 / 断点续跑 / 失败重试 / 每日配额 / 用量记账 / 进度统计,一律由骨架提供,子类不管。
 */
abstract class AASEO_Job {

	/** 机器标识,用作 Action Scheduler 的 group 与 option 键的一部分。仅小写字母与连字符。 */
	abstract public function slug();

	/** 给人看的名字 */
	abstract public function label();

	/** 一句话说明这个模块做什么(显示在后台) */
	public function description() {
		return '';
	}

	/**
	 * 找出待处理对象的 ID 列表。
	 *
	 * 重要:必须支持分页(limit/offset),**不要一次返回上万个 ID** ——
	 * 大站点上一次性取全量 ID 会撞上 MySQL 查询长度上限,也吃内存
	 * (这是图片优化类插件公认的规模坑)。骨架会分页反复调用它来入队。
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return int[]
	 */
	abstract public function find_candidates( $limit, $offset );

	/** 待处理总数(用于进度与跑前预估) */
	abstract public function count_candidates();

	/**
	 * 处理单个对象。
	 *
	 * @param int $object_id
	 * @return true|WP_Error  返回 WP_Error 时骨架会记失败并按策略重试
	 */
	abstract public function handle_one( $object_id );

	/** 单条的经验 token 数,用于跑前预估。骨架有实测历史时会优先用实测值。 */
	public function estimate_tokens() {
		return array( 'in' => 300, 'out' => 20 );
	}

	/** 这个模块用视觉模型还是文本模型 */
	public function model_kind() {
		return 'text'; // 'text' | 'vision'
	}

	/** 处理失败最多重试几次 */
	public function max_attempts() {
		return 2;
	}

	/** 是否已具备运行条件(如需要 API key) */
	public function is_ready() {
		return AASEO_Options::is_configured();
	}

	// ---------------------------------------------------------------- 骨架提供

	/** Action Scheduler 的 hook 名 */
	final public function hook() {
		return 'aaseo_run_' . str_replace( '-', '_', $this->slug() );
	}

	/** Action Scheduler 的 group 名(便于在 工具→Scheduled Actions 里筛选与批量取消) */
	final public function group() {
		return 'aaseo-' . $this->slug();
	}

	/** 本模块的运行态(排队中/已完成/失败 计数与状态) */
	final public function state() {
		return AASEO_Jobs::state( $this->slug() );
	}
}
