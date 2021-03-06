<?php
/**
 * User: 黄树斌
 * Date: 2019/1/14 0014
 * Time: 10:21
 */

namespace Nanov;

use Closure;
use Exception;
use Nanov\Master;
use Nanov\Worker;
use Nanov\Daemon;
use Nanov\Configure;
use Nanov\ProcessException;

class Manager
{
    /**
     * 主进程对象
     *
     * @var object Process
     */
    public $master = '';

    /**
     * 守护进程对象
     *
     * @var object Process
     */
    private $daemon = '';

    /**
     * 子进程池
     *
     * @var array [Process]
     */
    public $workers = [];

    /**
     * 信号池
     *
     * pool: array [Process]
     * signal:string reload/stop
     *
     * @var array
     */
    public $waitSignalProcessPool = [
        'pool'   => [],
        'signal' => '',
    ];

    /**
     * 注入子进程的闭包
     *
     * @var object Closure
     */
    private $workBusinessClosure = '';

    /**
     *最小进程数
     *
     * @var int
     */
    private $minNum = 1;

    /**
     * 启动的进程数
     *
     * @var int
     */
    public $startNum = 4;

    /**
     * 操作系统用户密码
     *
     * @var string
     */
    private $userPassword = '';

    /**
     * 管道目录
     *
     * @var string
     */
    private $pipeDir = '';

    /**
     * 配置项
     *
     * @var array
     */
    private $config = [];

    /**
     * 支持的信号
     *
     * @var array
     */
    private $signalSupport = [
        'int'       =>  SIGINT,   // 中断
        'stop'      =>  SIGUSR2,  // 优雅停止
        'reload'    =>  SIGUSR1,  // 重新加载
        'terminate' =>  SIGTERM,  // 强制中断
    ];

    /**
     * 挂起时间
     *
     * default 200000μs
     *
     * @var int
     */
    private static $hangupLoopMicrotime = 200000;


    public function __construct(Closure $closure)
    {
        // 获取环境配置
        $this->config = Configure::getConfig();

        // 欢迎界面
        $this->welcome();

        // 设置基本参数
        $this->configure();

        // 实例化master
        $this->master = new Master();

        // 实例化daemon
        $this->daemon = new Daemon();

        // 注册worker的闭包
        $this->workBusinessClosure = $closure;

        // fork子进程
        $this->execFork();

        // 注册信号处理方法
        $this->registerSigHandler();

        // 挂起master进程
        $this->hangup();
    }


