<?php
namespace liuguang\mvc;

use liuguang\mvc\data\DataMap;
use liuguang\mvc\event\EventDispatcher;
use liuguang\mvc\event\common\ApplicationErrorEvent;
use liuguang\mvc\http\UrlHelper;
use liuguang\mvc\http\RouteInfo;
use liuguang\mvc\event\common\RouteErrorEvent;
use liuguang\mvc\http\Controller;

/**
 * 应用主类
 *
 * @author liuguang
 *        
 */
class Application
{
    use EventDispatcher;

    /**
     * 应用实例
     *
     * @var Application
     */
    public static $app = null;

    /**
     * mvc源代码(src)目录
     *
     * @var string
     */
    public $mvcSourcePath;

    /**
     * 应用配置对象
     *
     * @var DataMap
     */
    public $config = null;

    /**
     * URL处理工具
     *
     * @var \liuguang\mvc\http\UrlHelper
     */
    public $url;

    /**
     *
     * @var \liuguang\mvc\http\RouteHandler
     */
    private $routeHandler;

    public function __construct()
    {
        $this->mvcSourcePath = __DIR__;
    }

    /**
     * 启动应用
     *
     * @return void
     */
    public function startApp(): void
    {
        if (self::$app !== null) {
            return;
        }
        self::$app = $this;
        if (! defined('APP_PATH')) {
            exit('APP_PATH is not defined !');
        }
        if (! defined('APP_CONFIG_PATH')) {
            define('APP_CONFIG_PATH', APP_PATH . '/./config');
        }
        // 加载框架配置文件
        $config = DataMap::loadFromPhpFile($this->mvcSourcePath . '/../config.inc.php');
        // 应用配置
        if ($this->config === null) {
            $appConfigFile = APP_CONFIG_PATH . '/./config.inc.php';
            if (is_file($appConfigFile)) {
                $appConfig = DataMap::loadFromPhpFile($appConfigFile);
                $config->mergeData($appConfig);
            }
        } else {
            $config->mergeData($this->config);
        }
        $this->config = $config;
        $this->loadErrorHandler();
        $this->loadRouteHandler();
        $this->loadRouteErrorHandler();
        //
        $url = '/';
        if (isset($_SERVER['REQUEST_URI'])) {
            $url = $_SERVER['REQUEST_URI'];
        }
        $routeInfo = $this->routeHandler->getRouteInfo($url);
        $this->invokeRoute($routeInfo);
    }

    /**
     * 加载错误处理器
     *
     * @return void
     */
    private function loadErrorHandler(): void
    {
        $errorHandlerClass = $this->config->getValue('ERROR_HANDLER');
        $errorHandler = new $errorHandlerClass();
        // 添加错误事件处理
        $this->addEventListener(ApplicationErrorEvent::class, [
            $errorHandler,
            'handleError'
        ]);
        // 当发生错误或者异常时,发送事件
        set_exception_handler(function ($exception) {
            $this->dispatchEvent(new ApplicationErrorEvent($exception));
        });
        set_error_handler(function ($errno, $errstr, $errfile, $errline, $errcontext) {
            $this->dispatchEvent(ApplicationErrorEvent::createCustom($errno, $errstr));
        });
    }

    /**
     * 加载路由
     *
     * @return void
     */
    private function loadRouteHandler(): void
    {
        $context = '';
        $pos = strrpos($_SERVER['SCRIPT_NAME'], '/');
        if ($pos > 0) {
            $context = substr($_SERVER['SCRIPT_NAME'], 0, $pos);
        }
        //
        $routeHandlerClass = $this->config->getValue('ROUTE_HANDLER');
        $this->routeHandler = new $routeHandlerClass();
        $this->url = new UrlHelper($this->routeHandler, $context);
    }

    /**
     * 加载路由错误处理接口
     *
     * @return void
     */
    private function loadRouteErrorHandler(): void
    {
        $errorHandlerClass = $this->config->getValue('ROUTE_ERROR_HANDLER');
        $errorHandler = new $errorHandlerClass();
        // 添加错误事件处理
        $this->addEventListener(RouteErrorEvent::class, [
            $errorHandler,
            'handleError'
        ]);
    }

    /**
     * 执行路由程序
     *
     * @param RouteInfo $routeInfo            
     * @return void
     */
    public function invokeRoute(RouteInfo $routeInfo): void
    {
        $controllerClass = $this->config->getValue('APP_NAMESPACE') . '\\controllers\\' . str_replace('.', '\\', $routeInfo->getControllerName());
        if (! class_exists($controllerClass)) {
            $event = RouteErrorEvent::createCustom(404, $routeInfo->getControllerName() . '/' . $routeInfo->getActionName() . '对应的控制器类' . $controllerClass . '不存在');
            $event->httpErrorCode = 404;
            $this->dispatchEvent($event);
            return;
        }
        $this->invokeController(new $controllerClass(), $routeInfo);
    }

    /**
     * 执行控制器
     *
     * @param Controller $controller
     *            控制器
     * @param RouteInfo $routeInfo
     *            路由信息
     * @return void
     */
    private function invokeController(Controller $controller, RouteInfo $routeInfo)
    {
        $actionResult = $controller->beforeAction($routeInfo);
        $actionResult->executeResult();
    }
}