    /**
     * welcome
     *
     * @return void
     */
    public function welcome()
    {
        $welcome = <<<WELCOME
\033[36m _   _                        
| \ | | __ _ _ __   _____   __
|  \| |/ _` | '_ \ / _ \ \ / /
| |\  | (_| | | | | (_) \ V / 
|_| \_|\__,_|_| |_|\___/ \_/  

The process manager start success!
\033[0m
WELCOME;
        echo $welcome;
    }

    /**
     * 配置系统
     *
     * @param array $config
     */
    public function configure()
    {
        // 配置时区
        date_default_timezone_set(isset($this->config['time']['timezone']) && !empty($this->config['time']['timezone']) ?
            $this->config['time']['timezone'] : 'Asia/shanghai');

        // 设置启动的进程数
        $this->startNum = isset($this->config['worker']['worker_num']) && !empty($this->config['worker']['worker_num']) ?
            (int)$this->config['worker']['worker_num'] : $this->startNum;

        // 设置master进程挂起时间
        self::$hangupLoopMicrotime = isset($this->config['master']['m_hangup_loop_microtime']) && !empty($this->config['master']['m_hangup_loop_microtime']) ?
            $this->config['master']['m_hangup_loop_microtime'] : self::$hangupLoopMicrotime;
    }

    /**
     * fork a worker process
     *
     * @return void
     */
    private function fork()
    {
        $pid = pcntl_fork();

        switch ($pid) {
            case -1:
                exit; break;

            case 0:
                // 子进程
                try {
                    // 实例化worker
                    $worker = new Worker();
                    $worker->setProcessName('nanov: worker process');
                    // 创建管道
                    $worker->pipeMake();
                    // 挂起
                    $worker->hangup($this->workBusinessClosure);
                }catch (Exception $e) {
                    $line = $e->getLine();
                    $file = $e->getFile();
                    $msg  = $e->getMessage();

                    ProcessException::error([
                        'msg' => [
                            'from'  => 'worker',
                            'extra' => "创建子进程 line: $line  file: $file  message: $msg"
                        ]
                    ]);
                }

                exit; break;

            default:
                try {
                    // 父进程生成子进程池
                    $worker = new Worker(['pid' => $pid, 'type' => 'master']);
                    $this->workers[$pid] = $worker;
                    $worker->setProcessName('nanov: master process');
                }catch (Exception $e) {
                    $line = $e->getLine();
                    $file = $e->getFile();
                    $msg  = $e->getMessage();

                    ProcessException::error([
                        'msg' => [
                            'from'  => 'master',
                            'extra' => "master进程创建进程池 line: $line  file: $file  message: $msg"
                        ]
                    ]);
                }

                break;
        }
    }

    /**
     * 生成子进程
     *
     * @param int $num
     */
    public function execFork($num = 0)
    {
        foreach (range(1, $num ?: $this->startNum) as $v) {
            $this->fork();
        }
    }

    /**
     * 安装信号处理器
     *
     * @return void
     */
    private function registerSigHandler()
    {
        foreach ($this->signalSupport as $signal) {
            pcntl_signal($signal, [$this, 'defineSigHandler']);
            // pcntl_signal($v, ['Naruto\Manager', 'defineSigHandler']);
        }
    }

    public function defineSigHandler($signal = 0)
    {
        switch ($signal) {
            // 重启信号
            case $this->signalSupport['reload']:
                // 将重启信号抛入信号池
                $this->waitSignalProcessPool = [
                    'pool'   => $this->workers,
                    'signal' => 'reload'
                ];
                // 将'reload'信号写入管道
                foreach ($this->workers as $worker) {
                    $worker->pipeWrite('reload');
                }

                break;

            // 优雅停止信号
            case $this->signalSupport['stop']:
                // 将重启信号抛入信号池
                $this->waitSignalProcessPool = [
                    'pool'   => $this->workers,
                    'signal' => 'stop'
                ];
                // 将'stop'信号写入管道
                foreach ($this->workers as $worker) {
                    $worker->pipeWrite('stop');
                }

                // 清除master的管道
                $this->master->clearPipe();
                // kill -9 master process
                exit; break;

            // SIGINT, SIGTERM, 立刻终止
            case $this->signalSupport['int']:
            case $this->signalSupport['terminate']:
                foreach ($this->workers as $worker) {
                    $worker->clearPipe();
                    // kill -9 all worker process
                    $res = posix_kill($worker->pid, SIGKILL);
                    ProcessException::info([
                        'msg' => [
                            'from'  => $this->master->type,
                            'extra' => "result: $res kill -9 {$worker->pid}"
                        ]
                    ]);
                }

                // 清除master的管道
                $this->master->clearPipe();
                // kill -9 master process
                exit; break;

            default:
                break;
        }
    }

    private function hangup()
    {
        while (true) {
            // 分发信号
            pcntl_signal_dispatch();

            // 检查子进程有没有被意外杀死
            $this->daemon->check($this);

            // 回收子进程
            foreach ($this->workers as $worker) {
                $res = pcntl_waitpid($worker->pid, $status, WNOHANG);
                if ($res > 0) {
                    // 从子进程池释放资源
                    unset($this->workers[$res]);

                    // 信号为重启信号时
                    if ($this->waitSignalProcessPool['signal'] === 'reload') {
                        if (array_key_exists($res, $this->waitSignalProcessPool['pool'])) {
                            unset($this->waitSignalProcessPool['pool'][$res]);
                            $this->fork();
                        }
                    }

                    // 信号为停止信号时
                    if ($this->waitSignalProcessPool['signal'] === 'stop') {
                        if (array_key_exists($res, $this->waitSignalProcessPool['pool'])) {
                            unset($this->waitSignalProcessPool['pool'][$res]);
                        }
                    }
                }
            }

            // 信号为停止信号时，停止master进程
            if ($this->waitSignalProcessPool['signal'] === 'stop') {
                // 检测所有子进程是否为停止状态
                if (empty($this->waitSignalProcessPool['pool'])) {
                    $this->master->stop();
                }
            }

            // 防止CPU被100%占有
            usleep(self::$hangupLoopMicrotime);
        }
    }
}